<?php

namespace App\Services\GoogleAds\NegativeKeywords;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\NegativeKeywordList;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Support\Facades\DB;

/** Automatic exclusions require verified demand and affordable reach for the remaining portfolio. */
class AddNegativeKeywordService
{
    public function __invoke(int $campaignId, string $keyword): void
    {
        $this->applyBatch($campaignId, [$keyword]);
    }

    public function applyBatch(int $campaignId, array $keywords): array
    {
        $applied = [];
        $campaign = null;
        $proposals = [];
        $mutationAttempted = false;
        try {
            $campaign = Campaign::with('customer')->find($campaignId);
            if (! $campaign || ! $campaign->customer) {
                return ['applied' => [], 'requires_review' => true, 'message' => 'The destination campaign could not be verified.'];
            }
            foreach ($keywords as $keyword) {
                if (! is_string($keyword) || trim($keyword) === '') {
                    continue;
                }
                $keyword = trim($keyword);
                $proposals[mb_strtolower($keyword)] = ['action' => 'negative', 'text' => $keyword,
                    'match_type' => 'EXACT', 'scope' => 'campaign'];
            }
            $proposals = array_values($proposals);
            if ($proposals === []) {
                return ['applied' => [], 'requires_review' => false];
            }
            if (! $this->eligible($campaign)) {
                return $this->review($campaign, 'Automatic keyword exclusions are held because the campaign is inactive, under a trial or hold, or has unresolved reach verification.', $proposals);
            }
            $customerId = $campaign->customer->cleanGoogleCustomerId();
            $resource = $campaign->googleAdsResourceName();
            if (! is_string($resource) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/campaigns/(\d+)$~', $resource, $match)) {
                return $this->review($campaign, 'The Google campaign resource does not match the current customer account.', $proposals);
            }
            $assessment = app(GoogleSearchMutationGuard::class)->assess($campaign, $proposals);
            if (! ($assessment['ready'] ?? false)) {
                return $this->review($campaign, $assessment['message'] ?? 'The remaining keyword portfolio has no verified demand and affordable traffic.', $proposals, ['assessment' => $assessment]);
            }
            foreach ($proposals as $proposal) {
                $fresh = $campaign->fresh(['customer']);
                if (! $fresh || ! $this->eligible($fresh) || $fresh->customer?->cleanGoogleCustomerId() !== $customerId
                    || $fresh->googleAdsResourceName() !== $resource) {
                    return $this->review($campaign, 'Campaign ownership, pause or reach verification changed before the exclusion.', $proposals,
                        ['applied' => $applied], $applied !== []);
                }
                $alreadyPresent = array_filter($assessment['observed_snapshot']['negative_keywords'] ?? [],
                    fn ($negative) => ($negative['scope'] ?? 'campaign') === 'campaign'
                        && ($negative['match_type'] ?? null) === 'EXACT'
                        && mb_strtolower(trim($negative['text'] ?? '')) === mb_strtolower($proposal['text']));
                if ($alreadyPresent !== []) {
                    $this->recordLocally($fresh, $proposal['text']);

                    continue;
                }
                $mutationAttempted = true;
                $result = app(AddNegativeKeyword::class, ['customer' => $fresh->customer])(
                    $customerId, $resource, $proposal['text'], KeywordMatchType::EXACT);
                if (! is_string($result) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/campaignCriteria/'.$match[1].'\~\d+$~', $result)) {
                    $readback = app(GoogleSearchMutationGuard::class)->assess($fresh, []);

                    return $this->review($fresh, 'Google did not confirm the keyword exclusion. Remaining exclusions are held for review.', $proposals,
                        ['failed_change' => $proposal, 'applied' => $applied, 'actual_portfolio_assessment' => $readback], true);
                }
                $applied[] = ['keyword' => $proposal['text'], 'resource_name' => $result];
                $this->recordLocally($fresh, $proposal['text']);
            }

            return ['applied' => $applied, 'requires_review' => false];
        } catch (\Throwable $e) {
            report($e);
            if ($campaign) {
                return $this->review($campaign, 'Keyword exclusion or its actual outcome could not be verified. Remaining changes are held.', $proposals,
                    ['applied' => $applied, 'error' => $e->getMessage()], $mutationAttempted);
            }

            return ['applied' => $applied, 'requires_review' => true, 'message' => 'The campaign could not be verified.'];
        }
    }

    private function eligible(Campaign $campaign): bool
    {
        return $campaign->customer && ! $campaign->customer->is_sandbox && $campaign->customer->service_type === 'managed'
            && $campaign->status === CampaignStatus::Active && ! $campaign->hasPassedEndDate()
            && ! CampaignSpendGuardrails::automaticChangesSuspended($campaign)
            && empty($campaign->search_delivery_state['repair']['errors'])
            && empty($campaign->search_delivery_state['verification']['errors']);
    }

    private function review(Campaign $campaign, string $message, array $proposals, array $details = [], bool $partial = false): array
    {
        if ($partial) {
            DB::transaction(function () use ($campaign, $message, $proposals, $details): void {
                $current = Campaign::whereKey($campaign->id)->where('customer_id', $campaign->customer_id)->lockForUpdate()->first();
                if (! $current) {
                    return;
                }
                $state = $current->search_delivery_state ?? [];
                $state['status'] = 'needs_review';
                $state['mutation_allowed'] = false;
                $state['verification']['errors'][] = ['code' => 'negative_keyword_batch_unverified', 'message' => $message,
                    'checked_at' => now()->toIso8601String(), 'planned_changes' => $proposals, ...$details];
                $current->forceFill(['search_delivery_state' => $state])->save();
            });
        }
        AgentActivity::record('keyword', 'keyword_exclusions_need_review', $message, $campaign->customer_id, $campaign->id,
            ['planned_changes' => $proposals, ...$details], 'needs_review');

        return ['applied' => $details['applied'] ?? [], 'requires_review' => true, 'message' => $message];
    }

    private function recordLocally(Campaign $campaign, string $keyword): void
    {
        DB::transaction(function () use ($campaign, $keyword): void {
            $list = NegativeKeywordList::firstOrCreate(
                ['customer_id' => $campaign->customer_id, 'name' => 'Auto-excluded'],
                ['keywords' => [], 'applied_to_campaigns' => []]
            );
            $list = NegativeKeywordList::whereKey($list->id)->lockForUpdate()->firstOrFail();
            $keywords = $list->keywords ?? [];
            if (! in_array($keyword, $keywords, true)) {
                $keywords[] = $keyword;
            }
            $campaigns = $list->applied_to_campaigns ?? [];
            if (! in_array($campaign->id, $campaigns, true)) {
                $campaigns[] = $campaign->id;
            }
            $list->update(['keywords' => $keywords, 'applied_to_campaigns' => $campaigns]);
        });
    }
}
