<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;

/** Read platform truth, scoped to one campaign, before and after a change. */
class GetCompetitiveCampaignState extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $resource): array
    {
        $this->ensureClient();
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $resource)) {
            throw new \InvalidArgumentException('Invalid campaign resource.');
        }
        $state = ['ads' => [], 'keywords' => [], 'source' => 'google_ads_api',
            'observed_at' => now()->toIso8601String(), 'campaign_resource' => $resource];
        $query = "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type,
            campaign.bidding_strategy_type, campaign.bidding_strategy, campaign.target_spend.cpc_bid_ceiling_micros,
            campaign.maximize_conversions.target_cpa_micros, campaign.maximize_conversion_value.target_roas,
            campaign.target_cpa.target_cpa_micros, campaign.target_roas.target_roas,
            campaign_budget.amount_micros, campaign_budget.explicitly_shared,
            campaign.network_settings.target_search_network, campaign.network_settings.target_content_network
            FROM campaign WHERE campaign.resource_name = '$resource'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $campaign = $row->getCampaign();
            $state['enabled'] = $campaign->getStatus() === CampaignStatus::ENABLED;
            $state['search'] = $campaign->getAdvertisingChannelType() === AdvertisingChannelType::SEARCH;
            $state['bidding_strategy_type'] = BiddingStrategyType::name($campaign->getBiddingStrategyType());
            $state['keyword_bids_supported'] = $campaign->getBiddingStrategyType() === BiddingStrategyType::MANUAL_CPC;
            $state['bidding_strategy_resource'] = $campaign->getBiddingStrategy() ?: null;
            // Portfolio controls live on the bidding strategy, not the campaign.
            // Missing controls are unknown; never invent a target from zero CPC.
            $state['bidding_controls'] = $state['bidding_strategy_resource'] ? null : [
                'cpc_bid_ceiling_micros' => $campaign->getTargetSpend()?->getCpcBidCeilingMicros(),
                'target_cpa_micros' => $campaign->getMaximizeConversions()?->getTargetCpaMicros()
                    ?? $campaign->getTargetCpa()?->getTargetCpaMicros(),
                'target_roas' => $campaign->getMaximizeConversionValue()?->getTargetRoas()
                    ?? $campaign->getTargetRoas()?->getTargetRoas(),
            ];
            $state['budget_micros'] = $row->getCampaignBudget()->getAmountMicros();
            $state['shared_budget'] = $row->getCampaignBudget()->getExplicitlyShared();
            $state['network_settings'] = [
                'target_search_network' => $campaign->getNetworkSettings()?->getTargetSearchNetwork(),
                'target_content_network' => $campaign->getNetworkSettings()?->getTargetContentNetwork(),
            ];
        }
        $query = "SELECT campaign.resource_name, ad_group.resource_name, ad_group_ad.resource_name, ad_group_ad.status,
            ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions FROM ad_group_ad
            WHERE campaign.resource_name = '$resource' AND ad_group.status = 'ENABLED'
            AND ad_group_ad.status != 'REMOVED' AND ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $ad = $row->getAdGroupAd()->getAd();
            $state['ads'][] = [
                'resource' => $row->getAdGroupAd()->getResourceName(),
                'ad_group' => $row->getAdGroup()->getResourceName(),
                'enabled' => $row->getAdGroupAd()->getStatus() === AdGroupAdStatus::ENABLED,
                'final_urls' => iterator_to_array($ad->getFinalUrls()),
                'headlines' => array_map(fn ($a) => $a->getText(), iterator_to_array($ad->getResponsiveSearchAd()->getHeadlines())),
                'descriptions' => array_map(fn ($a) => $a->getText(), iterator_to_array($ad->getResponsiveSearchAd()->getDescriptions())),
            ];
        }
        $query = "SELECT campaign.resource_name, ad_group.resource_name, ad_group.cpc_bid_micros, ad_group_criterion.resource_name, ad_group_criterion.status,
            ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.cpc_bid_micros
            FROM ad_group_criterion WHERE campaign.resource_name = '$resource'
            AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.negative = FALSE
            AND ad_group_criterion.status != 'REMOVED' AND ad_group.status = 'ENABLED'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $keyword = $row->getAdGroupCriterion();
            $state['keywords'][] = [
                'resource' => $keyword->getResourceName(), 'ad_group' => $row->getAdGroup()->getResourceName(),
                'text' => $keyword->getKeyword()->getText(), 'match_type' => $keyword->getKeyword()->getMatchType(),
                'enabled' => $keyword->getStatus() === AdGroupCriterionStatus::ENABLED,
                'cpc_bid_micros' => $keyword->getCpcBidMicros(),
                'ad_group_cpc_bid_micros' => $row->getAdGroup()->getCpcBidMicros(),
            ];
        }

        return $state;
    }
}
