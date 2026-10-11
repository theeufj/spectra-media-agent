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
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
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
                'measurement' => $measurement, 'currency_code' => $snapshot['currency_code'] ?? null];
            if (! empty($state['repair']['started_at']) && ! $campaign->spend_safety_hold) {
                return $this->verify($campaign, $snapshot, $state);
            }
            $mature = (int) $measurement['complete_hours'] >= (int) config('optimization.search_delivery.diagnosis_after_hours', 12);
            $enoughTraffic = (int) $measurement['impressions'] >= (int) config('optimization.search_delivery.min_impressions', 5);
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
                unset($state['alert_key'], $state['last_alert_at']);

                return $this->save($campaign, [...$state, 'status' => 'delivering', 'blocked_reason' => null]);
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
            $blocked = $this->mutationBlocked($campaign, $snapshot, $diagnosis, $state);
            if ($blocked !== null) {
                $state['diagnosis']['proposal']['blocked_reason'] = $blocked;
                $explanation = match ($blocked) {
                    'search_inventory_unavailable' => 'Google Search is disabled for this campaign. Keyword additions cannot restore traffic until its network settings are reviewed.',
                    'approved_search_ad_required' => 'No enabled, approved Search ad is available. Review ad approval before adding keywords.',
                    default => str_replace('_', ' ', $blocked),
                };
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
        if (($diagnosis['auto_repair_safe'] ?? false) !== true || empty($this->safeCandidates($snapshot, $diagnosis))) {
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
                || ! is_string($candidate['text'] ?? null) || trim($candidate['text']) === '') {
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
                $state['repair']['added_keywords'][] = ['text' => $candidate['text'], 'match_type' => $candidate['match_type'],
                    'ad_group_resource' => $candidate['ad_group_resource'], 'resource' => $resource];
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
        if ((int) $after['measurement']['impressions'] > 0) {
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
            'daily_budget_micros', 'actual_cpc_ceiling_micros', 'currency_code', 'time_zone', 'keywords', 'approved_ads',
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
            $summary = $state['diagnosis']['proposal']['summary'] ?? 'Review keyword demand, the approved CPC cap and campaign targeting.';
            $message = 'Search delivery needs review. '.$summary.' Automatic changes are held: '.str_replace('_', ' ', $state['blocked_reason'] ?? 'owner review required').'.';
            try {
                CriticalAgentAlert::deliver('search_reach_watchdog', 'Search delivery needs review: '.$campaign->name,
                    $message, ['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id,
                        'campaign_name' => $campaign->name, 'severity' => 'warning', 'dedupe_key' => $key,
                        'action_required' => $summary, 'action_url' => route('campaigns.show', $campaign)],
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
