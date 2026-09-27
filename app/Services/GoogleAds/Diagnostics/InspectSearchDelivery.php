<?php

namespace App\Services\GoogleAds\Diagnostics;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionPrimaryStatusEnum\AdGroupCriterionPrimaryStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V22\Enums\AdNetworkTypeEnum\AdNetworkType;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;

/** Read-only, network-specific evidence for a Google Search delivery incident. */
class InspectSearchDelivery extends BaseGoogleAdsService
{
    public function inspect(Campaign $campaign): ?array
    {
        $this->ensureClient();
        $customerId = $campaign->customer->cleanGoogleCustomerId();
        $campaignId = $campaign->googleCampaignNumericId();
        if (! $customerId || ! $campaignId) {
            return null;
        }

        $settings = null;
        $query = "SELECT customer.time_zone, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type,
                         campaign.maximize_conversions.target_cpa_micros,
                         campaign.network_settings.target_google_search, campaign.network_settings.target_search_network,
                         campaign.network_settings.target_content_network, campaign_budget.amount_micros
                  FROM campaign WHERE campaign.id = {$campaignId}";
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $value = $row->getCampaign();
            $settings = [
                'time_zone' => $row->getCustomer()->getTimeZone() ?: ($campaign->customer->timezone ?: config('app.timezone', 'UTC')),
                'status' => $value->getStatus(),
                'channel' => $value->getAdvertisingChannelType(),
                'bidding_strategy' => $value->getBiddingStrategyType(),
                'target_cpa_micros' => $value->getMaximizeConversions()?->getTargetCpaMicros() ?? 0,
                'google_search_enabled' => $value->getNetworkSettings()?->getTargetGoogleSearch(),
                'search_partners_enabled' => $value->getNetworkSettings()?->getTargetSearchNetwork(),
                'display_enabled' => $value->getNetworkSettings()?->getTargetContentNetwork(),
                'daily_budget_micros' => $row->getCampaignBudget()?->getAmountMicros(),
            ];
            break;
        }

        if (! $settings || $settings['channel'] !== AdvertisingChannelType::SEARCH) {
            return null;
        }

        $localToday = CarbonImmutable::now($settings['time_zone'])->startOfDay();
        $completeDays = [$localToday->subDays(2)->toDateString(), $localToday->subDay()->toDateString()];
        $network = ['google_search' => 0, 'search_partners' => 0, 'display' => 0];
        $query = "SELECT segments.date, segments.ad_network_type, metrics.impressions
                  FROM campaign WHERE campaign.id = {$campaignId}
                  AND segments.date BETWEEN '{$completeDays[0]}' AND '{$completeDays[1]}'";
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
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
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $metrics = $row->getMetrics();
            $auction = [
                'impression_share' => $metrics->getSearchImpressionShare(),
                'lost_to_rank' => $metrics->getSearchRankLostImpressionShare(),
                'lost_to_budget' => $metrics->getSearchBudgetLostImpressionShare(),
                'conversions' => $metrics->getConversions(),
                'cost_micros' => $metrics->getCostMicros(),
                'clicks' => $metrics->getClicks(),
            ];
            break;
        }

        $eligibleKeywords = 0;
        $approvedAds = 0;
        if ($network['google_search'] === 0) {
            $query = "SELECT ad_group_criterion.status, ad_group_criterion.primary_status
                      FROM ad_group_criterion WHERE campaign.id = {$campaignId} AND ad_group_criterion.type = KEYWORD";
            foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
                $criterion = $row->getAdGroupCriterion();
                if ($criterion->getStatus() === AdGroupCriterionStatus::ENABLED
                    && $criterion->getPrimaryStatus() === AdGroupCriterionPrimaryStatus::ELIGIBLE) {
                    $eligibleKeywords++;
                }
            }
            $query = "SELECT ad_group_ad.status, ad_group_ad.policy_summary.approval_status
                      FROM ad_group_ad WHERE campaign.id = {$campaignId}";
            foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
                $ad = $row->getAdGroupAd();
                if ($ad->getStatus() === AdGroupAdStatus::ENABLED
                    && $ad->getPolicySummary()?->getApprovalStatus() === PolicyApprovalStatus::APPROVED) {
                    $approvedAds++;
                }
            }
        }

        return $settings + [
            'complete_days' => $completeDays,
            'impressions' => $network,
            'auction' => $auction,
            'eligible_keywords' => $eligibleKeywords,
            'approved_ads' => $approvedAds,
        ];
    }

    public static function isStalled(?array $snapshot, ?\DateTimeInterface $deployedAt, ?\DateTimeInterface $now = null): bool
    {
        if (! $snapshot || ! $deployedAt || ! $snapshot['google_search_enabled']
            || ($snapshot['status'] ?? null) !== CampaignStatus::ENABLED) {
            return false;
        }

        $now ??= now();
        if ($deployedAt->getTimestamp() > $now->getTimestamp() - 48 * 3600) {
            return false;
        }

        // Both days must be complete days after launch, not a partial launch day.
        $firstCompleteDay = $snapshot['complete_days'][0] ?? null;
        if ($firstCompleteDay && $deployedAt->getTimestamp() > CarbonImmutable::parse(
            $firstCompleteDay, $snapshot['time_zone'] ?? 'UTC'
        )->startOfDay()->getTimestamp()) {
            return false;
        }

        return ($snapshot['impressions']['google_search'] ?? 0) === 0;
    }
}
