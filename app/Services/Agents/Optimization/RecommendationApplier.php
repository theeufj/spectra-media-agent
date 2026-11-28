<?php

namespace App\Services\Agents\Optimization;

use App\Features\AutoOptimization;
use App\Models\AgentActivity;
use App\Models\Audience;
use App\Models\Campaign;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Competition\CompetitivePlatformGateway;
use App\Services\FacebookAds\CustomAudienceService as FacebookCustomAudienceService;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\CreateRemarketingAudience;
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\CreateUserList;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetLocationBidAdjustment;
use App\Services\GoogleAds\CommonServices\UpdateCampaignNetworkSettings;
use App\Services\GoogleAds\CommonServices\UpdateKeywordBid;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use App\Services\MicrosoftAds\AdGroupService as MicrosoftAdGroupService;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Applies a single AI recommendation to the relevant Google Ads API endpoints.
 * Gated behind the auto_optimization feature flag per customer.
 */
class RecommendationApplier
{
    /**
     * How far a single auto-applied BUDGET recommendation may move the campaign's
     * daily budget. The model's number is not validated by anything upstream, and
     * a hallucinated 500 on a $50/day campaign would drain a week of prepaid ad
     * credit in an afternoon — a human reviews anything outside this band.
     */
    private const MIN_BUDGET_FACTOR = 0.5;

    private const MAX_BUDGET_FACTOR = 2.0;

    public function apply(Campaign $campaign, array $recommendation, bool $approvedByUser = false): array
    {
        $customer = $campaign->customer;

        if (! $approvedByUser && CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'Automatic campaign changes are suspended during a bounded restart trial or spending hold.', 'recommendation' => $recommendation];
        }

        if (! $approvedByUser && $customer && ! Feature::for($customer)->active(AutoOptimization::class)) {
            return [
                'applied' => false,
                'message' => 'Auto-optimization is disabled for this customer',
                'recommendation' => $recommendation,
            ];
        }

        // Canonicalise through the scorer's alias table. The scorer already
        // decided this was auto-appliable using those aliases; matching on the raw
        // string here meant BUDGET_ADJUSTMENT fell to the default arm and was
        // recorded 'failed'.
        $type = RecommendationScorer::canonicalType($recommendation['type'] ?? '');

        if ($type === '') {
            return ['applied' => false, 'message' => 'Recommendation type is missing', 'recommendation' => $recommendation];
        }

        $parent = $campaign->fresh(['customer', 'strategies']) ?? $campaign;
        $entries = \App\Services\Campaigns\CampaignResourceInventory::entries($parent);
        $target = $recommendation['target_entity'] ?? $recommendation['target'] ?? [];
        $target = is_array($target) ? $target : [];
        $multi = count($entries) + (int) (bool) $parent->microsoft_ads_campaign_id + (int) (bool) $parent->linkedin_campaign_id > 1;
        if ($multi && ($type === 'BUDGET' || empty($target['account_id']) || empty($target['external_campaign_id']))) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'Review the allocation and select the exact platform account and campaign.', 'recommendation' => $recommendation];
        }
        if (isset($target['external_campaign_id']) || isset($target['account_id'])) {
            $matches = array_filter($entries, fn ($entry) => (string) $entry['account_id'] === (string) ($target['account_id'] ?? '')
                && (string) $entry['resource_id'] === (string) ($target['external_campaign_id'] ?? '')
                && (! isset($target['strategy_id']) || $entry['strategy_id'] === (int) $target['strategy_id']));
            if (count($matches) !== 1) {
                return ['applied' => false, 'requires_review' => true, 'message' => 'The recommendation target no longer matches the managed account and campaign.', 'recommendation' => $recommendation];
            }
            $campaign = \App\Services\Campaigns\CampaignResourceInventory::view($parent, reset($matches));
        } elseif (count($entries) === 1 && ! $multi) {
            $campaign = \App\Services\Campaigns\CampaignResourceInventory::view($parent, $entries[0]);
        }

        if ($campaign->google_ads_campaign_id && ($type === 'BIDDING' || BiddingRecommendationGuard::keywordBidChange($recommendation))) {
            try {
                // An analysis can be stale by the time it is applied. Always
                // re-read strategy and criterion ownership before a CPC write.
                $state = app(CompetitivePlatformGateway::class)->state($campaign);
                $reason = BiddingRecommendationGuard::rejectionReason($recommendation, $state);
            } catch (\Throwable $e) {
                report($e);
                $reason = 'Current Google bidding configuration could not be verified; no bid was changed.';
            }
            if ($reason !== null) {
                return ['applied' => false, 'requires_review' => true, 'message' => $reason, 'recommendation' => $recommendation];
            }
            if (BiddingRecommendationGuard::keywordBidChange($recommendation)
                && ! CampaignSpendGuardrails::permitsKeywordBid($campaign, (int) $recommendation['suggested_value'])) {
                return ['applied' => false, 'requires_review' => true, 'message' => 'The keyword bid exceeds the campaign’s approved restart limits.', 'recommendation' => $recommendation];
            }
        }

        // Second line of defence behind the scorer's own gate: a recommendation
        // the model under-specified must not be attempted and filed as an error.
        $blockers = RecommendationScorer::autoApplyBlockers($recommendation);

        if ($blockers !== []) {
            return [
                'applied' => false,
                'requires_review' => true,
                'message' => 'Recommendation is missing required fields: '.implode(', ', $blockers),
                'recommendation' => $recommendation,
            ];
        }

        if (! $approvedByUser && in_array($type, ['TARGETING', 'AUDIENCE', 'NETWORK_SETTINGS', 'AD_EXTENSIONS'], true)) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'Approve the proposed audience, placement, geography or customer-facing extension before applying it.', 'recommendation' => $recommendation];
        }

        if ($campaign->google_ads_campaign_id && ($type === 'KEYWORDS' || BiddingRecommendationGuard::keywordBidChange($recommendation))) {
            $action = BiddingRecommendationGuard::keywordBidChange($recommendation) ? 'bid'
                : strtolower((string) ($recommendation['direction'] ?? $recommendation['action'] ?? ''));
            $change = ['action' => $action, 'resource_name' => $recommendation['criterion_resource_name']
                ?? $recommendation['keyword_resource'] ?? null];
            if ($action === 'bid') {
                $change['bid_micros'] = $recommendation['suggested_value'] ?? null;
            }
            $reach = app(GoogleSearchMutationGuard::class)->assess($campaign, [$change], $approvedByUser);
            if (! $reach['ready']) {
                return ['applied' => false, 'requires_review' => true, 'message' => $reach['message'],
                    'reach_evidence' => $reach, 'recommendation' => $recommendation];
            }
        }

        try {
            $result = match ($type) {
                'BUDGET' => $this->applyBudget($campaign, $recommendation),
                'KEYWORDS' => $this->applyKeyword($campaign, $recommendation),
                'NEGATIVE_KEYWORDS' => $this->applyNegativeKeywords($campaign, $recommendation, $approvedByUser),
                'BIDDING' => $this->applyBidding($campaign, $recommendation, $approvedByUser),
                'TARGETING' => $this->applyTargeting($campaign, $recommendation),
                'AD_EXTENSIONS' => $this->applyExtension($campaign, $recommendation),
                'SCHEDULE' => $this->applySchedule($campaign, $recommendation),
                'AUDIENCE' => $this->applyAudience($campaign, $recommendation),
                'NETWORK_SETTINGS' => $this->applyNetworkSettings($campaign, $recommendation),
                default => ['applied' => false, 'message' => "Auto-apply not yet supported for type: {$type}", 'recommendation' => $recommendation],
            };
            if (! ($result['applied'] ?? false) || ($result['scope'] ?? null) === 'local_plan') {
                return $result;
            }
            $receipt = $result['receipt'] ?? [];
            try {
                $confirmed = $receipt !== [] && app(RecommendationReadback::class, ['customer' => $campaign->customer])->confirmed($campaign, $type, $receipt);
            } catch (\Throwable $failure) {
                report($failure);
                $confirmed = false;
            }
            if (! $confirmed) {
                return ['applied' => false, 'submitted' => true, 'pending_verification' => true, 'receipt' => $receipt,
                    'message' => 'Submitted to the platform. The requested state has not yet been confirmed.', 'recommendation' => $recommendation];
            }
            if ($type === 'AUDIENCE' && isset($receipt['audience'])) {
                Audience::where('customer_id', $campaign->customer_id)->where('campaign_id', $campaign->id)
                    ->where('platform_resource_name', $receipt['audience'])->update(['status' => 'active']);
            }
            if ($type === 'BUDGET') {
                $parent->update(['daily_budget' => $receipt['budget_cents'] / 100]);
            }

            return $result + ['verified_at' => now()->toIso8601String()];
        } catch (\Throwable $e) {
            report($e);
            Log::error('RecommendationApplier: Failed to apply recommendation', [
                'campaign_id' => $campaign->id,
                'recommendation' => $recommendation,
                'error' => $e->getMessage(),
            ]);

            return ['applied' => false, 'pending_verification' => true, 'message' => 'Failed to confirm change: '.$e->getMessage(), 'recommendation' => $recommendation];
        }
    }

    /**
     * Change the campaign's daily budget.
     *
     * The local row is written only after the platform has accepted the mutate.
     * Persisting first and discarding the API's bool reported "Budget adjusted
     * from 50 to 80" for a change the platform never received — and every later
     * hourly push then anchored its multiplier on that phantom number.
     */
    private function applyBudget(Campaign $campaign, array $rec): array
    {
        $requested = $rec['suggested_value'] ?? null;

        if (! is_numeric($requested) || (float) $requested <= 0) {
            return ['applied' => false, 'message' => 'Invalid budget value', 'recommendation' => $rec];
        }

        $oldBudget = (float) ($campaign->daily_budget ?? 0);
        $newBudget = round((float) $requested, 2);

        if ($oldBudget > 0) {
            $newBudget = round(max(
                $oldBudget * self::MIN_BUDGET_FACTOR,
                min($oldBudget * self::MAX_BUDGET_FACTOR, $newBudget)
            ), 2);
        }

        $ceiling = (float) ($campaign->approved_daily_budget ?? $oldBudget);
        if ($newBudget > $ceiling) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'The requested budget exceeds the customer-approved daily budget.', 'recommendation' => $rec];
        }
        if (! CampaignSpendGuardrails::permitsBudget($campaign, $newBudget)) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'The requested budget exceeds the campaign’s approved restart limits.', 'recommendation' => $rec];
        }
        $deployed = $campaign->google_ads_campaign_id || $campaign->facebook_ads_campaign_id
            || $campaign->microsoft_ads_campaign_id || $campaign->linkedin_campaign_id;
        if (! $deployed) {
            $campaign->update(['daily_budget' => $newBudget]);

            return ['applied' => true, 'scope' => 'local_plan', 'message' => "Budget adjusted from {$oldBudget} to {$newBudget} in the campaign plan.", 'recommendation' => $rec];
        }
        if ($campaign->google_ads_campaign_id) {
            $remote = app(CompetitivePlatformGateway::class)->state($campaign);
            if (($remote['campaign_resource'] ?? '') !== $campaign->googleAdsResourceName() || ($remote['shared_budget'] ?? true) !== false) {
                return ['applied' => false, 'requires_review' => true, 'message' => 'Verify an unshared budget on this exact Google campaign before changing its allocation.', 'recommendation' => $rec];
            }
        }
        if (! app(\App\Services\Campaigns\CampaignBudgetService::class)->apply($campaign, $newBudget)) {
            return ['applied' => false, 'pending_verification' => true, 'receipt' => ['budget_cents' => (int) round($newBudget * 100)], 'message' => 'The platform did not confirm the budget update.', 'recommendation' => $rec];
        }

        $message = "Budget adjusted from {$oldBudget} to {$newBudget}";

        if ((float) $requested !== $newBudget) {
            $message .= " (requested {$requested}, clamped to the allowed range)";
        }

        return ['applied' => true, 'receipt' => ['budget_cents' => (int) round($newBudget * 100)], 'message' => $message, 'recommendation' => $rec];
    }

    /**
     * Disable out-of-network reach on a Google Search campaign — Search Partners and/or
     * Display Network expansion — which typically drains budget on low-intent placements.
     * The default action turns both off; an explicit parameters.network_settings map can
     * override. Google-only; the service itself refuses non-Search campaigns.
     */
    private function applyNetworkSettings(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (! $campaign->google_ads_campaign_id || ! $customer) {
            return ['applied' => false, 'message' => 'Network settings changes apply to Google Ads campaigns only', 'recommendation' => $rec];
        }

        // Default: disable Search Partners + Display expansion. An explicit map wins,
        // but we never allow disabling Google Search itself.
        $settings = $rec['parameters']['network_settings']
            ?? $rec['params']['network_settings']
            ?? ['target_search_network' => false, 'target_content_network' => false];
        unset($settings['target_google_search']);

        if (empty($settings)) {
            return ['applied' => false, 'message' => 'No network settings to change', 'recommendation' => $rec];
        }

        $ok = (new UpdateCampaignNetworkSettings($customer))(
            $customer->cleanGoogleCustomerId(),
            $campaign->googleAdsResourceName(),
            $settings
        );

        if (! $ok) {
            return ['applied' => false, 'message' => 'Failed to update network settings (non-Search campaign or API error)', 'recommendation' => $rec];
        }

        $disabled = [];
        if (($settings['target_search_network'] ?? null) === false) {
            $disabled[] = 'Search Partners';
        }
        if (($settings['target_content_network'] ?? null) === false) {
            $disabled[] = 'Display Network';
        }
        $message = 'Disabled '.(empty($disabled) ? 'out-of-network placements' : implode(' + ', $disabled));

        AgentActivity::record(
            'optimization',
            'network_settings_submitted',
            "Submitted network settings for \"{$campaign->name}\"",
            $campaign->customer_id,
            $campaign->id,
            ['network_settings' => $settings]
        );

        return ['applied' => true, 'receipt' => ['network' => $settings], 'message' => $message, 'recommendation' => $rec];
    }

    /**
     * Add campaign negative keywords (e.g. wasteful terms the model flagged) so budget
     * stops serving on irrelevant queries. Google-only; capped per run so one bad
     * response can't build a runaway block-list. Defaults to EXACT match to avoid
     * over-blocking legitimate traffic.
     */
    private function applyNegativeKeywords(Campaign $campaign, array $rec, bool $approvedByUser = false): array
    {
        $customer = $campaign->customer;

        if (! $campaign->google_ads_campaign_id || ! $customer) {
            return ['applied' => false, 'message' => 'Negative keywords apply to Google Ads campaigns only', 'recommendation' => $rec];
        }

        // Accept the various shapes the model may emit.
        $raw = $rec['keywords']
            ?? $rec['negative_keywords']
            ?? $rec['parameters']['keywords']
            ?? $rec['params']['keywords']
            ?? [];

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        $keywords = collect((array) $raw)
            ->map(fn ($k) => is_array($k) ? ($k['text'] ?? null) : $k)
            ->filter(fn ($k) => is_string($k) && trim($k) !== '')
            ->map(fn ($k) => trim($k))
            ->unique()
            ->take(20) // cap per run — guardrail against a hallucinated block-list
            ->values()
            ->all();

        if (empty($keywords)) {
            return ['applied' => false, 'message' => 'No negative keywords supplied', 'recommendation' => $rec];
        }

        $matchType = match (strtoupper($rec['match_type'] ?? 'EXACT')) {
            'PHRASE' => KeywordMatchType::PHRASE,
            'BROAD' => KeywordMatchType::BROAD,
            default => KeywordMatchType::EXACT,
        };

        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        $changes = array_map(fn ($text) => ['action' => 'negative', 'text' => $text,
            'match_type' => KeywordMatchType::name($matchType), 'scope' => 'campaign'], $keywords);
        $reach = app(GoogleSearchMutationGuard::class)->assess($campaign, $changes, $approvedByUser);
        if (! $reach['ready']) {
            return ['applied' => false, 'requires_review' => true, 'message' => $reach['message'],
                'reach_evidence' => $reach, 'recommendation' => $rec];
        }
        $service = new AddNegativeKeyword($customer);

        $added = [];
        foreach ($keywords as $kw) {
            if ($service($customerId, $resource, $kw, $matchType)) {
                $added[] = $kw;
            }
        }

        if (empty($added)) {
            return ['applied' => false, 'message' => 'Failed to add negative keywords', 'recommendation' => $rec];
        }

        $n = count($added);
        $message = "Added {$n} negative keyword".($n === 1 ? '' : 's')
            .': '.implode(', ', array_slice($added, 0, 5)).($n > 5 ? '…' : '');

        AgentActivity::record(
            'optimization',
            'negative_keywords_submitted',
            "Submitted {$n} negative keyword".($n === 1 ? '' : 's')." to \"{$campaign->name}\"",
            $campaign->customer_id,
            $campaign->id,
            ['keywords' => $added]
        );

        return ['applied' => true, 'receipt' => ['keywords' => $keywords, 'match_type' => KeywordMatchType::name($matchType)], 'message' => $message, 'recommendation' => $rec];
    }

    private function applyKeyword(Campaign $campaign, array $rec): array
    {
        // Microsoft: only keyword addition is supported via AdGroupService
        if ($campaign->microsoft_ads_campaign_id && ! $campaign->google_ads_campaign_id) {
            return $this->applyMicrosoftKeyword($campaign, $rec);
        }

        $action = strtolower((string) ($rec['direction'] ?? $rec['action'] ?? ''));
        $resource = $rec['criterion_resource_name'] ?? null;
        $customer = $campaign->customer;

        if (! $customer || ! $resource) {
            return ['applied' => false, 'message' => 'Missing customer or criterion resource name', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        if ($action === 'enable' && ! CampaignSpendGuardrails::canEnable($campaign)) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'Campaign spending is on hold; a keyword cannot be enabled.', 'recommendation' => $rec];
        }

        $result = match ($action) {
            'increase', 'decrease' => $this->adjustBid($customer, $customerId, $resource, $rec),
            'pause' => $this->pauseKeyword($customer, $customerId, $resource),
            'enable' => $this->enableKeyword($customer, $customerId, $resource),
            'remove' => $this->removeKeyword($customer, $customerId, $resource),
            default => ['applied' => false, 'message' => "Unknown keyword action: {$action}"],
        };

        $result['recommendation'] = $rec;

        return $result;
    }

    private function adjustBid($customer, string $customerId, string $resource, array $rec): array
    {
        $bid = $rec['suggested_value'] ?? null;
        if (! $bid) {
            return ['applied' => false, 'message' => 'No suggested bid value'];
        }
        $ok = app(UpdateKeywordBid::class, ['customer' => $customer])($customerId, $resource, (int) $bid);

        return ['applied' => $ok, 'receipt' => ['criterion' => $resource, 'cpc' => (int) $bid], 'message' => $ok ? "Bid adjusted to {$bid} micros" : 'Failed to adjust bid'];
    }

    private function pauseKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new UpdateKeywordStatus($customer))->pause($customerId, $resource);

        return ['applied' => $ok, 'receipt' => ['criterion' => $resource, 'status' => 'PAUSED'], 'message' => $ok ? 'Keyword paused' : 'Failed to pause keyword'];
    }

    private function enableKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new UpdateKeywordStatus($customer))->enable($customerId, $resource);

        return ['applied' => $ok, 'receipt' => ['criterion' => $resource, 'status' => 'ENABLED'], 'message' => $ok ? 'Keyword enabled' : 'Failed to enable keyword'];
    }

    private function removeKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new RemoveKeyword($customer))($customerId, $resource);

        return ['applied' => $ok, 'receipt' => ['criterion' => $resource, 'status' => 'REMOVED'], 'message' => $ok ? 'Keyword removed' : 'Failed to remove keyword'];
    }

    private function applyBidding(Campaign $campaign, array $rec, bool $approvedByUser = false): array
    {
        $customer = $campaign->customer;
        $subType = $rec['sub_type'] ?? null;
        // The scorer writes confidence_score; reading 'confidence' meant this
        // gate could never pass and every bidding auto-apply was recorded failed.
        $confidence = $rec['confidence_score'] ?? $rec['confidence'] ?? 0;

        $threshold = app(RecommendationScorer::class)->autoApplyThreshold('BIDDING');
        if ($subType === 'keyword_cpc' && ($approvedByUser || $confidence >= $threshold) && $campaign->google_ads_campaign_id && $customer) {
            $kwResource = $rec['keyword_resource'] ?? null;
            $newBidMicros = $rec['suggested_value'] ?? null;

            if ($kwResource && $newBidMicros) {
                $ok = app(UpdateKeywordBid::class, ['customer' => $customer])(
                    $customer->cleanGoogleCustomerId(),
                    $kwResource,
                    (int) $newBidMicros
                );

                if ($ok) {
                    AgentActivity::record(
                        'optimization', 'bid_adjustment_submitted',
                        'Submitted keyword bid of $'.round($newBidMicros / 1_000_000, 2),
                        $customer->id, $campaign->id,
                        ['keyword' => $kwResource, 'new_bid_micros' => $newBidMicros, 'confidence' => $confidence]
                    );

                    return ['applied' => true, 'receipt' => ['criterion' => $kwResource, 'cpc' => (int) $newBidMicros], 'message' => 'Keyword bid adjusted to $'.round($newBidMicros / 1_000_000, 2), 'recommendation' => $rec];
                }
            }
        }

        return ['applied' => false, 'requires_review' => true, 'message' => 'Bidding changes require manual review or the configured confidence threshold.', 'recommendation' => $rec];
    }

    private function applyTargeting(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (! $customer || ! $campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Device/location bid adjustments are Google Ads only — not applicable for this platform', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        $subType = $rec['sub_type'] ?? null;

        $result = match ($subType) {
            'device' => $this->deviceBid($customer, $customerId, $resource, $rec),
            'location' => $this->locationBid($customer, $customerId, $resource, $rec),
            default => ['applied' => false, 'message' => "Unknown targeting sub_type: {$subType}"],
        };

        $result['recommendation'] = $rec;

        return $result;
    }

    private function deviceBid($customer, string $customerId, string $resource, array $rec): array
    {
        $device = $rec['device_type'] ?? null;
        $modifier = $rec['suggested_value'] ?? null;
        if (! $device || $modifier === null) {
            return ['applied' => false, 'message' => 'Missing device type or bid modifier'];
        }
        $result = (new SetDeviceBidAdjustment($customer))($customerId, $resource, (int) $device, (float) $modifier);

        return ['applied' => $result !== null, 'receipt' => ['criterion' => $result, 'modifier' => (float) $modifier], 'message' => $result ? "Device bid set to {$modifier}x" : 'Failed to set device bid'];
    }

    private function locationBid($customer, string $customerId, string $resource, array $rec): array
    {
        $geo = $rec['geo_target_constant'] ?? null;
        $modifier = $rec['suggested_value'] ?? null;
        if (! $geo || $modifier === null) {
            return ['applied' => false, 'message' => 'Missing geo target or bid modifier'];
        }
        $result = (new SetLocationBidAdjustment($customer))($customerId, $resource, $geo, (float) $modifier);

        return ['applied' => $result !== null, 'receipt' => ['criterion' => $result, 'modifier' => (float) $modifier], 'message' => $result ? "Location bid set to {$modifier}x for {$geo}" : 'Failed to set location bid'];
    }

    private function applySchedule(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (! $customer || ! $campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Ad schedule adjustments are Google Ads only — not applicable for this platform', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        $service = app(SetAdSchedule::class, ['customer' => $customer]);
        $criterion = $rec['criterion_resource_name'] ?? null;
        $modifier = $rec['suggested_value'] ?? null;
        if (! $criterion || ! is_numeric($modifier) || ! isset($service->enabledIntervals($customerId, $resource)[$criterion])) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'The current delivery schedule is preserved. Choose an existing eligible interval for a bid adjustment.', 'recommendation' => $rec];
        }
        $ok = $service->updateExistingModifier($customerId, $resource, $criterion, (float) $modifier);

        return ['applied' => $ok, 'receipt' => ['criterion' => $criterion, 'modifier' => (float) $modifier],
            'message' => $ok ? 'Verified bid adjustment on the existing interval.' : 'The schedule adjustment could not be verified.', 'recommendation' => $rec];
    }

    private function applyExtension(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (! $customer || ! $campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Missing customer or campaign Google ID', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if (\App\Services\Campaigns\SingleGoogleSearchLandingPage::protectsResource($campaign, $resource)) {
            return ['applied' => false, 'requires_review' => true, 'message' => 'This Search campaign has one approved landing page and restricts additional extensions.', 'recommendation' => $rec];
        }
        $type = $rec['sub_type'] ?? null;

        if (! $type) {
            return ['applied' => false, 'message' => 'Missing extension sub_type', 'recommendation' => $rec];
        }

        try {
            [$assetResource, $fieldType, $label] = match ($type) {
                'structured_snippet' => $this->createSnippet($customer, $customerId, $rec),
                'call' => $this->createCall($customer, $customerId, $rec),
                'price' => $this->createPrice($customer, $customerId, $rec),
                'promotion' => $this->createPromotion($customer, $customerId, $rec),
                default => [null, null, $type],
            };

            if (! $assetResource) {
                return ['applied' => false, 'message' => "Failed to create {$type} extension", 'recommendation' => $rec];
            }

            $linkResult = (new LinkCampaignAsset($customer))($customerId, $resource, $assetResource, $fieldType);

            return ['applied' => $linkResult !== null, 'receipt' => ['asset' => $assetResource, 'role' => AssetFieldType::name($fieldType)], 'message' => $linkResult ? "{$label} created and linked" : "{$label} created but link failed", 'recommendation' => $rec];
        } catch (\Throwable $e) {
            report($e);

            return ['applied' => false, 'message' => 'Error: '.substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function createSnippet($customer, string $customerId, array $rec): array
    {
        $header = $rec['header'] ?? 'Services';
        $values = $rec['values'] ?? $rec['items'] ?? [];
        if (empty($values)) {
            return [null, null, 'Structured Snippet'];
        }
        $resource = (new CreateStructuredSnippetAsset($customer))($customerId, $header, $values);

        return [$resource, AssetFieldType::STRUCTURED_SNIPPET, 'Structured Snippet'];
    }

    private function createCall($customer, string $customerId, array $rec): array
    {
        $phone = $rec['phone_number'] ?? null;
        if (! $phone) {
            return [null, null, 'Call'];
        }
        $resource = (new CreateCallAsset($customer))($customerId, $phone, $customer->country);

        return [$resource, AssetFieldType::CALL, 'Call'];
    }

    private function createPrice($customer, string $customerId, array $rec): array
    {
        $offerings = $rec['offerings'] ?? [];
        if (empty($offerings)) {
            return [null, null, 'Price'];
        }
        $resource = (new CreatePriceAsset($customer))($customerId, (int) ($rec['price_type'] ?? 8), (int) ($rec['price_qualifier'] ?? 2), $offerings);

        return [$resource, AssetFieldType::PRICE, 'Price'];
    }

    private function createPromotion($customer, string $customerId, array $rec): array
    {
        $target = $rec['promotion_target'] ?? null;
        $data = $rec['promotion_data'] ?? [];
        if (! $target || empty($data)) {
            return [null, null, 'Promotion'];
        }
        $resource = (new CreatePromotionAsset($customer))($customerId, $target, $data);

        return [$resource, AssetFieldType::PROMOTION, 'Promotion'];
    }

    private function applyMicrosoftKeyword(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;
        $action = $rec['direction'] ?? $rec['action'] ?? null;

        // Microsoft AdGroupService only supports adding new keywords, not bid changes or pausing
        if (! in_array($action, ['add', 'expand'], true)) {
            return ['applied' => false, 'message' => "Microsoft keyword '{$action}' not auto-applicable — requires manual action in Microsoft Ads", 'recommendation' => $rec];
        }

        $adGroupId = $rec['ad_group_id'] ?? null;
        $keyword = $rec['keyword_text'] ?? $rec['text'] ?? null;
        $matchType = $rec['match_type'] ?? 'Broad';

        if (! $adGroupId || ! $keyword || ! $customer) {
            return ['applied' => false, 'message' => 'Missing ad_group_id, keyword_text, or customer', 'recommendation' => $rec];
        }

        try {
            $resourceId = (new MicrosoftAdGroupService($customer))->addKeyword($adGroupId, $keyword, $matchType);
            $applied = $resourceId !== null;

            return [
                'applied' => $applied,
                'message' => $applied ? "Keyword '{$keyword}' ({$matchType}) added to Microsoft ad group" : 'Failed to add keyword',
                'recommendation' => $rec,
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['applied' => false, 'message' => 'Error: '.substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function applyFacebookAudience(Campaign $campaign, $customer, array $rec): array
    {
        $accountId = $customer->facebook_ads_account_id;
        if (! $accountId) {
            return ['applied' => false, 'message' => 'Customer has no Facebook Ads account', 'recommendation' => $rec];
        }

        $daysSinceCreation = $campaign->created_at?->diffInDays(now()) ?? 0;
        if ($daysSinceCreation < 14) {
            return ['applied' => false, 'message' => "Campaign only {$daysSinceCreation} days old — audience creation requires 14+ days", 'recommendation' => $rec];
        }

        $subType = $rec['sub_type'] ?? 'lookalike';
        $audienceName = $rec['audience_name'] ?? $rec['description'] ?? 'Spectra Auto-Audience';
        $service = new FacebookCustomAudienceService($customer);

        try {
            if ($subType === 'lookalike') {
                $sourceId = $rec['source_audience_id'] ?? null;
                if (! $sourceId) {
                    return ['applied' => false, 'message' => 'Lookalike requires a source_audience_id', 'recommendation' => $rec];
                }
                $result = $service->createLookalikeAudience(
                    $accountId,
                    $sourceId,
                    $audienceName,
                    $rec['country_code'] ?? 'US',
                    (float) ($rec['ratio'] ?? 0.01)
                );
            } else {
                // Website custom audience fallback
                $result = $service->createWebsiteAudience(
                    $accountId,
                    $audienceName,
                    $rec['retention_days'] ?? 30,
                    $rec['pixel_rule'] ?? []
                );

                // sub_type comes from an AI-generated recommendation and is not
                // constrained. audiences.type has a CHECK allowing only
                // customer_match / remarketing / combined / lookalike, so writing
                // the raw value threw a constraint violation *after* the audience
                // had already been created on Facebook — orphaning it there with
                // no local record. This branch always builds a website audience,
                // which is a remarketing audience.
                $subType = 'remarketing';
            }

            if (! $result || empty($result['id'])) {
                return ['applied' => false, 'message' => "Failed to create Facebook {$subType} audience", 'recommendation' => $rec];
            }

            Audience::create([
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'name' => $audienceName,
                'platform' => 'facebook',
                'type' => $subType,
                'platform_resource_name' => $result['id'],
                'status' => 'creating',
                'source_data' => ['recommendation' => $rec, 'created_by' => 'optimization_agent'],
            ]);

            AgentActivity::record(
                'optimization', 'audience_submitted',
                "Submitted Facebook {$subType} audience '{$audienceName}'",
                $customer->id, $campaign->id,
                ['audience_id' => $result['id'], 'sub_type' => $subType]
            );

            return ['applied' => true, 'receipt' => ['audience' => (string) $result['id'], 'name' => $audienceName], 'message' => "Facebook {$subType} audience '{$audienceName}' created", 'recommendation' => $rec];
        } catch (\Throwable $e) {
            report($e);

            return ['applied' => false, 'message' => 'Error: '.substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function applyAudience(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return ['applied' => false, 'message' => 'Missing customer', 'recommendation' => $rec];
        }

        // Route Facebook audience actions
        if ($campaign->facebook_ads_campaign_id && ! $campaign->google_ads_campaign_id) {
            return $this->applyFacebookAudience($campaign, $customer, $rec);
        }

        if (! $campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Audience actions not yet supported for this platform', 'recommendation' => $rec];
        }

        $daysSinceCreation = $campaign->created_at?->diffInDays(now()) ?? 0;
        if ($daysSinceCreation < 30) {
            return ['applied' => false, 'message' => "Campaign only {$daysSinceCreation} days old — audience requires 30+ days of data", 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $subType = $rec['sub_type'] ?? 'remarketing';
        $listName = $rec['audience_name'] ?? $rec['description'] ?? 'Auto-created audience';

        try {
            $resourceName = match ($subType) {
                'customer_match' => (new CreateUserList($customer))($customerId, $listName, $rec['description'] ?? ''),
                'remarketing' => (new CreateRemarketingAudience($customer))($customerId, $listName, $rec['url_contains'] ?? '/', (int) ($rec['membership_days'] ?? 90)),
                default => null,
            };

            if (! $resourceName) {
                return ['applied' => false, 'message' => "Failed to create {$subType} audience", 'recommendation' => $rec];
            }

            Audience::create([
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'name' => $listName,
                'platform' => 'google',
                'type' => $subType,
                'platform_resource_name' => $resourceName,
                'status' => 'creating',
                'source_data' => ['recommendation' => $rec, 'created_by' => 'optimization_agent'],
            ]);

            return ['applied' => true, 'receipt' => ['audience' => $resourceName, 'name' => $listName], 'message' => "{$subType} audience '{$listName}' created", 'recommendation' => $rec];
        } catch (\Throwable $e) {
            report($e);

            return ['applied' => false, 'message' => 'Error: '.substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }
}
