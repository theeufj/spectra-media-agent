<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Features\AutoHealing;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\Campaign;
use App\Services\Agents\GoogleSearchCostProtection;
use App\Services\Agents\GoogleSearchReachRecovery;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Campaigns\CampaignSpendGuardrails;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/** Each campaign has an independent observation/repair job, never a scheduler API call. */
class CheckGoogleSearchDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return 'search-delivery:'.$this->campaignId;
    }

    public function handle(GoogleSearchReachRecovery $recovery): void
    {
        $lock = Cache::lock($this->uniqueId(), 650);
        if (! $lock->get()) {
            return;
        }
        $started = $this->startRun();
        try {
            $campaign = Campaign::withoutCustomerScope()->with('customer')->find($this->campaignId);
            if (! $campaign instanceof Campaign) {
                $this->finishRun($started, scope: "Campaign {$this->campaignId}", note: 'Campaign no longer exists.');

                return;
            }
            $previousReachStatus = $campaign->search_delivery_state['reach_status']
                ?? $campaign->search_delivery_state['status'] ?? null;
            // Cost containment runs before any reach/rank repair can call traffic healthy.
            $cost = app(GoogleSearchCostProtection::class)->check($campaign);
            $termReview = $this->reviewSearchTerms($campaign, $cost);
            $freshState = $campaign->fresh()->search_delivery_state ?? [];
            $effectiveReview = $termReview ?? $freshState['search_term_review'] ?? null;
            $pendingCost = ! empty($freshState['cost_protection']['mutation_started_at']) && empty($freshState['cost_protection']['verified_at']);
            $reachStatus = null;
            if (! empty($freshState['verification']['errors']) || $pendingCost || ($cost['status'] ?? null) === 'unverified'
                || in_array($effectiveReview['status'] ?? null, ['running', 'needs_review'], true)) {
                // Do not let a repair's own changed fingerprint erase an unknown
                // keyword/cost write, or launch a second repair over the first.
                $state = [...$freshState, 'status' => 'needs_review', 'mutation_allowed' => false,
                    'blocked_reason' => $pendingCost ? 'cost_reduction_outcome_unverified' : 'partial_repair_unresolved'];
            } else {
                $state = $recovery->check($campaign);
                $reachStatus = $state['status'];
                $state['reach_status'] = $reachStatus;
            }
            $state['cost_protection'] = $cost;
            if ($termReview !== null) {
                $state['search_term_review'] = $termReview;
            }
            if ((in_array($cost['status'] ?? null, ['needs_review', 'unverified'], true) || ($termReview['status'] ?? null) === 'needs_review')
                && in_array($state['status'] ?? null, ['delivering', 'recovered'], true)) {
                $state['status'] = 'needs_review';
                $state['blocked_reason'] = $cost['reason'] ?? 'search_cost_review_required';
            }
            $current = $campaign->fresh();
            if ($current) {
                $current->update(['search_delivery_state' => [...($current->search_delivery_state ?? []), ...$state]]);
            }
            $status = $state['status'];
            $rankActions = $state['ad_rank']['repair']['metadata']['actions_taken'] ?? 0;
            $this->finishRun($started,
                actions: (is_int($rankActions) && $rankActions > 0 ? $rankActions : 0)
                    + ($reachStatus === 'recovered' && $previousReachStatus !== 'recovered' ? 1 : 0)
                    + (int) ($cost['actions_taken'] ?? 0)
                    + (int) ($termReview['actions_taken'] ?? 0),
                errors: $status === 'unavailable' ? 1 : 0,
                warnings: in_array($status, ['low_reach', 'low_traffic', 'approval_required', 'needs_review'], true) ? 1 : 0,
                scope: "Campaign {$campaign->id}",
                note: $status,
                details: ['delivery_status' => $status, 'measurement' => $state['measurement'] ?? [],
                    'reach_status' => $state['reach_status'] ?? null,
                    'ad_rank' => $state['ad_rank'] ?? [],
                    'cost_protection' => $cost,
                    'search_term_review' => $termReview,
                    'blocked_reason' => $state['blocked_reason'] ?? null]);
        } finally {
            $lock->release();
        }
    }

    /** Sparse expensive queries are reviewed between daily sweeps, never on every hourly retry. */
    private function reviewSearchTerms(Campaign $campaign, array $cost): ?array
    {
        $campaign = $campaign->fresh(['customer']) ?? $campaign;
        if (($cost['limit_verified'] ?? null) !== true || ($cost['observed']['expensive_clicks'] ?? null) !== true
            || ! in_array($cost['status'] ?? null, ['verified', 'needs_review'], true)
            || $campaign->status !== CampaignStatus::Active || $campaign->customer?->service_type !== 'managed'
            || $campaign->customer->google_ads_link_status === 'revoked'
            || $campaign->customer->is_sandbox || $campaign->hasPassedEndDate()
            || CampaignSpendGuardrails::automaticChangesSuspended($campaign)
            || ! empty($campaign->search_delivery_state['verification']['errors'])
            || ! Feature::for($campaign->customer)->active(AutoHealing::class)) {
            return null;
        }
        $previous = $campaign->search_delivery_state['search_term_review'] ?? [];
        if (! empty($previous['checked_at']) && CarbonImmutable::parse($previous['checked_at'])->gt(
            now()->subHours(max(1, (int) config('optimization.search_cost_protection.mining_cooldown_hours', 24))))) {
            return null;
        }
        // Persist the claim before provider calls. A retry after an unknown keyword
        // mutation must not start a second mining batch immediately.
        $review = ['status' => 'running', 'checked_at' => now()->toIso8601String(), 'actions_taken' => 0];
        $campaign->update(['search_delivery_state' => [...($campaign->search_delivery_state ?? []), 'search_term_review' => $review]]);
        $result = app(SearchTermMiningAgent::class)->mine($campaign, true);
        $review['status'] = ! empty($result['requires_review']) || ! empty($result['errors']) ? 'needs_review' : 'checked';
        $review['actions_taken'] = count($result['keywords_added'] ?? []) + count($result['negatives_added'] ?? []);
        $review['terms_analyzed'] = $result['terms_analyzed'] ?? 0;
        $review['reason'] = $result['review_reason'] ?? $result['reason'] ?? null;
        $review['errors'] = $result['errors'] ?? [];

        return $review;
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
    }
}
