<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;

/** Read-only configuration used for deployment checks and delayed creative verification. */
class ReadCampaignConfiguration extends BaseGoogleAdsService
{
    public function ads(string $customerId, string $campaign): array
    {
        return $this->rows($customerId, "SELECT campaign.resource_name, ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.ad.id, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad_strength, ad_group_ad.policy_summary.approval_status FROM ad_group_ad WHERE campaign.resource_name = '{$campaign}' AND ad_group_ad.status != 'REMOVED'");
    }

    public function read(string $customerId, string $campaign): array
    {
        if (! preg_match('#^customers/\d+/campaigns/\d+$#', $campaign)) {
            throw new \InvalidArgumentException('Invalid Google campaign resource.');
        }

        return [
            'campaign' => $this->rows($customerId, "SELECT campaign.resource_name, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign_budget.amount_micros FROM campaign WHERE campaign.resource_name = '{$campaign}'")[0] ?? [],
            'ads' => $this->ads($customerId, $campaign),
            'keywords' => $this->rows($customerId, "SELECT campaign.resource_name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.negative FROM ad_group_criterion WHERE campaign.resource_name = '{$campaign}' AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status = 'ENABLED'"),
            'criteria' => $this->rows($customerId, "SELECT campaign.resource_name, campaign_criterion.type, campaign_criterion.negative, campaign_criterion.location.geo_target_constant, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.resource_name = '{$campaign}' AND campaign_criterion.status != 'REMOVED'"),
            'assets' => $this->rows($customerId, "SELECT campaign.resource_name, campaign_asset.asset, campaign_asset.field_type, asset.final_urls, asset.sitelink_asset.link_text, asset.price_asset.price_offerings, asset.promotion_asset.promotion_target, asset.promotion_asset.percent_off, asset.promotion_asset.money_amount_off.amount_micros, asset.promotion_asset.money_amount_off.currency_code FROM campaign_asset WHERE campaign.resource_name = '{$campaign}' AND campaign_asset.status = 'ENABLED'"),
            'goals' => $this->rows($customerId, "SELECT campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable FROM campaign_conversion_goal WHERE campaign.resource_name = '{$campaign}'"),
            'conversion_actions' => $this->rows($customerId, "SELECT conversion_action.category, conversion_action.primary_for_goal FROM conversion_action WHERE conversion_action.status = 'ENABLED'"),
        ];
    }

    protected function rows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }
}
