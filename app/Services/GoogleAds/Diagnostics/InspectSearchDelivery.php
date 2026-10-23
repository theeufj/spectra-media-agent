<?php

namespace App\Services\GoogleAds\Diagnostics;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Google\Ads\GoogleAds\V26\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupCriterionPrimaryStatusEnum\AdGroupCriterionPrimaryStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupCriterionPrimaryStatusReasonEnum\AdGroupCriterionPrimaryStatusReason;
use Google\Ads\GoogleAds\V26\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V26\Enums\AdNetworkTypeEnum\AdNetworkType;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignCriterionStatusEnum\CampaignCriterionStatus;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\CriterionTypeEnum\CriterionType;
use Google\Ads\GoogleAds\V26\Enums\DayOfWeekEnum\DayOfWeek;
use Google\Ads\GoogleAds\V26\Enums\DeviceEnum\Device;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Enums\MinuteOfHourEnum\MinuteOfHour;
use Google\Ads\GoogleAds\V26\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V26\Enums\PositiveGeoTargetTypeEnum\PositiveGeoTargetType;
use Google\Ads\GoogleAds\V26\Enums\TargetingDimensionEnum\TargetingDimension;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;

/** Read-only, network-specific evidence for a Google Search delivery incident. */
class InspectSearchDelivery extends BaseGoogleAdsService
{
    public function inspect(Campaign $campaign, ?DateTimeInterface $since = null): ?array
    {
        $customerId = $campaign->customer?->cleanGoogleCustomerId();
        $campaignId = $campaign->googleCampaignNumericId();
        if (! $customerId || ! $campaignId) {
            return null;
        }

        $settings = null;
        $query = "SELECT customer.time_zone, customer.currency_code, campaign.status, campaign.start_date_time, campaign.end_date_time,
                         campaign.advertising_channel_type, campaign.bidding_strategy_type,
                         campaign.maximize_conversions.target_cpa_micros, campaign.target_spend.cpc_bid_ceiling_micros,
                         campaign.bidding_strategy, campaign.geo_target_type_setting.positive_geo_target_type,
                         campaign.network_settings.target_google_search, campaign.network_settings.target_search_network,
                         campaign.network_settings.target_content_network, campaign_budget.amount_micros
                  FROM campaign WHERE campaign.id = {$campaignId}";
        foreach ($this->rows($customerId, $query) as $row) {
            $value = $row->getCampaign();
            $ceiling = (int) ($value->getTargetSpend()?->getCpcBidCeilingMicros() ?? 0);
            $settings = [
                'time_zone' => $row->getCustomer()->getTimeZone() ?: ($campaign->customer->timezone ?: config('app.timezone', 'UTC')),
                'account_time_zone_verified' => in_array($row->getCustomer()->getTimeZone(), timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true),
                'currency_code' => $row->getCustomer()->getCurrencyCode(),
                'status' => $value->getStatus(),
                'start_date' => CampaignDateTimes::calendarDate($value->getStartDateTime()),
                'start_date_time' => $value->getStartDateTime(),
                'end_date' => CampaignDateTimes::calendarDate($value->getEndDateTime()),
                'end_date_time' => $value->getEndDateTime(),
                'channel' => $value->getAdvertisingChannelType(),
                'bidding_strategy' => $value->getBiddingStrategyType(),
                'target_cpa_micros' => (int) ($value->getMaximizeConversions()?->getTargetCpaMicros() ?? 0),
                'cpc_bid_ceiling_micros' => $ceiling,
                'actual_cpc_ceiling_micros' => $ceiling,
                'portfolio_bidding_strategy' => $value->getBiddingStrategy(),
                'positive_geo_target_type' => $value->getGeoTargetTypeSetting()?->getPositiveGeoTargetType(),
                'google_search_enabled' => $value->getNetworkSettings()?->getTargetGoogleSearch(),
                'search_partners_enabled' => $value->getNetworkSettings()?->getTargetSearchNetwork(),
                'display_enabled' => $value->getNetworkSettings()?->getTargetContentNetwork(),
                'daily_budget_micros' => (int) ($row->getCampaignBudget()?->getAmountMicros() ?? 0),
            ];
            break;
        }

        if (! $settings || $settings['channel'] !== AdvertisingChannelType::SEARCH) {
            return null;
        }

        // Retain the legacy two-day snapshot for existing diagnostics. It is not
        // evidence that a newly restarted/configured campaign delivered traffic.
        $localToday = CarbonImmutable::now($settings['time_zone'])->startOfDay();
        $completeDays = [$localToday->subDays(2)->toDateString(), $localToday->subDay()->toDateString()];
        $network = ['google_search' => 0, 'search_partners' => 0, 'display' => 0];
        $query = "SELECT segments.date, segments.ad_network_type, metrics.impressions
                  FROM campaign WHERE campaign.id = {$campaignId}
                  AND segments.date BETWEEN '{$completeDays[0]}' AND '{$completeDays[1]}'";
        foreach ($this->rows($customerId, $query) as $row) {
            if (! in_array($row->getSegments()->getDate(), $completeDays, true)) {
                continue;
            }
            $key = match ($row->getSegments()->getAdNetworkType()) {
                AdNetworkType::SEARCH => 'google_search',
                AdNetworkType::SEARCH_PARTNERS => 'search_partners',
                AdNetworkType::CONTENT => 'display',
                default => null,
            };
            if ($key !== null) {
                $network[$key] += (int) $row->getMetrics()?->getImpressions();
            }
        }

        $auction = ['impression_share' => null, 'lost_to_rank' => null, 'lost_to_budget' => null,
            'conversions' => 0.0, 'cost_micros' => 0, 'clicks' => 0];
        $query = "SELECT metrics.search_impression_share, metrics.search_rank_lost_impression_share,
                         metrics.search_budget_lost_impression_share, metrics.conversions, metrics.cost_micros, metrics.clicks
                  FROM campaign WHERE campaign.id = {$campaignId} AND segments.date DURING LAST_7_DAYS";
        foreach ($this->rows($customerId, $query) as $row) {
            $metrics = $row->getMetrics();
            $auction = [
                'impression_share' => $metrics->hasSearchImpressionShare() ? $metrics->getSearchImpressionShare() : null,
                'lost_to_rank' => $metrics->hasSearchRankLostImpressionShare() ? $metrics->getSearchRankLostImpressionShare() : null,
                'lost_to_budget' => $metrics->hasSearchBudgetLostImpressionShare() ? $metrics->getSearchBudgetLostImpressionShare() : null,
                'conversions' => $metrics->getConversions(),
                'cost_micros' => (int) $metrics->getCostMicros(),
                'clicks' => (int) $metrics->getClicks(),
            ];
            break;
        }

        $keywords = [];
        $negativeKeywords = [];
        $eligibleKeywords = 0;
        $query = "SELECT ad_group.resource_name, ad_group.status, ad_group.cpc_bid_micros, ad_group_criterion.resource_name,
                         ad_group_criterion.status, ad_group_criterion.negative, ad_group_criterion.keyword.text,
                         ad_group_criterion.keyword.match_type, ad_group_criterion.primary_status,
                         ad_group_criterion.primary_status_reasons, ad_group_criterion.cpc_bid_micros
                  FROM ad_group_criterion WHERE campaign.id = {$campaignId}
                  AND ad_group_criterion.type = KEYWORD AND ad_group_criterion.status != REMOVED";
        foreach ($this->rows($customerId, $query) as $row) {
            $criterion = $row->getAdGroupCriterion();
            $keyword = [
                'resource_name' => $criterion->getResourceName(),
                'ad_group_resource' => $row->getAdGroup()->getResourceName(),
                'text' => $criterion->getKeyword()->getText(),
                'match_type' => KeywordMatchType::name($criterion->getKeyword()->getMatchType()),
            ];
            if ($criterion->getNegative()) {
                if ($criterion->getStatus() === AdGroupCriterionStatus::ENABLED && $row->getAdGroup()->getStatus() === AdGroupStatus::ENABLED) {
                    $negativeKeywords[] = $keyword + ['scope' => 'ad_group'];
                }

                continue;
            }
            $keywords[] = $keyword + [
                'status' => AdGroupCriterionStatus::name($criterion->getStatus()),
                'ad_group_status' => AdGroupStatus::name($row->getAdGroup()->getStatus()),
                'primary_status' => AdGroupCriterionPrimaryStatus::name($criterion->getPrimaryStatus()),
                'primary_status_reasons' => array_map(
                    fn ($reason) => AdGroupCriterionPrimaryStatusReason::name($reason),
                    iterator_to_array($criterion->getPrimaryStatusReasons())
                ),
                'cpc_bid_micros' => (int) ($criterion->getCpcBidMicros() ?: $row->getAdGroup()->getCpcBidMicros()),
                'keyword_cpc_bid_micros' => (int) $criterion->getCpcBidMicros(),
                'ad_group_cpc_bid_micros' => (int) $row->getAdGroup()->getCpcBidMicros(),
            ];
            if ($criterion->getStatus() === AdGroupCriterionStatus::ENABLED
                && $criterion->getPrimaryStatus() === AdGroupCriterionPrimaryStatus::ELIGIBLE
                && $row->getAdGroup()->getStatus() === AdGroupStatus::ENABLED) {
                $eligibleKeywords++;
            }
        }
        usort($keywords, fn ($a, $b) => strcmp($a['resource_name'], $b['resource_name']));
        if ($settings['bidding_strategy'] === BiddingStrategyType::MANUAL_CPC) {
            $bids = array_column(array_filter($keywords, fn ($keyword) => $keyword['status'] === 'ENABLED'
                && $keyword['ad_group_status'] === 'ENABLED'), 'cpc_bid_micros');
            $settings['actual_cpc_ceiling_micros'] = $settings['cpc_bid_ceiling_micros'] = $bids === [] ? 0 : max($bids);
        }

        $approvedAds = 0;
        $approvedAdResources = [];
        $query = "SELECT ad_group.status, ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.policy_summary.approval_status
                  FROM ad_group_ad WHERE campaign.id = {$campaignId} AND ad_group_ad.status != REMOVED";
        foreach ($this->rows($customerId, $query) as $row) {
            $ad = $row->getAdGroupAd();
            if ($ad->getStatus() === AdGroupAdStatus::ENABLED
                && $ad->getPolicySummary()?->getApprovalStatus() === PolicyApprovalStatus::APPROVED
                && $row->getAdGroup()->getStatus() === AdGroupStatus::ENABLED) {
                $approvedAds++;
                if ($ad->getResourceName() !== '') {
                    $approvedAdResources[] = $ad->getResourceName();
                }
            }
        }

        $geos = [];
        $excludedGeos = [];
        $languages = [];
        $schedule = [];
        $deviceModifiers = [];
        $unsupported = [];
        if ($settings['portfolio_bidding_strategy'] !== '') {
            $unsupported[] = ['type' => 'portfolio_bidding_strategy', 'resource_name' => $settings['portfolio_bidding_strategy']];
        }
        if ($settings['positive_geo_target_type'] !== PositiveGeoTargetType::PRESENCE) {
            $unsupported[] = ['type' => 'geo_presence_or_interest', 'value' => $settings['positive_geo_target_type']];
        }
        $query = "SELECT campaign_criterion.type, campaign_criterion.negative, campaign_criterion.status,
                         campaign_criterion.bid_modifier, campaign_criterion.device.type,
                         campaign_criterion.location.geo_target_constant, campaign_criterion.language.language_constant,
                         campaign_criterion.keyword.text, campaign_criterion.keyword.match_type,
                         campaign_criterion.ad_schedule.day_of_week, campaign_criterion.ad_schedule.start_hour,
                         campaign_criterion.ad_schedule.start_minute, campaign_criterion.ad_schedule.end_hour,
                         campaign_criterion.ad_schedule.end_minute
                  FROM campaign_criterion WHERE campaign.id = {$campaignId}
                  AND campaign_criterion.status = ENABLED";
        foreach ($this->rows($customerId, $query) as $row) {
            $criterion = $row->getCampaignCriterion();
            if ($criterion->getStatus() !== CampaignCriterionStatus::ENABLED) {
                continue;
            }
            if ($criterion->getType() === CriterionType::DEVICE) {
                $deviceModifiers[] = ['scope' => 'campaign', 'device' => Device::name($criterion->getDevice()->getType()),
                    'bid_modifier' => $criterion->hasBidModifier() ? $criterion->getBidModifier() : 1.0];
            } elseif (! in_array($criterion->getType(), [CriterionType::LOCATION, CriterionType::LANGUAGE, CriterionType::KEYWORD, CriterionType::AD_SCHEDULE], true)) {
                $unsupported[] = ['scope' => 'campaign', 'type' => CriterionType::name($criterion->getType()), 'negative' => $criterion->getNegative()];
            }
            if ($criterion->getType() !== CriterionType::DEVICE && $criterion->hasBidModifier() && $criterion->getBidModifier() !== 1.0) {
                $unsupported[] = ['scope' => 'campaign', 'type' => 'criterion_bid_modifier',
                    'criterion_type' => CriterionType::name($criterion->getType()), 'bid_modifier' => $criterion->getBidModifier()];
            }
            if ($criterion->getType() === CriterionType::LOCATION) {
                if ($criterion->getNegative()) {
                    $excludedGeos[] = $criterion->getLocation()->getGeoTargetConstant();
                } else {
                    $geos[] = $criterion->getLocation()->getGeoTargetConstant();
                }
            } elseif ($criterion->getType() === CriterionType::LANGUAGE && ! $criterion->getNegative()) {
                $languages[] = $criterion->getLanguage()->getLanguageConstant();
            } elseif ($criterion->getType() === CriterionType::KEYWORD && $criterion->getNegative()) {
                $negativeKeywords[] = ['scope' => 'campaign', 'text' => $criterion->getKeyword()->getText(),
                    'match_type' => KeywordMatchType::name($criterion->getKeyword()->getMatchType())];
            } elseif ($criterion->getType() === CriterionType::AD_SCHEDULE && ! $criterion->getNegative()) {
                $value = $criterion->getAdSchedule();
                $schedule[] = ['day_of_week' => DayOfWeek::name($value->getDayOfWeek()),
                    'start_hour' => $value->getStartHour(), 'start_minute' => MinuteOfHour::name($value->getStartMinute()),
                    'end_hour' => $value->getEndHour(), 'end_minute' => MinuteOfHour::name($value->getEndMinute())];
            }
        }
        $targetingModes = [];
        $query = "SELECT ad_group.resource_name, ad_group.targeting_setting.target_restrictions
                  FROM ad_group WHERE campaign.id = {$campaignId}";
        foreach ($this->rows($customerId, $query) as $row) {
            $group = $row->getAdGroup();
            $restrictions = [];
            foreach ($group->getTargetingSetting()?->getTargetRestrictions() ?? [] as $restriction) {
                $restrictions[TargetingDimension::name($restriction->getTargetingDimension())] = $restriction->hasBidOnly() ? $restriction->getBidOnly() : null;
            }
            ksort($restrictions);
            $targetingModes[$group->getResourceName()] = $restrictions;
        }
        ksort($targetingModes);
        $audienceCriteria = [];
        $query = "SELECT ad_group.resource_name, ad_group.status, ad_group_criterion.resource_name,
                         ad_group_criterion.type, ad_group_criterion.negative, ad_group_criterion.bid_modifier
                  FROM ad_group_criterion WHERE campaign.id = {$campaignId}
                  AND ad_group_criterion.type != KEYWORD AND ad_group_criterion.status != REMOVED";
        foreach ($this->rows($customerId, $query) as $row) {
            if ($row->getAdGroup()->getStatus() !== AdGroupStatus::ENABLED) {
                continue;
            }
            $criterion = $row->getAdGroupCriterion();
            $knownObservation = ($targetingModes[$row->getAdGroup()->getResourceName()]['AUDIENCE'] ?? null) === true;
            if ($criterion->getType() === CriterionType::USER_INTEREST) {
                $audienceCriteria[] = ['resource_name' => $criterion->getResourceName(),
                    'ad_group_resource' => $row->getAdGroup()->getResourceName(), 'type' => 'USER_INTEREST',
                    'observation_only' => $knownObservation, 'negative' => $criterion->getNegative(),
                    'bid_modifier' => $criterion->hasBidModifier() ? $criterion->getBidModifier() : null];
                // Explicit AUDIENCE observation is not an eligibility restriction.
                // Exclusions, bid changes and unknown/default mode remain unsafe.
                if ($knownObservation && ! $criterion->getNegative()
                    && (! $criterion->hasBidModifier() || $criterion->getBidModifier() === 1.0)) {
                    continue;
                }
            }
            $unsupported[] = ['scope' => 'ad_group', 'type' => CriterionType::name($criterion->getType()),
                'resource_name' => $criterion->getResourceName(), 'negative' => $criterion->getNegative(),
                'bid_modifier' => $criterion->hasBidModifier() ? $criterion->getBidModifier() : null];
        }
        $query = "SELECT ad_group.resource_name, ad_group.status, ad_group_bid_modifier.resource_name,
                         ad_group_bid_modifier.device.type, ad_group_bid_modifier.bid_modifier
                  FROM ad_group_bid_modifier WHERE campaign.id = {$campaignId}";
        foreach ($this->rows($customerId, $query) as $row) {
            if ($row->getAdGroup()->getStatus() !== AdGroupStatus::ENABLED) {
                continue;
            }
            $modifier = $row->getAdGroupBidModifier();
            if ($modifier->getDevice()) {
                $deviceModifiers[] = ['scope' => 'ad_group', 'ad_group_resource' => $row->getAdGroup()->getResourceName(),
                    'device' => Device::name($modifier->getDevice()->getType()),
                    'bid_modifier' => $modifier->hasBidModifier() ? $modifier->getBidModifier() : 1.0,
                    'explicit' => $modifier->hasBidModifier()];
            } else {
                $unsupported[] = ['scope' => 'ad_group', 'type' => 'bid_modifier', 'resource_name' => $modifier->getResourceName(),
                    'bid_modifier' => $modifier->getBidModifier()];
            }
        }
        // The v26 forecast cannot model keyword negatives or shared-list
        // references. Preserve this uncertainty instead of omitting exclusions.
        $query = "SELECT campaign_shared_set.shared_set, shared_set.type
                  FROM campaign_shared_set WHERE campaign.id = {$campaignId}
                  AND campaign_shared_set.status = ENABLED AND shared_set.type = NEGATIVE_KEYWORDS";
        foreach ($this->rows($customerId, $query) as $row) {
            $unsupported[] = ['type' => 'shared_negative_keyword_list', 'resource_name' => $row->getCampaignSharedSet()->getSharedSet()];
        }
        if ($settings['bidding_strategy'] === BiddingStrategyType::MANUAL_CPC) {
            foreach ($keywords as $keyword) {
                if ($keyword['status'] === 'ENABLED' && $keyword['ad_group_status'] === 'ENABLED' && $keyword['cpc_bid_micros'] <= 0) {
                    $unsupported[] = ['type' => 'manual_keyword_bid_unavailable', 'resource_name' => $keyword['resource_name']];
                }
            }
        }
        sort($geos);
        sort($excludedGeos);
        sort($languages);
        usort($negativeKeywords, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        usort($schedule, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        usort($deviceModifiers, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        usort($unsupported, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        usort($audienceCriteria, fn ($a, $b) => strcmp($a['resource_name'], $b['resource_name']));
        $startedAt = $this->evaluationStartedAt($campaign, $since);
        if (\App\Services\Campaigns\CampaignSpendGuardrails::activeTrial($campaign)) {
            $query = "SELECT metrics.cost_micros FROM campaign WHERE campaign.id = {$campaignId}";
            foreach ($this->rows($customerId, $query) as $row) {
                $settings['reported_lifetime_cost_micros'] = (int) $row->getMetrics()->getCostMicros();
                $settings['reported_spend_checked_at'] = now()->toIso8601String();
                break;
            }
        }

        $measurement = $this->measurement($customerId, $campaignId, $startedAt, $settings['time_zone']);
        sort($approvedAdResources);

        return $settings + [
            'complete_days' => $completeDays,
            'impressions' => $network,
            'auction' => $auction,
            'eligible_keywords' => $eligibleKeywords,
            'approved_ads' => $approvedAds,
            'approved_ad_resources' => $approvedAdResources,
            'keywords' => $keywords,
            'geo_target_constants' => array_values(array_unique($geos)),
            'excluded_geo_target_constants' => array_values(array_unique($excludedGeos)),
            'language_constants' => array_values(array_unique($languages)),
            'negative_keywords' => $negativeKeywords,
            'ad_schedule' => $schedule,
            'device_bid_modifiers' => $deviceModifiers,
            'unsupported_targeting' => $unsupported,
            'ad_group_targeting_modes' => $targetingModes,
            'audience_criteria' => $audienceCriteria,
            'evaluation_started_at' => $startedAt?->toIso8601String(),
            'measurement' => $measurement,
            'rank_evidence' => $settings['account_time_zone_verified']
                ? $this->rankEvidence($customerId, $campaignId, $measurement, $settings['time_zone'])
                : ['status' => 'unavailable', 'reason' => 'google_account_timezone_unverified', 'checked_at' => now()->toIso8601String()],
        ];
    }

    /** @return iterable<GoogleAdsRow> */
    protected function rows(string $customerId, string $query): iterable
    {
        $this->ensureClient();

        return $this->searchQuery($customerId, $query)->iterateAllElements();
    }

    protected function deploymentStartedAt(Campaign $campaign): mixed
    {
        return $campaign->strategies()->whereIn('deployment_status', Strategy::DEPLOYED_STATUSES)
            ->whereNotNull('deployed_at')->where('deployed_at', '<=', now())->max('deployed_at');
    }

    protected function evaluationStartedAt(Campaign $campaign, ?DateTimeInterface $since): ?CarbonImmutable
    {
        $values = [$since, $campaign->spend_guardrails['started_at'] ?? null,
            $campaign->last_bidding_changed_at, $campaign->last_budget_changed_at, $this->deploymentStartedAt($campaign)];
        $latest = null;
        foreach ($values as $value) {
            if (! $value instanceof DateTimeInterface && (! is_string($value) || trim($value) === '')) {
                continue;
            }
            try {
                $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);
                if ($date->lessThanOrEqualTo(CarbonImmutable::now()) && (! $latest || $date->greaterThan($latest))) {
                    $latest = $date;
                }
            } catch (\Throwable) {
                // Invalid/future baselines cannot hide a real delivery incident.
            }
        }

        return $latest;
    }

    private function measurement(string $customerId, string $campaignId, ?CarbonImmutable $startedAt, string $timezone): array
    {
        $lag = max(0, (int) config('optimization.search_delivery.reporting_lag_hours', 3));
        $through = CarbonImmutable::now($timezone)->subHours($lag)->startOfHour();
        $from = $startedAt?->setTimezone($timezone);
        if ($from && ! $from->equalTo($from->startOfHour())) {
            $from = $from->addHour()->startOfHour();
        }
        // Keep watching for a later stop: a few impressions after restart cannot
        // certify delivery forever. The original start remains in the snapshot.
        $windowHours = max(1, (int) config('optimization.search_delivery.measurement_window_hours', 48));
        $windowStart = $through->subHours($windowHours);
        if ($from && $from->lessThan($windowStart)) {
            $from = $windowStart;
        }
        $hours = $from ? max(0, (int) (($through->getTimestamp() - $from->getTimestamp()) / 3600)) : 0;
        $measurement = ['started_at' => $startedAt?->toIso8601String(), 'from' => $from?->toIso8601String(),
            'through' => $through->toIso8601String(), 'complete_hours' => $hours,
            'reporting_lag_hours' => $lag, 'impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0];
        if (! $from || $hours === 0) {
            return $measurement;
        }

        // Hour/date segments are account-local. Filter whole hours in PHP as well
        // as narrowing the GAQL dates, excluding both the partial restart hour
        // and hours whose metrics may still be catching up at Google.
        $query = "SELECT segments.date, segments.hour, segments.ad_network_type,
                         metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions
                  FROM campaign WHERE campaign.id = {$campaignId}
                  AND segments.date BETWEEN '{$from->toDateString()}' AND '{$through->toDateString()}'";
        foreach ($this->rows($customerId, $query) as $row) {
            $segments = $row->getSegments();
            if ($segments->getAdNetworkType() !== AdNetworkType::SEARCH) {
                continue;
            }
            $hour = CarbonImmutable::parse($segments->getDate(), $timezone)->startOfDay()->setHour($segments->getHour());
            if ($hour->lessThan($from) || $hour->greaterThanOrEqualTo($through)) {
                continue;
            }
            $metrics = $row->getMetrics();
            $measurement['impressions'] += (int) $metrics->getImpressions();
            $measurement['clicks'] += (int) $metrics->getClicks();
            $measurement['cost_micros'] += (int) $metrics->getCostMicros();
            $measurement['conversions'] += $metrics->getConversions();
        }

        return $measurement;
    }

    /** Shares are Google aggregates, not averages of ratios or hourly estimates. */
    private function rankEvidence(string $customerId, string $campaignId, array $measurement, string $timezone): array
    {
        $evidence = ['status' => 'unavailable', 'checked_at' => now()->toIso8601String(), 'account_timezone' => $timezone,
            'from' => null, 'through' => null, 'complete_days' => 0, 'impression_share' => null, 'lost_to_rank' => null,
            'lost_to_budget' => null, 'impressions' => null, 'clicks' => null, 'cost_micros' => null,
            'source' => 'google_search_share_metrics', 'note' => 'Google impression-share estimates can lag. They describe auction opportunity, not guaranteed traffic.'];
        if (! is_string($measurement['from'] ?? null) || ! is_string($measurement['through'] ?? null)) {
            return $evidence;
        }
        $from = CarbonImmutable::parse($measurement['from'])->setTimezone($timezone);
        $from = ($from->isStartOfDay() ? $from : $from->addDay())->startOfDay();
        $through = CarbonImmutable::parse($measurement['through'])->setTimezone($timezone)->startOfDay()->subDay();
        $evidence['from'] = $from->toDateString();
        $evidence['through'] = $through->toDateString();
        if ($from->gt($through)) {
            $evidence['status'] = 'collecting_evidence';

            return $evidence;
        }
        $evidence['complete_days'] = (int) $from->diffInDays($through->addDay());
        $query = "SELECT metrics.search_impression_share, metrics.search_rank_lost_impression_share,
                         metrics.search_budget_lost_impression_share, metrics.impressions, metrics.clicks, metrics.cost_micros
                  FROM campaign WHERE campaign.id = {$campaignId}
                  AND segments.date BETWEEN '{$from->toDateString()}' AND '{$through->toDateString()}'";
        foreach ($this->rows($customerId, $query) as $row) {
            $metrics = $row->getMetrics();
            $evidence['impression_share'] = $metrics->hasSearchImpressionShare() ? $metrics->getSearchImpressionShare() : null;
            $evidence['lost_to_rank'] = $metrics->hasSearchRankLostImpressionShare() ? $metrics->getSearchRankLostImpressionShare() : null;
            $evidence['lost_to_budget'] = $metrics->hasSearchBudgetLostImpressionShare() ? $metrics->getSearchBudgetLostImpressionShare() : null;
            $evidence['impressions'] = $metrics->hasImpressions() ? (int) $metrics->getImpressions() : null;
            $evidence['clicks'] = $metrics->hasClicks() ? (int) $metrics->getClicks() : null;
            $evidence['cost_micros'] = $metrics->hasCostMicros() ? (int) $metrics->getCostMicros() : null;
            $shares = [$evidence['impression_share'], $evidence['lost_to_rank'], $evidence['lost_to_budget']];
            if (count(array_filter($shares, fn ($share) => is_numeric($share) && is_finite($share) && $share >= 0 && $share <= 1)) === 3
                && is_int($evidence['impressions']) && is_int($evidence['clicks']) && is_int($evidence['cost_micros'])
                && min($evidence['impressions'], $evidence['clicks'], $evidence['cost_micros']) >= 0) {
                $evidence['status'] = 'verified';
            }
            break;
        }

        return $evidence;
    }

    public static function isStalled(?array $snapshot, ?DateTimeInterface $deployedAt, ?DateTimeInterface $now = null): bool
    {
        if (! $snapshot || ! $snapshot['google_search_enabled']
            || ($snapshot['status'] ?? null) !== CampaignStatus::ENABLED) {
            return false;
        }

        if (array_key_exists('measurement', $snapshot)) {
            // Old impressions must never certify delivery after a restart.
            return ($snapshot['measurement']['complete_hours'] ?? 0) >= 48
                && ($snapshot['measurement']['impressions'] ?? 0) === 0;
        }
        if (! $deployedAt) {
            return false;
        }
        $now ??= now();
        if ($deployedAt->getTimestamp() > $now->getTimestamp() - 48 * 3600) {
            return false;
        }

        $firstCompleteDay = $snapshot['complete_days'][0] ?? null;
        if ($firstCompleteDay && $deployedAt->getTimestamp() > CarbonImmutable::parse(
            $firstCompleteDay, $snapshot['time_zone'] ?? 'UTC'
        )->startOfDay()->getTimestamp()) {
            return false;
        }

        return ($snapshot['impressions']['google_search'] ?? 0) === 0;
    }
}
