<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Features\AutoHealing;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\GoogleAdRankRepair;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Common\KeywordInfo;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupCriteriaRequest;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/** Observe a restart separately from historical traffic; never loosen approved spending limits. */
class GoogleSearchReachRecovery
{
    public function __construct(private GoogleSearchReachPlanner $planner) {}

    public function check(Campaign $campaign): array
    {
        $campaign = $campaign->fresh(['customer']) ?? $campaign;
        $previous = $campaign->search_delivery_state ?? [];
        $state = [...$previous, 'checked_at' => now()->toIso8601String(),
            'stale_after_hours' => (int) config('optimization.search_delivery.stale_after_hours', 3),
            'mutation_allowed' => false, 'errors' => []];
        try {
            if ((! $campaign->spend_safety_hold && $campaign->status !== CampaignStatus::Active) || $campaign->hasPassedEndDate()) {
                return $this->save($campaign, [...$state, 'status' => 'paused',
                    'blocked_reason' => $campaign->spend_safety_hold ? 'spend_safety_hold' : 'campaign_not_active']);
            }
            if (! $campaign->customer?->google_ads_customer_id || ! $campaign->googleCampaignNumericId()) {
                throw new \RuntimeException('The Google customer or campaign identifier is unavailable.');
            }
            if (! $campaign->strategies()->where('campaign_type', 'search')
                ->whereIn('deployment_status', ['active', 'verified', 'deployed'])->exists()) {
                return $this->save($campaign, [...$state, 'status' => 'paused', 'blocked_reason' => 'not_deployed_search_campaign']);
            }
            if ($this->rankRepairCoolingDown($campaign)) {
                // The durable repair owns this server-imposed read cooldown. Let its
                // local ownership/permission checks run before making any Google read.
                // Old measurements and fingerprints remain old; this is no fresh proof.
                $result = $this->repairAdRank($campaign, [], ['status' => 'collecting_evidence',
                    'reason' => 'google_api_quota_cooldown']);

                return $this->saveRankResult($campaign, $state, $result);
            }
            $started = $this->evaluationStart($campaign, $previous);
            $snapshot = $this->inspect($campaign, $started);
            if (! $snapshot) {
                throw new \RuntimeException('Google did not return a Search delivery snapshot.');
            }
            if (($snapshot['channel'] ?? null) !== AdvertisingChannelType::SEARCH) {
                return $this->save($campaign, [...$state, 'status' => 'paused', 'blocked_reason' => 'not_search_campaign']);
            }
            if (($snapshot['status'] ?? null) !== 2 && ! $campaign->spend_safety_hold) {
                return $this->save($campaign, [...$state, 'status' => 'paused', 'blocked_reason' => 'google_campaign_not_enabled']);
            }
            $fingerprint = $this->fingerprint($campaign, $snapshot);
            if (isset($previous['config_fingerprint']) && $previous['config_fingerprint'] !== $fingerprint) {
                // No earlier metrics may certify a changed keyword/bid/targeting configuration.
                $started = CarbonImmutable::now();
                $snapshot['measurement'] = ['started_at' => $started->toIso8601String(),
                    'from' => $started->toIso8601String(), 'through' => $started->toIso8601String(),
                    'complete_hours' => 0, 'impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0];
                if (! empty($state['repair']['started_at'])) {
                    // A partial or unverified remote write changes the keyword
                    // fingerprint itself. That cannot erase the failed attempt.
                    $state['repair']['errors'][] = ['code' => 'repair_configuration_changed',
                        'message' => 'Live settings changed after a repair started. Review its outcome before resetting this incident.'];
                } else {
                    unset($state['repair'], $state['verification'], $state['diagnosis'], $state['alert_key'], $state['last_alert_at']);
                }
            }
            $measurement = $snapshot['measurement'] ?? null;
            if (! is_array($measurement) || ! isset($measurement['complete_hours'], $measurement['impressions'])) {
                throw new \RuntimeException('Google hourly delivery evidence is unavailable.');
            }
            $providerStart = $snapshot['evaluation_started_at'] ?? $measurement['started_at'] ?? null;
            if (is_string($providerStart)) {
                $started = $started->max(CarbonImmutable::parse($providerStart));
            }
            $state = [...$state, 'evaluation_started_at' => $started->toIso8601String(),
                'measurement_started_at' => $started->toIso8601String(), 'config_fingerprint' => $fingerprint,
                'measurement' => $measurement, 'currency_code' => $snapshot['currency_code'] ?? null,
                'verified_limits' => ['checked_at' => $state['checked_at'],
                    'daily_budget_micros' => $snapshot['daily_budget_micros'] ?? null,
                    'cpc_bid_ceiling_micros' => $snapshot['actual_cpc_ceiling_micros'] ?? null]];
            $rank = $this->rankDiagnosis($snapshot);
            $rank['evidence'] = $snapshot['rank_evidence'] ?? [];
            $rank['evidence']['status'] = match ($rank['status']) {
                'rank_limited', 'not_rank_limited' => 'verified',
                'collecting_evidence' => 'collecting_evidence',
                default => 'unavailable',
            };
            $rank['evidence']['reason'] = $rank['reason'] ?? null;
            $state['ad_rank']['diagnosis'] = $rank;
            $pendingRankRepair = ($state['ad_rank']['repair']['status'] ?? null) === 'verifying'
                || $this->hasPendingRankRepair($campaign);
            if ($pendingRankRepair || $rank['status'] === 'rank_limited') {
                // Creative repair has its own narrow permissions and durable
                // read-back workflow. A passing reach forecast cannot hide rank loss.
                $result = $this->repairAdRank($campaign, $snapshot, $rank);

                return $this->saveRankResult($campaign, $state, $result);
            }
            if (! empty($state['repair']['started_at']) && ! $campaign->spend_safety_hold) {
                return $this->verify($campaign, $snapshot, $state);
            }
            $mature = (int) $measurement['complete_hours'] >= (int) config('optimization.search_delivery.diagnosis_after_hours', 12);
            $enoughTraffic = (int) $measurement['impressions'] >= (int) config('optimization.search_delivery.min_impressions', 5);
            $meaningfulTraffic = $enoughTraffic && (int) ($measurement['clicks'] ?? 0)
                >= max(1, (int) config('optimization.search_delivery.min_meaningful_clicks', 3));
            $researchDue = empty($state['last_research_at'])
                || CarbonImmutable::parse($state['last_research_at'])->lte(now()->subHours((int) config('optimization.search_delivery.research_cooldown_hours', 24)));
            $research = $mature && $researchDue && (! $enoughTraffic || $campaign->spend_safety_hold);
            $diagnosis = $this->planner->assess($campaign, $snapshot, $research);
            $reachRisk = $this->hasReachRisk($diagnosis);
            if (! $research && $mature && $researchDue && $reachRisk) {
                // A little traffic is not proof that the existing keyword theme
                // has enough forecast reach for an economically useful test.
                $research = true;
                $diagnosis = $this->planner->assess($campaign, $snapshot, true);
                $reachRisk = $this->hasReachRisk($diagnosis);
            }
            if ($research) {
                $state['last_research_at'] = now()->toIso8601String();
            }
            // Fresh failures stay failures. Only merge the still-current researched
            // proposal into a successful hourly baseline assessment.
            if (! $research && ($previous['config_fingerprint'] ?? null) === $fingerprint
                && ($previous['diagnosis']['status'] ?? null) === 'evidence_ready'
                && ($diagnosis['status'] ?? null) === 'evidence_ready'
                && ! empty($previous['last_research_at'])
                && CarbonImmutable::parse($previous['last_research_at'])->gt(now()->subHours((int) config('optimization.search_delivery.research_cooldown_hours', 24)))) {
                foreach (['candidate_keywords', 'combined_forecast', 'proposal', 'auto_repair_safe'] as $key) {
                    if (array_key_exists($key, $previous['diagnosis'])) {
                        $diagnosis[$key] = $previous['diagnosis'][$key];
                    }
                }
            }
            $state['diagnosis'] = $diagnosis;
            if (! $campaign->spend_safety_hold && $enoughTraffic && ! $reachRisk
                && ($diagnosis['status'] ?? null) === 'evidence_ready') {
                if (($blocked = $this->servingBlocked($snapshot)) !== null) {
                    // Earlier impressions cannot prove that the campaign can
                    // still serve with its current ad and keyword eligibility.
                    $state = [...$state, 'status' => 'needs_review', 'blocked_reason' => $blocked];

                    return $mature ? $this->alertAndSave($campaign, $state) : $this->save($campaign, $state);
                }
                if ($meaningfulTraffic) {
                    unset($state['alert_key'], $state['last_alert_at']);

                    return $this->save($campaign, [...$state, 'status' => 'delivering', 'blocked_reason' => null]);
                }
            }
            if (! $mature) {
                if (($diagnosis['status'] ?? null) !== 'evidence_ready') {
                    return $this->save($campaign, [...$state, 'status' => 'unavailable', 'blocked_reason' => 'reach_evidence_unavailable']);
                }

                return $this->save($campaign, [...$state, 'status' => ! empty($diagnosis['issues']) ? 'low_reach' : 'collecting_evidence',
                    'blocked_reason' => 'collecting_complete_hours']);
            }
            if (($diagnosis['status'] ?? null) !== 'evidence_ready') {
                return $this->alertAndSave($campaign, [...$state, 'status' => 'unavailable',
                    'blocked_reason' => 'reach_evidence_unavailable']);
            }
            if (! $campaign->spend_safety_hold && $enoughTraffic && ! $reachRisk) {
                // Impressions alone show that an ad appeared, not that the test
                // reached prospective customers. Keep observing before another write.
                return $this->save($campaign, [...$state, 'status' => 'low_traffic',
                    'blocked_reason' => $rank['status'] === 'unavailable' ? 'ad_rank_evidence_unavailable' : 'insufficient_meaningful_traffic']);
            }
            $blocked = $this->mutationBlocked($campaign, $snapshot, $diagnosis, $state);
            if ($blocked !== null) {
                $state['diagnosis']['proposal']['blocked_reason'] = $blocked;
                $explanation = GoogleSearchDeliveryAlertContent::holdReason($blocked);
                $state['diagnosis']['proposal']['blocked_by'] = array_values(array_unique([
                    ...($state['diagnosis']['proposal']['blocked_by'] ?? []), $explanation]));

                return $this->alertAndSave($campaign, [...$state, 'status' => 'approval_required', 'blocked_reason' => $blocked]);
            }

            return $this->repair($campaign, $snapshot, [...$state, 'status' => 'repairing',
                'mutation_allowed' => true, 'blocked_reason' => null]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Search delivery watchdog could not complete', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
            // A remote write may precede a failed read-back. Keep its durable
            // attempt, so a retry cannot silently add the same keywords again.
            $state = [...$state, ...($campaign->fresh()->search_delivery_state ?? []),
                'checked_at' => now()->toIso8601String()];
            if (! empty($state['repair']['started_at'])) {
                $state['repair']['errors'][] = ['code' => 'repair_readback_unavailable',
                    'message' => 'Repair read-back could not finish. Review the live keywords before retrying.'];
            }
            $state['errors'] = [['code' => 'search_delivery_check_unavailable',
                'message' => 'We could not verify current Search delivery. A later check or administrator review is required.']];

            return $this->alertAndSave($campaign, [...$state, 'status' => 'unavailable',
                'mutation_allowed' => false, 'blocked_reason' => 'search_delivery_check_unavailable']);
        }
    }

    private function evaluationStart(Campaign $campaign, array $previous): CarbonImmutable
    {
        $trial = CampaignSpendGuardrails::activeTrial($campaign);
        if (! empty($trial['started_at'])) {
            $start = CarbonImmutable::parse($trial['started_at']);

            return empty($previous['evaluation_started_at']) ? $start : $start->max(CarbonImmutable::parse($previous['evaluation_started_at']));
        }
        if (! empty($previous['evaluation_started_at'])) {
            return CarbonImmutable::parse($previous['evaluation_started_at']);
        }
        $deployed = $campaign->strategies()->whereIn('deployment_status', ['active', 'verified', 'deployed'])
            ->whereNotNull('deployed_at')->max('deployed_at');

        return $deployed ? CarbonImmutable::parse($deployed)->max(now()->subHours(48)) : CarbonImmutable::now();
    }

    private function hasReachRisk(array $diagnosis): bool
    {
        $codes = array_column($diagnosis['issues'] ?? [], 'code');
        if (array_intersect($codes, ['insufficient_forecast_reach', 'no_active_keywords']) !== []) {
            return true;
        }

        // Low historical volume per term is advisory when the current set can
        // collectively deliver enough forecast traffic under the actual limits.
        $forecast = $diagnosis['forecast'] ?? [];

        return in_array('limited_keyword_demand', $codes, true)
            && (($forecast['success'] ?? false) !== true
                || ($forecast['clicks'] ?? 0) < (int) config('optimization.search_delivery.min_forecast_clicks', 30));
    }

    private function rankDiagnosis(array $snapshot): array
    {
        $evidence = $snapshot['rank_evidence'] ?? [];
        $rank = [...$evidence, 'status' => 'unavailable', 'checked_at' => now()->toIso8601String(),
            'evidence_checked_at' => $evidence['checked_at'] ?? null, 'issues' => [],
            'currency_code' => $snapshot['currency_code'] ?? null];
        if (($evidence['status'] ?? null) === 'collecting_evidence') {
            return [...$rank, 'status' => 'collecting_evidence', 'reason' => 'ad_rank_collecting_complete_days'];
        }
        if (($evidence['status'] ?? null) !== 'verified' || ! is_string($evidence['checked_at'] ?? null)
            || ! is_string($evidence['from'] ?? null) || ! is_string($evidence['through'] ?? null)
            || ($evidence['account_timezone'] ?? null) !== ($snapshot['time_zone'] ?? null)
            || ! in_array($evidence['account_timezone'] ?? '', timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            return [...$rank, 'reason' => 'ad_rank_evidence_unavailable'];
        }
        try {
            $checked = CarbonImmutable::parse($evidence['checked_at']);
            $timezone = $evidence['account_timezone'];
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $evidence['from'], $timezone);
            $through = CarbonImmutable::createFromFormat('!Y-m-d', $evidence['through'], $timezone);
            $measurementFrom = CarbonImmutable::parse($snapshot['measurement']['from'])->setTimezone($timezone);
            $measurementStarted = CarbonImmutable::parse($snapshot['evaluation_started_at'] ?? $snapshot['measurement']['started_at']);
            if ($measurementFrom->lt($measurementStarted)) {
                return [...$rank, 'reason' => 'ad_rank_window_unverified'];
            }
            $expectedFrom = ($measurementFrom->isStartOfDay() ? $measurementFrom : $measurementFrom->addDay())->startOfDay();
            $expectedThrough = CarbonImmutable::parse($snapshot['measurement']['through'])->setTimezone($timezone)->startOfDay()->subDay();
            if (! $from || ! $through || $from->toDateString() !== $evidence['from'] || $through->toDateString() !== $evidence['through']
                || ! $from->equalTo($expectedFrom) || ! $through->equalTo($expectedThrough) || $through->lt($from)
                || $checked->lt(now()->subHours((int) config('optimization.search_delivery.ad_rank.evidence_stale_after_hours', 3)))
                || $checked->gt(now()->addMinutes(5))) {
                return [...$rank, 'reason' => 'ad_rank_window_unverified'];
            }
            $days = (int) $from->diffInDays($through->addDay());
            if (($evidence['complete_days'] ?? null) !== $days
                || $days < (int) config('optimization.search_delivery.ad_rank.min_complete_days', 1)
                || (int) ($snapshot['measurement']['complete_hours'] ?? 0) < (int) config('optimization.search_delivery.ad_rank.min_complete_hours', 24)) {
                return [...$rank, 'status' => 'collecting_evidence', 'reason' => 'ad_rank_collecting_complete_days'];
            }
        } catch (\Throwable) {
            return [...$rank, 'reason' => 'ad_rank_window_unverified'];
        }
        foreach (['lost_to_rank', 'lost_to_budget', 'impression_share'] as $field) {
            $share = $evidence[$field] ?? null;
            if (! is_numeric($share) || ! is_finite($share) || $share < 0 || $share > 1) {
                return [...$rank, 'reason' => 'ad_rank_share_unverified'];
            }
        }
        foreach (['impressions', 'clicks', 'cost_micros'] as $field) {
            if (! is_int($evidence[$field] ?? null) || $evidence[$field] < 0) {
                return [...$rank, 'reason' => 'ad_rank_traffic_unverified'];
            }
        }
        if ($evidence['impressions'] < (int) config('optimization.search_delivery.ad_rank.min_impressions', 10)) {
            return [...$rank, 'status' => 'collecting_evidence', 'reason' => 'ad_rank_insufficient_observations'];
        }
        if ($evidence['lost_to_rank'] >= (float) config('optimization.search_delivery.ad_rank.lost_rank_threshold', 0.70)
            && $evidence['clicks'] < max(1, (int) config('optimization.search_delivery.min_meaningful_clicks', 3))) {
            return [...$rank, 'status' => 'rank_limited', 'issues' => [AgentIssue::make('sustained_ad_rank_loss',
                'Google reports sustained Search opportunity lost to Ad Rank with few clicks in the current configuration window. Improve ad relevance and assets within the existing spending limits.')->toArray()]];
        }

        return [...$rank, 'status' => 'not_rank_limited', 'reason' => null];
    }

    private function hasPendingRankRepair(Campaign $campaign): bool
    {
        // A concurrent check can save a transient lock/read failure over the
        // display state. The durable attempt still owns staging and verification.
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('customer_id', $campaign->customer_id)
            ->where('action', 'ad_rank_repair')->latest('id')->first();

        return $attempt !== null && in_array($attempt->status, ['running', 'pending'], true)
            && in_array($attempt->details['phase'] ?? '', ['preparing', 'staging_assets', 'awaiting_policy_review',
                'activating', 'observing_delivery'], true);
    }

    private function rankRepairCoolingDown(Campaign $campaign): bool
    {
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('customer_id', $campaign->customer_id)
            ->where('action', 'ad_rank_repair')->latest('id')->first();
        if (! $attempt) {
            return false;
        }
        $details = $attempt->details ?? [];
        if (! in_array($attempt->status, ['running', 'pending'], true)
            || ! in_array($details['phase'] ?? '', ['staging_assets', 'awaiting_policy_review', 'activating', 'observing_delivery'], true)
            || ($details['customer_id'] ?? null) !== $campaign->customer_id
            || ($details['google_customer_id'] ?? null) !== $campaign->customer->cleanGoogleCustomerId()
            || ($details['campaign_resource'] ?? null) !== $campaign->googleAdsResourceName()
            || ! in_array($details['quota_source'] ?? null, ['inspect', 'spend', 'ads', 'assets'], true)
            || ! is_string($details['next_check_at'] ?? null)) {
            return false;
        }
        try {
            $next = CarbonImmutable::parse($details['next_check_at']);

            return $next->isFuture() && $next->lte(now()->addHour());
        } catch (\Throwable) {
            return false;
        }
    }

    private function saveRankResult(Campaign $campaign, array $state, array $result): array
    {
        $state['ad_rank']['repair'] = $result;
        $state['mutation_allowed'] = (bool) ($result['mutation_allowed'] ?? false);
        $state['status'] = match ($result['status'] ?? 'unavailable') {
            'verifying' => 'verifying',
            'held' => 'approval_required',
            'needs_review' => 'needs_review',
            'no_op' => ($result['metadata']['rank_recovery_verified'] ?? false) ? 'recovered' : 'low_traffic',
            default => 'unavailable',
        };
        $state['blocked_reason'] = match ($state['status']) {
            'verifying' => 'ad_rank_repair_verification_pending',
            'recovered' => null,
            'approval_required' => 'ad_rank_repair_not_authorized',
            'unavailable' => 'ad_rank_repair_unavailable',
            default => 'ad_rank_limits_reached',
        };
        $reason = $result['metadata']['blocked_reason'] ?? $result['metadata']['reason']
            ?? $result['blocked_reason'] ?? $result['reason'] ?? null;
        if (is_string($reason) && $reason !== '' && $state['status'] !== 'recovered') {
            $state['blocked_reason'] = $reason;
        }
        if ($reason === 'google_api_quota_cooldown') {
            $state['mutation_allowed'] = false;
        }

        return in_array($state['status'], ['needs_review', 'unavailable', 'approval_required'], true)
            ? $this->alertAndSave($campaign, $state) : $this->save($campaign, $state);
    }

    protected function repairAdRank(Campaign $campaign, array $snapshot, array $rankDiagnosis): array
    {
        return app(GoogleAdRankRepair::class)->repair($campaign, $snapshot, $rankDiagnosis);
    }

    private function mutationBlocked(Campaign $campaign, array $snapshot, array $diagnosis, array $state): ?string
    {
        $current = $campaign->fresh(['customer']);
        if (! $current || $current->hasPassedEndDate()) {
            return 'campaign_not_active';
        }
        if (CampaignSpendGuardrails::automaticChangesSuspended($current)) {
            return $current->spend_safety_hold ? 'spend_safety_hold' : 'approved_bounded_trial';
        }
        if ($current->status !== CampaignStatus::Active) {
            return 'campaign_not_active';
        }
        if (! $current->customer || $current->customer->service_type === 'setup_only'
            || $current->customer->google_ads_link_status === 'revoked'
            || ! Feature::for($current->customer)->active(AutoHealing::class)) {
            return 'automatic_management_not_enabled';
        }
        $approved = self::moneyToMicros($current->approved_daily_budget);
        if (! $current->budget_confirmed_at || $approved <= 0 || (int) ($snapshot['daily_budget_micros'] ?? 0) > $approved) {
            return 'approved_budget_unverified';
        }
        if ((int) ($snapshot['actual_cpc_ceiling_micros'] ?? 0) <= 0) {
            return 'existing_cpc_cap_required';
        }
        if (($snapshot['google_search_enabled'] ?? false) !== true) {
            return 'search_inventory_unavailable';
        }
        if (($snapshot['approved_ads'] ?? 0) < 1) {
            return 'approved_search_ad_required';
        }
        if (! isset($snapshot['eligible_keywords'])) {
            return 'search_keyword_eligibility_unavailable';
        }
        if (empty($this->safeCandidates($snapshot, $diagnosis))) {
            return 'forecasted_relevant_keywords_required';
        }
        $attempts = array_filter($state['repair_attempts'] ?? [], fn ($at) => CarbonImmutable::parse($at)->gte(now()->subDays(7)));
        if (count($attempts) >= min(1, max(0, (int) config('optimization.search_delivery.max_attempts_per_week', 1)))) {
            return 'repair_attempt_limit';
        }

        return null;
    }

    private function servingBlocked(array $snapshot): ?string
    {
        if (($snapshot['channel'] ?? null) !== AdvertisingChannelType::SEARCH) {
            return 'not_search_campaign';
        }
        if (($snapshot['status'] ?? null) !== 2) {
            return 'google_campaign_not_enabled';
        }
        if (($snapshot['google_search_enabled'] ?? false) !== true) {
            return 'search_inventory_unavailable';
        }
        if (($snapshot['approved_ads'] ?? 0) < 1) {
            return 'approved_search_ad_required';
        }
        if (! isset($snapshot['eligible_keywords'])) {
            return 'search_keyword_eligibility_unavailable';
        }
        if ($snapshot['eligible_keywords'] < 1) {
            return 'eligible_search_keyword_required';
        }

        return null;
    }

    private function safeCandidates(array $snapshot, array $diagnosis): array
    {
        $candidates = [];
        foreach ($diagnosis['candidate_keywords'] ?? [] as $candidate) {
            if (! is_array($candidate) || ($candidate['relevant'] ?? false) !== true
                || ($candidate['negative_conflict'] ?? true) !== false || ($candidate['forecasted'] ?? false) !== true
                || ! in_array($candidate['match_type'] ?? null, ['EXACT', 'PHRASE'], true)
                || ($candidate['forecast']['success'] ?? false) !== true || ($candidate['forecast']['clicks'] ?? 0) <= 0
                || ! is_string($candidate['text'] ?? null) || trim($candidate['text']) === ''
                || ! is_string($candidate['selection_reason'] ?? null) || trim($candidate['selection_reason']) === '') {
                continue;
            }
            $adGroup = $candidate['ad_group_resource'] ?? null;
            $activeGroups = array_column(array_filter($snapshot['keywords'] ?? [],
                fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
                    && ($keyword['ad_group_status'] ?? null) === 'ENABLED'), 'ad_group_resource');
            if (! is_string($adGroup) || ! in_array($adGroup, $activeGroups, true)) {
                continue;
            }
            $duplicate = false;
            foreach ($snapshot['keywords'] ?? [] as $existing) {
                if (strtolower(trim((string) ($existing['text'] ?? ''))) === strtolower(trim($candidate['text']))) {
                    $duplicate = true;
                }
            }
            if ($duplicate || $this->negativeConflict($candidate, $snapshot['negative_keywords'] ?? [])) {
                continue;
            }
            $candidates[strtolower(trim($candidate['text']))] = $candidate;
        }

        return array_slice(array_values($candidates), 0, min(3, max(0, (int) config('optimization.search_delivery.max_keywords_per_repair', 3))));
    }

    private function negativeConflict(array $candidate, array $negatives): bool
    {
        return GoogleSearchReachPlanner::negativeConflict($candidate['text'], $negatives, $candidate['ad_group_resource']);
    }

    private function repair(Campaign $campaign, array $snapshot, array $state): array
    {
        // Research is not mutation authorization. Read live settings again after it finishes.
        $fresh = $campaign->fresh(['customer']);
        if (! $fresh) {
            throw new \RuntimeException('Campaign was removed during reach diagnosis.');
        }
        $live = $this->inspect($fresh, CarbonImmutable::parse($state['evaluation_started_at']));
        if (! $live || $this->fingerprint($fresh, $live) !== $state['config_fingerprint']) {
            return $this->alertAndSave($fresh, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'configuration_changed_during_diagnosis']);
        }
        if (($blocked = $this->mutationBlocked($fresh, $live, $state['diagnosis'], $state)) !== null) {
            return $this->alertAndSave($fresh, [...$state, 'status' => 'approval_required', 'blocked_reason' => $blocked, 'mutation_allowed' => false]);
        }
        $candidates = $this->safeCandidates($live, $state['diagnosis']);
        $proposed = $live;
        $proposed['keywords'] = [...array_values(array_filter($live['keywords'] ?? [], fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
            && ($keyword['ad_group_status'] ?? null) === 'ENABLED')),
            ...array_map(fn ($candidate) => $candidate + ['status' => 'ENABLED', 'ad_group_status' => 'ENABLED']
                + (($live['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC ? ['cpc_bid_micros' => $live['actual_cpc_ceiling_micros']] : []), $candidates)];
        $proof = $this->mutationReach($fresh, $proposed);
        $state['repair_reach_gate'] = $proof;
        if (! ($proof['reach_gate']['ready'] ?? false)) {
            return $this->alertAndSave($fresh, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'repair_reach_unverified', 'mutation_allowed' => false]);
        }
        // Forecasting may take time; new live settings or a newly approved hold invalidate this plan.
        $live = $this->inspect($fresh, CarbonImmutable::parse($state['evaluation_started_at']));
        if (! $live || $this->fingerprint($fresh, $live) !== $state['config_fingerprint']) {
            return $this->alertAndSave($fresh, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'configuration_changed_during_diagnosis']);
        }
        if (($blocked = $this->mutationBlocked($fresh, $live, $state['diagnosis'], $state)) !== null) {
            return $this->alertAndSave($fresh, [...$state, 'status' => 'approval_required', 'blocked_reason' => $blocked, 'mutation_allowed' => false]);
        }
        $state['repair_attempts'] = array_values(array_filter($state['repair_attempts'] ?? [],
            fn ($at) => CarbonImmutable::parse($at)->gte(now()->subDays(7))));
        $state['repair_attempts'][] = now()->toIso8601String();
        $state['repair'] = ['started_at' => now()->toIso8601String(), 'added_keywords' => [], 'errors' => []];
        $this->save($fresh, $state); // Durable attempt before the first remote write.
        foreach ($candidates as $candidate) {
            try {
                if (($blocked = $this->mutationBlocked($fresh, $live, $state['diagnosis'], [...$state, 'repair_attempts' => []])) !== null) {
                    throw new \RuntimeException('Campaign authorization changed: '.$blocked);
                }
                $candidate['set_keyword_bid'] = ($live['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC;
                $resource = $this->addKeyword($fresh->customer, $candidate, (int) $live['actual_cpc_ceiling_micros']);
                if (! $resource) {
                    throw new \RuntimeException('Google did not confirm the keyword creation.');
                }
                $metrics = GoogleSearchReachPlanner::historicalMetric($proof['assessment']['historical']['keywords'] ?? [], $candidate['text']);
                $state['repair']['added_keywords'][] = ['text' => $candidate['text'], 'match_type' => $candidate['match_type'],
                    'ad_group_resource' => $candidate['ad_group_resource'], 'resource' => $resource,
                    'selection_reason' => $candidate['selection_reason'], 'historical_metrics' => $metrics,
                    'historical_checked_at' => $proof['assessment']['historical']['checked_at'] ?? null];
                $this->save($fresh, $state);
            } catch (\Throwable $e) {
                report($e);
                Log::error('Search reach repair did not complete', ['campaign_id' => $fresh->id, 'error' => $e->getMessage()]);
                $state['repair']['errors'][] = ['code' => 'keyword_repair_unverified',
                    'message' => 'A keyword addition could not be verified. Review the live campaign before another repair.'];
                break;
            }
        }
        $after = $this->inspect($fresh, CarbonImmutable::parse($state['repair']['started_at']));
        if (! $after) {
            throw new \RuntimeException('Keyword repair read-back is unavailable.');
        }
        foreach ($state['repair']['added_keywords'] as $added) {
            $confirmed = array_filter($after['keywords'] ?? [], fn ($k) => ($k['resource'] ?? $k['resource_name'] ?? null) === $added['resource']
                && ($k['text'] ?? null) === $added['text'] && ($k['match_type'] ?? null) === $added['match_type']
                && in_array($k['status'] ?? null, ['ENABLED', 2], true));
            if (! $confirmed) {
                $state['repair']['errors'][] = ['code' => 'keyword_readback_mismatch', 'message' => 'Google has not confirmed the added keyword as enabled.'];
            }
        }
        $unchanged = $after;
        $addedResources = array_column($state['repair']['added_keywords'], 'resource');
        $unchanged['keywords'] = array_values(array_filter($after['keywords'] ?? [],
            fn ($keyword) => ! in_array($keyword['resource'] ?? $keyword['resource_name'] ?? null, $addedResources, true)));
        if ($this->fingerprint($fresh, $unchanged) !== $state['config_fingerprint']) {
            $state['repair']['errors'][] = ['code' => 'configuration_changed_after_repair',
                'message' => 'Other live campaign settings changed during keyword creation. Review them before declaring delivery recovered.'];
        }
        $state['repair']['readback_reach'] = $this->mutationReach($fresh, $after);
        if (! ($state['repair']['readback_reach']['reach_gate']['ready'] ?? false)) {
            $state['repair']['errors'][] = ['code' => 'repair_reach_readback_unverified',
                'message' => 'The actual repaired keyword portfolio did not pass a fresh demand and affordable reach check. Review it before another automatic repair.'];
        }
        $state['config_fingerprint'] = $this->fingerprint($fresh, $after);
        $state['mutation_allowed'] = false;
        $state['verification'] = ['started_at' => $state['repair']['started_at'], ...($after['measurement'] ?? [])];
        $state['status'] = empty($state['repair']['errors']) && $state['repair']['added_keywords'] ? 'verifying' : 'needs_review';
        AgentActivity::record('search_delivery', 'reach_repair_started',
            'Added '.count($state['repair']['added_keywords']).' forecasted relevant keyword(s); waiting for subsequent Google Search traffic.',
            $fresh->customer_id, $fresh->id, ['added_count' => count($state['repair']['added_keywords']), 'errors' => $state['repair']['errors']], $state['status']);

        return $state['status'] === 'needs_review' ? $this->alertAndSave($fresh, $state) : $this->save($fresh, $state);
    }

    private function verify(Campaign $campaign, array $snapshot, array $state): array
    {
        $repairAt = CarbonImmutable::parse($state['repair']['started_at']);
        // Original restart metrics cannot prove that newly added keywords helped.
        $after = $this->inspect($campaign, $repairAt);
        if (! $after || ! isset($after['measurement']['impressions'])) {
            throw new \RuntimeException('Post-repair delivery verification is unavailable.');
        }
        $state['verification'] = ['started_at' => $repairAt->toIso8601String(), ...$after['measurement']];
        if (! is_string($after['measurement']['from'] ?? null)
            || CarbonImmutable::parse($after['measurement']['from'])->lt($repairAt)) {
            throw new \RuntimeException('Post-repair metrics include time before the repair.');
        }
        if (! empty($state['repair']['errors'])) {
            return $this->alertAndSave($campaign, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'partial_repair_unresolved']);
        }
        if (($blocked = $this->servingBlocked($after)) !== null) {
            return $this->alertAndSave($campaign, [...$state, 'status' => 'needs_review', 'blocked_reason' => $blocked]);
        }
        if ($this->fingerprint($campaign, $after) !== $state['config_fingerprint']) {
            $state['repair']['errors'][] = ['code' => 'repair_configuration_changed',
                'message' => 'Live serving settings changed during verification. Review the current campaign before resetting this incident.'];

            return $this->alertAndSave($campaign, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'configuration_changed_during_verification']);
        }
        if ((int) $after['measurement']['impressions'] >= (int) config('optimization.search_delivery.min_impressions', 5)
            && (int) ($after['measurement']['clicks'] ?? 0) >= max(1, (int) config('optimization.search_delivery.min_meaningful_clicks', 3))) {
            if (($state['status'] ?? null) !== 'recovered') {
                AgentActivity::record('search_delivery', 'reach_recovery_verified',
                    'Google Search traffic was observed after the keyword repair.',
                    $campaign->customer_id, $campaign->id, ['measurement' => $after['measurement']]);
            }

            return $this->save($campaign, [...$state, 'status' => 'recovered', 'blocked_reason' => null]);
        }
        if ((int) ($after['measurement']['complete_hours'] ?? 0) >= (int) config('optimization.search_delivery.verify_hours', 24)) {
            return $this->alertAndSave($campaign, [...$state, 'status' => 'needs_review', 'blocked_reason' => 'repair_did_not_restore_search_traffic']);
        }

        return $this->save($campaign, [...$state, 'status' => 'verifying', 'blocked_reason' => null]);
    }

    private function fingerprint(Campaign $campaign, array $snapshot): string
    {
        $settings = array_intersect_key($snapshot, array_flip(['status', 'channel', 'bidding_strategy', 'google_search_enabled', 'search_partners_enabled',
            'daily_budget_micros', 'actual_cpc_ceiling_micros', 'currency_code', 'time_zone', 'keywords', 'approved_ads', 'approved_ad_resources',
            'negative_keywords', 'geo_target_constants', 'excluded_geo_target_constants', 'included_geos', 'excluded_geos', 'language_constants', 'languages', 'ad_schedule',
            'device_bid_modifiers', 'unsupported_targeting', 'positive_geo_target_type', 'negative_geo_target_type', 'targeting_setting',
            'ad_group_targeting_modes', 'audience_criteria']));
        $settings['campaign_resource'] = $campaign->googleAdsResourceName();
        $settings['trial_started_at'] = CampaignSpendGuardrails::activeTrial($campaign)['started_at'] ?? null;
        $settings['keywords'] = array_map(fn ($keyword) => array_intersect_key($keyword,
            array_flip(['resource', 'resource_name', 'text', 'match_type', 'ad_group_resource', 'ad_group_status', 'status', 'cpc_bid_micros'])),
            $snapshot['keywords'] ?? []);
        $sort = function (array $data) use (&$sort): array {
            foreach ($data as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }
            unset($value);
            if (array_is_list($data)) {
                usort($data, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            } else {
                ksort($data);
            }

            return $data;
        };

        return hash('sha256', json_encode($sort($settings), JSON_THROW_ON_ERROR));
    }

    private function save(Campaign $campaign, array $state): array
    {
        $campaign->update(['search_delivery_state' => $state]);

        return $state;
    }

    private function alertAndSave(Campaign $campaign, array $state): array
    {
        $state['mutation_allowed'] = false;
        $this->save($campaign, $state); // Diagnosis survives an email/queue outage.
        $key = hash('sha256', ($state['config_fingerprint'] ?? 'unknown').'|'.($state['evaluation_started_at'] ?? '').'|'.($state['blocked_reason'] ?? $state['status']));
        if (($state['alert_key'] ?? null) !== $key || empty($state['last_alert_at'])
            || CarbonImmutable::parse($state['last_alert_at'])->lte(now()->subHours(24))) {
            $content = GoogleSearchDeliveryAlertContent::forState($state);
            $message = $content['message'];
            try {
                CriticalAgentAlert::deliver('search_reach_watchdog', 'Search delivery needs review: '.$campaign->name,
                    $message, ['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id,
                        'campaign_name' => $campaign->name, 'severity' => 'warning', 'dedupe_key' => $key,
                        ...$content, 'action_url' => route('campaigns.show', $campaign)],
                    CriticalAgentAlert::RECIPIENTS_BOTH, $campaign->customer);
                AgentActivity::record('search_delivery', 'reach_needs_review', $message,
                    $campaign->customer_id, $campaign->id, ['blocked_reason' => $state['blocked_reason'] ?? null,
                        'issues' => $state['diagnosis']['issues'] ?? []], $state['status']);
                $state['alert_key'] = $key;
                $state['last_alert_at'] = now()->toIso8601String();
            } catch (\Throwable $e) {
                report($e);
                Log::error('Search delivery alert could not be delivered', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
                $state['errors'][] = ['code' => 'search_delivery_alert_unavailable',
                    'message' => 'The diagnosis was saved, but its notification could not be delivered.'];
            }
        }

        return $this->save($campaign, $state);
    }

    private static function moneyToMicros(mixed $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100 + (int) str_pad($fraction, 2, '0')) * 10_000;
    }

    protected function inspect(Campaign $campaign, \DateTimeInterface $since): ?array
    {
        return app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign, $since);
    }

    private function mutationReach(Campaign $campaign, array $snapshot): array
    {
        $keywords = array_values(array_filter($snapshot['keywords'] ?? [], fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
            && ($keyword['ad_group_status'] ?? null) === 'ENABLED'));
        $assessment = $this->planner->assessMutation($campaign, $snapshot, $keywords);

        return ['assessment' => $assessment, 'reach_gate' => (new GoogleSearchReachGate)->evaluate($campaign, $snapshot, $assessment)];
    }

    protected function addKeyword(Customer $customer, array $candidate, int $cap): ?string
    {
        $service = new class($customer) extends BaseGoogleAdsService
        {
            public function add(array $candidate, int $cap): ?string
            {
                $this->ensureClient();
                $criterion = new AdGroupCriterion(['ad_group' => $candidate['ad_group_resource'],
                    'keyword' => new KeywordInfo(['text' => $candidate['text'],
                        'match_type' => $candidate['match_type'] === 'EXACT' ? KeywordMatchType::EXACT : KeywordMatchType::PHRASE]),
                    'status' => 2]);
                if ($candidate['set_keyword_bid'] ?? false) {
                    $criterion->setCpcBidMicros($cap);
                }
                $operation = new AdGroupCriterionOperation;
                $operation->setCreate($criterion);
                $api = $this->client->getAdGroupCriterionServiceClient();
                $customerId = $this->customer?->cleanGoogleCustomerId();
                if (! $customerId) {
                    throw new \RuntimeException('Keyword mutation requires a customer account.');
                }
                $request = new MutateAdGroupCriteriaRequest(['customer_id' => $customerId,
                    'operations' => [$operation], 'validate_only' => true]);
                $api->mutateAdGroupCriteria($request);
                $request->setValidateOnly(false);
                $result = $api->mutateAdGroupCriteria($request);

                return count($result->getResults()) > 0 ? $result->getResults()[0]->getResourceName() : null;
            }
        };

        return $service->add($candidate, $cap);
    }
}
