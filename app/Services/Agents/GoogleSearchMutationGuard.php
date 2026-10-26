<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Features\AutoHealing;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Laravel\Pennant\Feature;

/** Demand and affordable reach for the complete proposed Search portfolio, never authorization by itself. */
class GoogleSearchMutationGuard
{
    public function __construct(private GoogleSearchReachPlanner $planner, private GoogleSearchReachGate $gate) {}

    public function assess(Campaign $campaign, array $changes, bool $approvedByUser = false): array
    {
        return $this->assessChanges($campaign, $changes, $approvedByUser, false);
    }

    /** Only proven, matured, campaign-level exact exclusions; never additional spend or reach. */
    public function assessCostExclusions(Campaign $campaign, array $changes): array
    {
        return $this->assessChanges($campaign, $changes, false, true);
    }

    /** Add a small researched set under ongoing limits; actual delivery must subsequently be observed. */
    public function assessBoundedExpansion(Campaign $campaign, array $changes): array
    {
        return $this->assessChanges($campaign, $changes, false, false, true);
    }

    private function assessChanges(Campaign $campaign, array $changes, bool $approvedByUser, bool $costContainment, bool $boundedExpansion = false): array
    {
        try {
            $campaign = $campaign->fresh(['customer']) ?? $campaign;
            if (! $campaign->customer || ! CampaignSpendGuardrails::canEnable($campaign, $approvedByUser) || $campaign->hasPassedEndDate() || $campaign->spend_safety_hold
                || ! $approvedByUser && ($campaign->customer->service_type !== 'managed' || $campaign->status !== CampaignStatus::Active || CampaignSpendGuardrails::automaticChangesSuspended($campaign))) {
                return $this->blocked('Campaign changes require review: the campaign is inactive, ended, or under an approved spending hold or trial.');
            }
            if ($costContainment && ! $this->costExclusionsAuthorized($campaign, $changes)) {
                return $this->blocked('Protective cost exclusions require an approved automation profile and exact campaign exclusions only.');
            }
            if ($boundedExpansion && ! $this->boundedExpansionAuthorized($campaign, $changes)) {
                return $this->blocked('Bounded keyword expansion requires approved ongoing limits and at most three researched exact or phrase additions.');
            }
            $snapshot = $this->inspect($campaign);
            if (! $snapshot || ($snapshot['channel'] ?? null) !== 2 || ($snapshot['google_search_enabled'] ?? null) !== true
                || ($snapshot['approved_ads'] ?? 0) < 1 || ! $approvedByUser && ($snapshot['status'] ?? null) !== 2) {
                return $this->blocked('Current Google Search settings could not be verified.', 'unavailable');
            }
            $costEvidence = $costContainment ? $this->costExclusionEvidence($campaign, $snapshot, $changes) : null;
            if ($costContainment && $costEvidence === null) {
                return $this->blocked('Fresh matured spend, zero recent conversion evidence and a nonconflicting exact exclusion could not be verified.');
            }
            if ($boundedExpansion && ! $this->boundedExpansionBids($campaign, $snapshot, $changes)) {
                return $this->blocked('Keyword additions cannot increase existing effective bids or exceed approved limits.');
            }
            if (CampaignSpendGuardrails::activeTrial($campaign)) {
                $spend = $this->spend($campaign);
                $snapshot['reported_lifetime_cost_micros'] = $spend['cost_micros'];
                $snapshot['reported_spend_checked_at'] = now()->toIso8601String();
            }
            $proposed = $this->project($campaign, $snapshot, $changes);
            $keywords = array_values(array_filter($proposed['keywords'], fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
                && ($keyword['ad_group_status'] ?? null) === 'ENABLED'));
            if ($keywords === []) {
                return $this->blocked('The proposed changes leave no active Search keywords. Review a replacement portfolio before applying them.');
            }
            $baseline = $boundedExpansion ? $this->planner->assessMutation($campaign, $snapshot,
                array_values(array_filter($snapshot['keywords'], fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
                    && ($keyword['ad_group_status'] ?? null) === 'ENABLED'))) : null;
            $assessment = $this->planner->assessMutation($campaign, $proposed, $keywords);
            if ($boundedExpansion && ! $this->boundedExpansionEvidence($campaign, $snapshot, $baseline, $assessment, $changes)) {
                return $this->blocked('Fresh demand and a material whole-portfolio traffic improvement within the current limits were not demonstrated.');
            }
            $result = $boundedExpansion ? $this->gate->evaluateBoundedExpansion($campaign, $proposed, $assessment)
                : ($costContainment ? $this->gate->evaluateCostContainment($campaign, $proposed, $assessment)
                    : $this->gate->evaluate($campaign, $proposed, $assessment));

            if ($result['ready']) {
                $fresh = $campaign->fresh(['customer']);
                $after = $fresh ? $this->inspect($fresh) : null;
                $fresh = $fresh?->fresh(['customer']); // Local approval may have changed during the final API read.
                if (! $fresh || ! CampaignSpendGuardrails::canEnable($fresh, $approvedByUser) || $fresh->spend_safety_hold || $fresh->hasPassedEndDate()
                    || ! $approvedByUser && ($fresh->customer?->service_type !== 'managed' || $fresh->status !== CampaignStatus::Active || CampaignSpendGuardrails::automaticChangesSuspended($fresh))
                    || ! $after || $this->fingerprint($snapshot) !== $this->fingerprint($after)) {
                    return $this->blocked('Campaign settings or authorization changed during reach research. Review the current settings before applying the proposal.');
                }
                if (CampaignSpendGuardrails::activeTrial($fresh)) {
                    $spend = $this->spend($fresh);
                    $after['reported_lifetime_cost_micros'] = $spend['cost_micros'];
                    $after['reported_spend_checked_at'] = now()->toIso8601String();
                }
                $fresh->refresh(); // Refresh throws if the campaign was removed, which cannot authorize a write.
                if (! CampaignSpendGuardrails::canEnable($fresh, $approvedByUser)
                    || ! $approvedByUser && ($fresh->customer?->service_type !== 'managed' || CampaignSpendGuardrails::automaticChangesSuspended($fresh))) {
                    return $this->blocked('Campaign authorization changed during the final spend verification. Review the current limits before applying the proposal.');
                }
                if ($costContainment) {
                    $costEvidence = $this->costExclusionsAuthorized($fresh, $changes)
                        ? $this->costExclusionEvidence($fresh, $after, $changes) : null;
                    $finalSnapshot = $costEvidence !== null ? $this->inspect($fresh) : null;
                    $fresh = $fresh->fresh(['customer']);
                    if ($costEvidence === null || ! $fresh || ! $this->costExclusionsAuthorized($fresh, $changes)
                        || ! $finalSnapshot || $this->fingerprint($after) !== $this->fingerprint($finalSnapshot)) {
                        return $this->blocked('Protective exclusion evidence or authorization changed during the final verification.');
                    }
                    $after = $finalSnapshot;
                }
                if ($boundedExpansion && (! $this->boundedExpansionAuthorized($fresh, $changes)
                    || ! $this->boundedExpansionBids($fresh, $after, $changes))) {
                    return $this->blocked('Bounded expansion limits or authorization changed during research.');
                }
                $proposed = $this->project($fresh, $after, $changes);
                $snapshot = $after;
                $result = $boundedExpansion ? $this->gate->evaluateBoundedExpansion($fresh, $proposed, $assessment)
                    : ($costContainment ? $this->gate->evaluateCostContainment($fresh, $proposed, $assessment)
                        : $this->gate->evaluate($fresh, $proposed, $assessment));
            }

            return ['ready' => $result['ready'], 'status' => $result['status'], 'issues' => $result['issues'],
                'message' => $result['ready'] ? ($boundedExpansion
                    ? 'The bounded researched additions fit approved limits. The indicative forecast requires subsequent live traffic verification.'
                    : ($costContainment
                    ? 'Only the verified protective exact exclusions are eligible. The indicative forecast does not verify remaining reach.'
                    : 'The proposed Search changes have current demand and affordable forecast evidence.'))
                    : AgentIssue::toSentence($result['issues']),
                'snapshot' => $proposed, 'observed_snapshot' => $snapshot, 'assessment' => $assessment, 'reach_gate' => $result,
                ...($costContainment ? ['cost_containment_only' => true, 'reach_not_verified' => true, 'cost_containment_evidence' => $costEvidence] : []),
                ...($boundedExpansion ? ['bounded_expansion' => true, 'reach_not_verified' => true, 'baseline' => $baseline] : [])];
        } catch (\Throwable $e) {
            report($e);

            return $this->blocked('Fresh Search demand or forecast evidence is unavailable; no campaign change was authorized.', 'unavailable');
        }
    }

    private function project(Campaign $campaign, array $snapshot, array $changes): array
    {
        $customerId = $campaign->customer->cleanGoogleCustomerId();
        $keywords = $snapshot['keywords'] ?? [];
        $activeGroups = array_column(array_filter($keywords, fn ($keyword) => ($keyword['ad_group_status'] ?? null) === 'ENABLED'), 'ad_group_resource');
        // Exclusions must be staged before additions, regardless of producer ordering.
        usort($changes, fn ($a, $b) => (($a['action'] ?? null) === 'negative' ? 0 : 1) <=> (($b['action'] ?? null) === 'negative' ? 0 : 1));
        foreach ($changes as $change) {
            $action = $change['action'] ?? null;
            if ($action === 'bidding') {
                $ceiling = $change['cpc_bid_ceiling_micros'] ?? null;
                if (($change['strategy'] ?? null) !== 'MAXIMIZE_CLICKS' || ! is_int($ceiling) || $ceiling <= 0
                    || ! CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', $ceiling)
                    || isset($change['expected_bidding_strategy']) && $change['expected_bidding_strategy'] !== ($snapshot['bidding_strategy'] ?? null)) {
                    throw new \InvalidArgumentException('A capped bidding repair requires its exact approved strategy, ceiling and current strategy.');
                }
                $snapshot['bidding_strategy'] = BiddingStrategyType::TARGET_SPEND;
                $snapshot['cpc_bid_ceiling_micros'] = $snapshot['actual_cpc_ceiling_micros'] = $ceiling;

                continue;
            }
            if (in_array($action, ['add', 'negative'], true)) {
                $text = trim($change['text'] ?? '');
                $match = strtoupper($change['match_type'] ?? '');
                if ($text === '' || ! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                    throw new \InvalidArgumentException('A keyword change requires text and a known match type.');
                }
                $group = $change['ad_group_resource'] ?? null;
                if ($action === 'add' || ($change['scope'] ?? 'campaign') === 'ad_group') {
                    if (! is_string($group) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/adGroups/\d+$~', $group)
                        || ! in_array($group, $activeGroups, true)) {
                        throw new \InvalidArgumentException('A keyword addition must use this campaign’s verified active ad group.');
                    }
                }
                if ($action === 'negative') {
                    $snapshot['negative_keywords'][] = ['text' => $text, 'match_type' => $match,
                        'scope' => $change['scope'] ?? 'campaign', 'ad_group_resource' => $group];

                    continue;
                }
                if (! is_string($change['selection_reason'] ?? null) || trim($change['selection_reason']) === '') {
                    throw new \InvalidArgumentException('A new keyword requires a recorded commercial selection reason.');
                }
                if (GoogleSearchReachPlanner::negativeConflict($text, $snapshot['negative_keywords'] ?? [], $group)) {
                    throw new \InvalidArgumentException('The proposed keyword conflicts with a current negative keyword.');
                }
                foreach ($keywords as $existing) {
                    if (($existing['ad_group_resource'] ?? null) === $group && ($existing['match_type'] ?? null) === $match
                        && mb_strtolower(trim($existing['text'] ?? '')) === mb_strtolower($text)
                        && ($existing['status'] ?? null) !== 'REMOVED') {
                        throw new \InvalidArgumentException('The proposed keyword already exists; duplicate additions cannot be forecast as extra reach.');
                    }
                }
                $added = ['text' => $text, 'match_type' => $match, 'ad_group_resource' => $group,
                    'status' => 'ENABLED', 'ad_group_status' => 'ENABLED', 'selection_reason' => $change['selection_reason']];
                if (($snapshot['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC) {
                    $groupKeywords = array_filter($keywords, fn ($keyword) => ($keyword['ad_group_resource'] ?? null) === $group);
                    $bids = array_column($groupKeywords, 'ad_group_cpc_bid_micros');
                    $bid = $change['bid_micros'] ?? ($bids === [] ? 0 : max($bids));
                    if (! is_int($bid) || $bid <= 0 || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $bid)) {
                        throw new \InvalidArgumentException('The new keyword’s inherited manual CPC could not be verified.');
                    }
                    $added['cpc_bid_micros'] = $bid;
                }
                $keywords[] = $added;

                continue;
            }
            if (! in_array($action, ['enable', 'pause', 'remove', 'bid'], true)) {
                throw new \InvalidArgumentException('Unsupported Search mutation cannot bypass reach verification.');
            }
            $resource = $change['resource_name'] ?? null;
            $found = false;
            foreach ($keywords as &$keyword) {
                if (($keyword['resource_name'] ?? $keyword['resource'] ?? null) !== $resource || ! is_string($resource)) {
                    continue;
                }
                $found = true;
                if ($action === 'bid') {
                    $bid = $change['bid_micros'] ?? null;
                    if (($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC || ! is_int($bid) || $bid <= 0
                        || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $bid)) {
                        throw new \InvalidArgumentException('Only a verified, permitted manual CPC keyword bid can be forecast.');
                    }
                    $keyword['cpc_bid_micros'] = $bid;
                } else {
                    $keyword['status'] = $action === 'enable' ? 'ENABLED' : ($action === 'pause' ? 'PAUSED' : 'REMOVED');
                }
            }
            unset($keyword);
            if (! $found) {
                throw new \InvalidArgumentException('The changed keyword does not belong to this campaign’s current snapshot.');
            }
        }
        $snapshot['keywords'] = $keywords;
        if (($snapshot['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC) {
            $active = array_filter($keywords, fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED' && ($keyword['ad_group_status'] ?? null) === 'ENABLED');
            $bids = array_column($active, 'cpc_bid_micros');
            if (count($bids) !== count($active) || array_filter($bids, fn ($bid) => ! is_int($bid) || $bid <= 0)) {
                throw new \InvalidArgumentException('Every enabled manual CPC keyword requires a verified effective bid.');
            }
            $snapshot['cpc_bid_ceiling_micros'] = $snapshot['actual_cpc_ceiling_micros'] = $bids === [] ? 0 : max($bids);
        }

        return $snapshot;
    }

    private function fingerprint(array $snapshot): string
    {
        return GoogleSearchReachGate::settingsFingerprint($snapshot);
    }

    private function boundedExpansionAuthorized(Campaign $campaign, array $changes): bool
    {
        $customer = $campaign->customer;
        $limits = CampaignSpendGuardrails::automationLimits($campaign);
        $cap = $limits['max_cpc_bid_micros'] ?? null;
        if ($changes === [] || count($changes) > 3 || ! $customer || $customer->service_type !== 'managed'
            || $customer->is_sandbox || $customer->google_ads_link_status === 'revoked'
            || $campaign->status !== CampaignStatus::Active || $campaign->spend_safety_hold || $campaign->hasPassedEndDate()
            || CampaignSpendGuardrails::activeTrial($campaign) || ! $limits || ! is_int($cap)
            || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $cap)
            || ! Feature::for($customer)->active(AutoHealing::class)) {
            return false;
        }
        foreach ($changes as $change) {
            if (! is_array($change) || array_diff(array_keys($change), ['action', 'text', 'match_type', 'ad_group_resource', 'selection_reason', 'bid_micros']) !== []
                || ($change['action'] ?? null) !== 'add' || ! in_array($change['match_type'] ?? null, ['EXACT', 'PHRASE'], true)
                || ! is_string($change['selection_reason'] ?? null) || trim($change['selection_reason']) === '') {
                return false;
            }
        }

        return true;
    }

    private function boundedExpansionBids(Campaign $campaign, array $snapshot, array $changes): bool
    {
        $limits = CampaignSpendGuardrails::automationLimits($campaign);
        $cap = $snapshot['actual_cpc_ceiling_micros'] ?? null;
        $budget = $snapshot['daily_budget_micros'] ?? null;
        if (($snapshot['account_time_zone_verified'] ?? null) !== true
            || ($snapshot['currency_code'] ?? null) !== ($limits['currency_code'] ?? null)
            || ! is_int($cap) || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $cap)
            || ! is_int($budget) || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget)) {
            return false;
        }
        if (($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC) {
            return ($snapshot['bidding_strategy'] ?? null) === BiddingStrategyType::TARGET_SPEND;
        }
        foreach ($changes as $change) {
            $groups = array_filter($snapshot['keywords'] ?? [], fn ($keyword) => ($keyword['ad_group_resource'] ?? null) === ($change['ad_group_resource'] ?? null)
                && ($keyword['ad_group_status'] ?? null) === 'ENABLED');
            $bids = array_column($groups, 'ad_group_cpc_bid_micros');
            $inherited = $bids === [] ? 0 : max($bids);
            $bid = $change['bid_micros'] ?? $inherited;
            if (! is_int($inherited) || $inherited <= 0 || ! is_int($bid) || $bid <= 0
                || $bid > min($cap, $inherited) || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $bid)) {
                return false;
            }
        }

        return true;
    }

    private function boundedExpansionEvidence(Campaign $campaign, array $snapshot, ?array $baseline, array $assessment, array $changes): bool
    {
        if (! $baseline) {
            return false;
        }
        // The old portfolio may have insufficient traffic, but every other piece
        // of baseline evidence must be current and compatible with the proposal.
        $oldGate = $this->gate->evaluateBoundedExpansion($campaign, $snapshot, $baseline);
        $allowed = ['search_reach_below_useful_threshold', 'search_reach_no_forecast_traffic', 'search_reach_no_forecast_impressions'];
        if (array_diff(array_column($oldGate['issues'], 'code'), $allowed) !== []) {
            return false;
        }
        $old = $baseline['forecast'] ?? [];
        $new = $assessment['forecast'] ?? [];
        $days = $old['period_days'] ?? null;
        if (! is_int($days) || $days <= 0 || ($new['period_days'] ?? null) !== $days
            || ($old['forecast_period'] ?? null) !== ($new['forecast_period'] ?? null)
            || ! is_numeric($old['clicks'] ?? null) || ! is_numeric($new['clicks'] ?? null)
            || ! is_finite((float) $old['clicks']) || ! is_finite((float) $new['clicks']) || $old['clicks'] < 0) {
            return false;
        }
        $before = $old['clicks'] * 30 / $days;
        $after = $new['clicks'] * 30 / $days;
        if ($after < $before + max(1, $before * 0.2)) {
            return false;
        }
        foreach ($changes as $change) {
            $metric = GoogleSearchReachPlanner::historicalMetric($assessment['historical']['keywords'] ?? [], $change['text'] ?? '');
            if (! is_numeric($metric['avg_monthly_searches'] ?? null) || $metric['avg_monthly_searches'] <= 0) {
                return false;
            }
        }

        return true;
    }

    private function costExclusionsAuthorized(Campaign $campaign, array $changes): bool
    {
        $limits = CampaignSpendGuardrails::automationLimits($campaign);
        $customer = $campaign->customer;
        $cap = $limits['max_cpc_bid_micros'] ?? null;
        if ($changes === [] || count($changes) > 20 || ! $limits || ! is_int($cap)
            || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $cap)
            || $campaign->status !== CampaignStatus::Active || ! $customer || $customer->service_type !== 'managed'
            || $customer->is_sandbox || $customer->google_ads_link_status === 'revoked'
            || $campaign->spend_safety_hold || CampaignSpendGuardrails::activeTrial($campaign) || $campaign->hasPassedEndDate()
            || ! Feature::for($customer)->active(AutoHealing::class)
            || $campaign->googleAdsResourceName() !== 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$campaign->googleCampaignNumericId()) {
            return false;
        }
        $seen = [];
        foreach ($changes as $change) {
            if (! is_array($change) || array_diff(array_keys($change), ['action', 'text', 'match_type', 'scope', 'reason']) !== []
                || ($change['action'] ?? null) !== 'negative' || ($change['match_type'] ?? null) !== 'EXACT'
                || ($change['scope'] ?? null) !== 'campaign' || ! is_string($change['text'] ?? null) || trim($change['text']) === '') {
                return false;
            }
            $text = mb_strtolower(trim($change['text']));
            if (isset($seen[$text])) {
                return false;
            }
            $seen[$text] = true;
        }

        return true;
    }

    /** Provider observations, not producer-supplied cost claims, authorize exact cost exclusions. */
    private function costExclusionEvidence(Campaign $campaign, array $snapshot, array $changes): ?array
    {
        $timezone = $snapshot['time_zone'] ?? null;
        $limits = CampaignSpendGuardrails::automationLimits($campaign);
        $cap = $snapshot['actual_cpc_ceiling_micros'] ?? null;
        $budget = $snapshot['daily_budget_micros'] ?? null;
        if (($snapshot['account_time_zone_verified'] ?? null) !== true || ! is_string($timezone)
            || ! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ($snapshot['currency_code'] ?? null) !== ($limits['currency_code'] ?? null)
            || ! is_int($budget) || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget)
            || ! is_int($cap) || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $cap)) {
            return null;
        }
        $newNegatives = array_map(fn ($change) => ['text' => trim($change['text']), 'match_type' => 'EXACT', 'scope' => 'campaign'], $changes);
        foreach ($snapshot['keywords'] ?? [] as $keyword) {
            if (($keyword['status'] ?? null) === 'ENABLED' && ($keyword['ad_group_status'] ?? null) === 'ENABLED'
                && GoogleSearchReachPlanner::negativeConflict($keyword['text'] ?? '', $newNegatives, $keyword['ad_group_resource'] ?? '')) {
                return null;
            }
        }
        $fallback = CampaignSpendGuardrails::moneyToMicros(config('optimization.search_terms.negative_cost_threshold', 50));
        $fraction = config('optimization.search_terms.negative_daily_budget_fraction', 0.5);
        if ($fallback <= 0 || ! is_numeric($fraction) || ! is_finite((float) $fraction) || $fraction <= 0 || $fraction > 1) {
            return null;
        }
        $approved = min($budget, CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget), $limits['max_daily_budget_micros']);
        $basisPoints = (int) floor((float) $fraction * 10_000);
        $threshold = max(1, min($fallback, intdiv($approved, 10_000) * $basisPoints + intdiv(($approved % 10_000) * $basisPoints, 10_000)));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $today->subDays(30)->toDateString();
        $through = $today->subDays(max(1, (int) config('optimization.search_terms.conversion_lag_days', 2)) + 1)->toDateString();
        $requested = array_fill_keys(array_map(fn ($change) => mb_strtolower(trim($change['text'])), $changes), true);
        $observations = [];
        foreach (['LAST_30_DAYS_INCLUDING_TODAY' => false, 'MATURED_LAST_30_DAYS' => true] as $range => $matured) {
            $seen = [];
            foreach ($this->searchTermReport($campaign, $range) as $row) {
                $text = mb_strtolower(trim($row['search_term'] ?? ''));
                if (! isset($requested[$text])) {
                    continue;
                }
                $group = $row['ad_group_resource_name'] ?? '';
                if (($row['campaign_resource_name'] ?? null) !== $campaign->googleAdsResourceName()
                    || ! is_string($group) || ! preg_match('#^customers/'.preg_quote($campaign->customer->cleanGoogleCustomerId(), '#').'/adGroups/[1-9][0-9]*$#D', $group)
                    || ($row['account_timezone'] ?? null) !== $timezone || ($row['reported_from'] ?? null) !== $from
                    || ($row['matured'] ?? null) !== $matured
                    || ($matured ? ($row['matured_through'] ?? null) !== $through : ($row['reported_through'] ?? null) !== $today->toDateString())
                    || isset($seen[$text.'|'.$group])) {
                    return null;
                }
                $seen[$text.'|'.$group] = true;
                foreach (['conversions', 'all_conversions'] as $metric) {
                    if (! is_numeric($row[$metric] ?? null) || ! is_finite((float) $row[$metric]) || (float) $row[$metric] !== 0.0) {
                        return null;
                    }
                }
                $observations[$text] ??= ['recent_verified' => false, 'matured_clicks' => 0, 'matured_cost_micros' => 0];
                if (! $matured) {
                    $observations[$text]['recent_verified'] = true;

                    continue;
                }
                $clicks = $this->integerMetric($row['clicks'] ?? null);
                $cost = $this->integerMetric($row['cost_micros'] ?? null);
                if ($clicks === null || $cost === null || $cost > PHP_INT_MAX - $observations[$text]['matured_cost_micros']) {
                    return null;
                }
                $observations[$text]['matured_clicks'] += $clicks;
                $observations[$text]['matured_cost_micros'] += $cost;
            }
        }
        foreach ($requested as $text => $_) {
            $observation = $observations[$text] ?? [];
            if (($observation['recent_verified'] ?? false) !== true
                || $observation['matured_clicks'] < max(2, (int) config('optimization.search_terms.negative_min_clicks', 2))
                || $observation['matured_cost_micros'] < $threshold) {
                return null;
            }
        }

        return ['checked_at' => now()->toIso8601String(), 'account_timezone' => $timezone,
            'matured_through' => $through, 'cost_threshold_micros' => $threshold, 'terms' => $observations];
    }

    private function integerMetric(mixed $value): ?int
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^[0-9]{1,16}$/D', $value)) ? (int) $value : null;
    }

    private function blocked(string $message, string $status = 'blocked'): array
    {
        return ['ready' => false, 'status' => $status, 'message' => $message,
            'issues' => [(new AgentIssue('search_mutation_reach_unverified', $message))->toArray()]];
    }

    protected function inspect(Campaign $campaign): ?array
    {
        return app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign, CarbonImmutable::now()->subDays(2));
    }

    protected function spend(Campaign $campaign): array
    {
        return app(GetCampaignSpendSafety::class, ['customer' => $campaign->customer])->forCampaign($campaign);
    }

    protected function searchTermReport(Campaign $campaign, string $range): array
    {
        return app(GetSearchTermsReport::class, ['customer' => $campaign->customer])(
            $campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName(), $range);
    }
}
