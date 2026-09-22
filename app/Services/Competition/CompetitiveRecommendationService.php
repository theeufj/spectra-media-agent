<?php

namespace App\Services\Competition;

use App\Features\AutoOptimization;
use App\Jobs\ApplyCompetitiveRecommendation;
use App\Models\Campaign;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\Agents\Optimization\RecommendationScorer;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

class CompetitiveRecommendationService
{
    public function __construct(private CompetitivePlatformGateway $platform) {}

    public function record(Campaign $campaign, array $rec, bool $autoApply = false): Recommendation
    {
        $payload = $rec;
        foreach (['source', 'evidence', 'baseline', 'categorized'] as $key) {
            unset($payload[$key]);
        }
        $payload['type'] = RecommendationScorer::canonicalType($rec['type'] ?? '');
        $blocked = null;
        try {
            $payload = $this->prepare($campaign, $payload, $this->platform->state($campaign));
        } catch (\Throwable $e) {
            $blocked = $e->getMessage();
        }
        $action = array_intersect_key($payload, array_flip([
            'type', 'sub_type', 'suggested_value', 'keyword_resource', 'keywords', 'headlines',
            'descriptions', 'ad_group', 'final_urls', 'parameters',
        ]));
        ksort($action);
        // Ignore wording/confidence changes: the same action is not new work.
        $sourceIds = array_column($rec['evidence'] ?? [], 'id');
        sort($sourceIds);
        $fingerprint = hash('sha256', $campaign->id.':'.json_encode([$action, $sourceIds]));
        $requiresApproval = ! $autoApply || $blocked !== null || str_starts_with($payload['type'], 'COMPETITOR_')
            || ! Feature::for($campaign->customer)->active(AutoOptimization::class);
        $record = Recommendation::firstOrCreate(['fingerprint' => $fingerprint], [
            'campaign_id' => $campaign->id, 'source' => 'competitor', 'type' => $payload['type'],
            'platform' => $campaign->google_ads_campaign_id ? 'google' : null,
            'payload' => $payload, 'evidence' => $rec['evidence'] ?? [],
            'parameters' => $action, 'target_entity' => ['campaign_id' => $campaign->id],
            'rationale' => $rec['reasoning'] ?? $rec['description'] ?? 'Competitor-informed campaign adjustment',
            'status' => $requiresApproval ? 'pending' : 'approved', 'requires_approval' => $requiresApproval,
            'execution' => ['can_apply' => $blocked === null, 'blocked_reason' => $blocked],
        ]);
        // A transient read failure may have blocked an unapproved proposal.
        // Refresh that proposal when a later review can validate the same action.
        if (! $record->wasRecentlyCreated && $record->status === 'pending'
            && ! ($record->execution['can_apply'] ?? false) && $blocked === null) {
            $record->update(['payload' => $payload, 'parameters' => $action,
                'execution' => ['can_apply' => true, 'blocked_reason' => null]]);
        }
        if ($record->wasRecentlyCreated && ! $requiresApproval) {
            ApplyCompetitiveRecommendation::dispatch($record->id)->afterCommit();
        }

        return $record;
    }

    public function prepare(Campaign $campaign, array $payload, array $state): array
    {
        if (! ($state['enabled'] ?? false)) {
            throw new \RuntimeException('Campaign is not enabled on Google. No change will be applied.');
        }
        $type = $payload['type'];
        if (! in_array($type, ['BUDGET', 'NETWORK_SETTINGS', 'BIDDING', 'COMPETITOR_KEYWORD_TEST', 'COMPETITOR_AD_TEST'], true)) {
            throw new \RuntimeException('This action needs a specialist review; automatic execution is not available for this change.');
        }
        if ($type === 'BUDGET') {
            if ($campaign->facebook_ads_campaign_id || $campaign->microsoft_ads_campaign_id || $campaign->linkedin_campaign_id) {
                throw new \RuntimeException('A shared cross-platform budget needs a portfolio review.');
            }
            $strategies = $campaign->strategies->filter(fn ($strategy) => $strategy->daily_budget > 0);
            $splitBudget = $strategies->contains(fn ($strategy) => ! str_contains(strtolower($strategy->platform), 'google')
                || ! $strategy->reusableGoogleCampaignId())
                || $strategies->map(fn ($strategy) => $strategy->reusableGoogleCampaignId())->unique()->count() > 1;
            if ($splitBudget || ($state['shared_budget'] ?? false) || (float) ($campaign->billing_budget_multiplier ?? 1) < 1) {
                throw new \RuntimeException('A shared, split or billing-limited budget needs a portfolio review.');
            }
            $value = $payload['suggested_value'] ?? null;
            if (is_numeric($value)) {
                $payload['suggested_value'] = $value = round((float) $value, 2);
            }
            $current = (float) $campaign->daily_budget;
            $approved = (float) ($campaign->approved_daily_budget ?? $current);
            if (! is_numeric($value) || $value <= 0 || $value > $approved || $value < $current * 0.5 || $value > $current * 2) {
                throw new \RuntimeException('Budget change is outside the approved daily budget or adjustment range.');
            }
        } elseif ($type === 'BIDDING') {
            $keyword = collect($state['keywords'])->firstWhere('resource', $payload['keyword_resource'] ?? '');
            $bid = $payload['suggested_value'] ?? null;
            if (($payload['sub_type'] ?? '') !== 'keyword_cpc' || ! $keyword || ! is_numeric($bid) || $bid <= 0
                || $keyword['cpc_bid_micros'] <= 0 || $bid > $keyword['cpc_bid_micros'] * 1.25 || $bid < $keyword['cpc_bid_micros'] * 0.75) {
                throw new \RuntimeException('Bid changes require a verified keyword in this campaign and a move within 25% of its current bid.');
            }
        } else {
            if (! ($state['search'] ?? false)) {
                throw new \RuntimeException('Keyword, ad-variation and network tests require a Google Search campaign.');
            }
            if ($type === 'NETWORK_SETTINGS') {
                $payload['parameters'] = ['network_settings' => ['target_search_network' => false, 'target_content_network' => false]];
            } else {
                $ads = collect($state['ads'])->where('enabled', true);
                $ad = isset($payload['ad_group']) ? $ads->firstWhere('ad_group', $payload['ad_group']) : $ads->first();
                if (! $ad || empty($ad['final_urls'])) {
                    throw new \RuntimeException('No active Search ad group with a verified destination is available.');
                }
                $payload['ad_group'] = $ad['ad_group'];
                // Use the actual existing destination; never accept an AI-supplied URL.
                $payload['final_urls'] = $ad['final_urls'];
                if ($type === 'COMPETITOR_KEYWORD_TEST') {
                    $payload['keywords'] = $this->texts($payload['keywords'] ?? [], 1, 3, 80);
                } else {
                    $payload['headlines'] = $this->texts($payload['headlines'] ?? [], 3, 15, 30);
                    $payload['descriptions'] = $this->texts($payload['descriptions'] ?? [], 2, 4, 90);
                }
            }
        }

        return $payload;
    }

    private function texts(mixed $texts, int $minimum, int $maximum, int $length): array
    {
        if (! is_array($texts) || count($texts) < $minimum || count($texts) > $maximum) {
            throw new \RuntimeException("This test requires between {$minimum} and {$maximum} distinct text items.");
        }
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $length || preg_match('/[\x00-\x1F]/', $text)) {
                throw new \RuntimeException("Test text must be non-empty and no longer than {$length} characters.");
            }
        }
        $texts = array_values(array_unique(array_map('trim', $texts)));
        if (count($texts) < $minimum) {
            throw new \RuntimeException('The proposed test contains duplicate copy.');
        }

        return $texts;
    }

    public function apply(Recommendation $recommendation): bool
    {
        $lock = Cache::lock('competitive_change:'.$recommendation->campaign_id, 600);
        if (! $lock->get()) {
            return false;
        }
        try {
            $claimed = Recommendation::whereKey($recommendation->id)->where('source', 'competitor')
                ->where('status', 'approved')->update(['status' => 'applying']);
            if (! $claimed) {
                return true;
            }
            $campaign = $recommendation->campaign;
            if (! $campaign || ! Campaign::serving()->whereKey($campaign->id)->exists()
                || (! $recommendation->requires_approval && ! Feature::for($campaign->customer)->active(AutoOptimization::class))) {
                throw new \RuntimeException('Campaign must be serving with optimisation enabled before applying this change.');
            }
            foreach ($recommendation->evidence ?? [] as $source) {
                if (empty($source['observed_at']) || \Carbon\Carbon::parse($source['observed_at'])->lessThan(now()->subDays(30))) {
                    throw new \RuntimeException('This proposal uses outdated research. Review the latest competitor report before changing the campaign.');
                }
            }
            $state = $this->platform->state($campaign);
            $payload = $this->prepare($campaign, $recommendation->payload, $state);
            // Approval was for this exact target/destination. Do not silently retarget.
            foreach (['ad_group', 'final_urls'] as $field) {
                if (isset($recommendation->payload[$field]) && $payload[$field] !== $recommendation->payload[$field]) {
                    throw new \RuntimeException('The campaign destination changed after this action was proposed. Review a fresh suggestion.');
                }
            }
            $execution = array_merge($recommendation->execution ?? [], [
                'before' => $state, 'started_at' => now()->toIso8601String(),
                'baseline' => $this->metrics($campaign->id, now()->subDays(7)->toDateString(), now()->subDay()->toDateString()),
            ]);
            $execution['mutation_started_at'] = now()->toIso8601String();
            $recommendation->update(['execution' => $execution]);
            $result = $this->platform->apply($campaign, $payload, $state, $recommendation->requires_approval);
            $execution['result'] = $result;
            $recommendation->update([
                'execution' => $execution, 'status' => ($result['applied'] ?? false) ? 'applied'
                    : (($result['pending_verification'] ?? false) ? 'needs_verification' : 'failed'),
                'applied_at' => ($result['applied'] ?? false) ? now() : null,
            ]);
            if ($result['applied'] ?? false) {
                $this->verify($recommendation->fresh());
            }
        } catch (\Throwable $e) {
            report($e);
            $recommendation->refresh();
            $execution = $recommendation->execution ?? [];
            $execution['error'] = $e->getMessage();
            // A verification outage must not relabel an accepted change as failed.
            $recommendation->update(['execution' => $execution, 'status' => $recommendation->applied_at ? 'applied'
                : (isset($execution['mutation_started_at']) ? 'needs_verification' : 'failed')]);
        } finally {
            $lock->release();
        }

        return true;
    }

    public function verify(Recommendation $recommendation): void
    {
        $campaign = $recommendation->campaign;
        if (! $campaign || ! $recommendation->payload) {
            return;
        }
        $state = $this->platform->state($campaign);
        if ($this->platform->verified($recommendation->payload, $state)) {
            $execution = array_merge($recommendation->execution ?? [], ['after' => $state]);
            unset($execution['error']);
            $recommendation->update(['status' => 'verified', 'execution' => $execution, 'verified_at' => now(),
                'applied_at' => $recommendation->applied_at ?? now()]);
        }
    }

    public function measure(Recommendation $recommendation): void
    {
        if (! $recommendation->applied_at || $recommendation->applied_at->greaterThan(now()->subDays(8))) {
            return;
        }
        $start = $recommendation->applied_at->copy()->addDay()->startOfDay();
        $after = $this->metrics($recommendation->campaign_id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $before = $recommendation->execution['baseline'] ?? [];
        $enough = ($before['days'] ?? 0) >= 7 && $after['days'] >= 7 && ($before['clicks'] ?? 0) >= 30 && $after['clicks'] >= 30;
        $recommendation->update([
            'status' => 'measured', 'measured_at' => now(), 'outcome' => [
                'before' => $before, 'after' => $after, 'sufficient_data' => $enough,
                'summary' => $enough ? 'Seven-day campaign performance before and after the change. This comparison does not isolate its effect.'
                    : 'Not enough complete performance data to judge this change. No improvement is claimed.',
                'method' => 'observational_campaign_comparison',
            ],
        ]);
    }

    private function metrics(int $campaignId, string $from, string $to): array
    {
        $rows = GoogleAdsPerformanceData::where('campaign_id', $campaignId)->whereBetween('date', [$from, $to])->get();

        return [
            'from' => $from, 'to' => $to, 'days' => $rows->pluck('date')->unique()->count(),
            'impressions' => $rows->sum('impressions'), 'clicks' => $rows->sum('clicks'),
            'cost' => round($rows->sum('cost'), 2), 'conversions' => $rows->sum('conversions'),
            'conversion_value' => round($rows->sum('conversion_value'), 2),
        ];
    }
}
