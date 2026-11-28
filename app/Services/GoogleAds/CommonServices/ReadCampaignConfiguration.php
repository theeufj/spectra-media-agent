<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Support\SearchBudgetReadback;

/** Read-only configuration used for deployment checks and delayed creative verification. */
class ReadCampaignConfiguration extends BaseGoogleAdsService
{
    public function findEmptyPausedLaunch(string $account, string $id): ?string
    {
        if (! \Illuminate\Support\Str::isUuid($id) || $account !== $this->customer?->cleanGoogleCustomerId()) {
            return null;
        }
        $rows = $this->rows($account, "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type FROM campaign WHERE campaign.name LIKE 'STS {$id} %'");
        $campaign = $rows[0]['campaign'] ?? [];
        $resource = $campaign['resourceName'] ?? '';
        if (count($rows) !== 1 || ($campaign['status'] ?? '') !== 'PAUSED' || ! preg_match('#^customers/'.$account.'/campaigns/\d+$#D', $resource)) {
            return null;
        }
        $group = ($campaign['advertisingChannelType'] ?? '') === 'PERFORMANCE_MAX' ? 'asset_group' : 'ad_group';
        $children = $this->rows($account, "SELECT {$group}.id FROM {$group} WHERE campaign.resource_name = '{$resource}' LIMIT 1");

        return $children === [] ? $resource : null;
    }

    public function ads(string $customerId, string $campaign): array
    {
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaign)) {
            throw new \InvalidArgumentException('Invalid Google campaign resource or account scope.');
        }

        return $this->rows($customerId, "SELECT campaign.resource_name, campaign.status, ad_group.resource_name, ad_group.status, ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.ad.id, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad_strength, ad_group_ad.action_items, ad_group_ad.policy_summary.approval_status, ad_group_ad.policy_summary.review_status FROM ad_group_ad WHERE campaign.resource_name = '{$campaign}' AND ad_group_ad.status != 'REMOVED'");
    }

    public function campaignStatus(string $customerId, string $campaign): string
    {
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaign)) {
            throw new \InvalidArgumentException('Invalid Google campaign resource or account scope.');
        }
        $row = $this->rows($customerId, "SELECT campaign.id, campaign.status FROM campaign WHERE campaign.resource_name = '{$campaign}'")[0] ?? [];
        $status = $row['campaign']['status'] ?? 'UNKNOWN';

        return is_int($status) ? \Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus::name($status) : strtoupper((string) $status);
    }

    public function hasVerifiedSearchTotalBudget(string $customerId, string $resource, array $expected): bool
    {
        if ($this->customer?->cleanGoogleCustomerId() !== $customerId
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#D', $resource)) {
            return false;
        }
        $row = $this->rows($customerId, 'SELECT customer.time_zone, campaign.resource_name, campaign.status, campaign.advertising_channel_type, '
            .SearchBudgetReadback::FIELDS." FROM campaign WHERE campaign.resource_name = '{$resource}'")[0] ?? [];

        return ($row['campaign']['resourceName'] ?? null) === $resource
            && ($row['campaign']['status'] ?? null) === 'PAUSED'
            && ($row['campaign']['advertisingChannelType'] ?? null) === 'SEARCH'
            && ($row['customer']['timeZone'] ?? null) === ($this->customer->timezone ?: config('app.timezone'))
            && SearchBudgetReadback::matches($expected, SearchBudgetReadback::fromRow($row));
    }

    /** Current destinations on the exact Search campaign, including inherited account assets. */
    public function singleLandingPageState(string $account, string $resource): array
    {
        if ($this->customer?->cleanGoogleCustomerId() !== $account || ! ctype_digit($account)
            || ! preg_match('#^customers/'.$account.'/campaigns/\d+$#D', $resource)) {
            throw new \InvalidArgumentException('The Search campaign is outside the selected Google Ads account.');
        }
        $campaign = "campaign.resource_name = '{$resource}'";
        $group = $campaign." AND ad_group.status = 'ENABLED'";
        $assetUrls = 'asset.final_urls, asset.final_mobile_urls, asset.tracking_url_template, asset.final_url_suffix';
        $locationTypes = "asset_set.status = 'ENABLED' AND asset_set.type IN ('LOCATION_SYNC', 'BUSINESS_PROFILE_DYNAMIC_LOCATION_GROUP', 'CHAIN_DYNAMIC_LOCATION_GROUP', 'STATIC_LOCATION_GROUP')";

        return [
            'settings' => $this->rows($account, "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type,
                campaign.asset_automation_settings, campaign.excluded_parent_asset_field_types, campaign.excluded_parent_asset_set_types,
                campaign.tracking_url_template, campaign.final_url_suffix, customer.tracking_url_template, customer.final_url_suffix FROM campaign WHERE {$campaign}"),
            'ads' => $this->rows($account, "SELECT campaign.resource_name, ad_group.status, ad_group.type, ad_group.tracking_url_template, ad_group.final_url_suffix,
                ad_group_ad.ad.type, ad_group_ad.ad.final_urls, ad_group_ad.ad.final_mobile_urls, ad_group_ad.ad.final_app_urls,
                ad_group_ad.ad.tracking_url_template, ad_group_ad.ad.final_url_suffix FROM ad_group_ad WHERE {$group} AND ad_group_ad.status = 'ENABLED'"),
            'keywords' => $this->rows($account, "SELECT campaign.resource_name, ad_group.status, ad_group_criterion.final_urls, ad_group_criterion.final_mobile_urls,
                ad_group_criterion.tracking_url_template, ad_group_criterion.final_url_suffix FROM ad_group_criterion WHERE {$group}
                AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.negative = FALSE AND ad_group_criterion.status = 'ENABLED'"),
            'customer_assets' => $this->rows($account, "SELECT customer_asset.field_type, {$assetUrls} FROM customer_asset WHERE customer_asset.status = 'ENABLED'"),
            'campaign_assets' => $this->rows($account, "SELECT campaign.resource_name, campaign_asset.field_type, {$assetUrls} FROM campaign_asset WHERE {$campaign} AND campaign_asset.status = 'ENABLED'"),
            'group_assets' => $this->rows($account, "SELECT campaign.resource_name, ad_group.status, ad_group_asset.field_type, {$assetUrls} FROM ad_group_asset WHERE {$group} AND ad_group_asset.status = 'ENABLED'"),
            'customer_locations' => $this->rows($account, "SELECT customer_asset_set.resource_name FROM customer_asset_set WHERE customer_asset_set.status = 'ENABLED' AND {$locationTypes} LIMIT 1"),
            'campaign_locations' => $this->rows($account, "SELECT campaign_asset_set.resource_name FROM campaign_asset_set WHERE {$campaign} AND campaign_asset_set.status = 'ENABLED' AND {$locationTypes} LIMIT 1"),
            'group_locations' => $this->rows($account, "SELECT ad_group_asset_set.resource_name FROM ad_group_asset_set WHERE {$group} AND ad_group_asset_set.status = 'ENABLED' AND {$locationTypes} LIMIT 1"),
        ];
    }

    public function performanceMax(string $customerId, string $campaign): array
    {
        if ($this->customer?->cleanGoogleCustomerId() !== $customerId
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#D', $campaign)) {
            throw new \InvalidArgumentException('Invalid Performance Max account or campaign resource.');
        }

        return [
            'campaign' => $this->rows($customerId, "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign.maximize_conversions.target_cpa_micros, campaign.asset_automation_settings, campaign_budget.amount_micros FROM campaign WHERE campaign.resource_name = '{$campaign}'")[0] ?? [],
            'groups' => $this->performanceMaxGroups($customerId, $campaign),
            'assets' => $this->rows($customerId, "SELECT asset_group.resource_name, asset_group_asset.asset, asset_group_asset.field_type FROM asset_group_asset WHERE campaign.resource_name = '{$campaign}' AND asset_group_asset.status = 'ENABLED'"),
            'ctas' => $this->rows($customerId, "SELECT asset_group.resource_name, asset.call_to_action_asset.call_to_action FROM asset_group_asset WHERE campaign.resource_name = '{$campaign}' AND asset_group_asset.field_type = 'CALL_TO_ACTION_SELECTION' AND asset_group_asset.status = 'ENABLED'"),
        ];
    }

    public function performanceMaxGroups(string $customerId, string $campaign): array
    {
        if ($this->customer?->cleanGoogleCustomerId() !== $customerId
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#D', $campaign)) {
            throw new \InvalidArgumentException('Invalid Performance Max account or campaign resource.');
        }

        return $this->rows($customerId, "SELECT asset_group.resource_name, asset_group.name, asset_group.status, asset_group.final_urls, asset_group.primary_status, asset_group.primary_status_reasons, asset_group.ad_strength FROM asset_group WHERE campaign.resource_name = '{$campaign}' AND asset_group.status != 'REMOVED'");
    }

    public function sitelinks(string $customerId, string $campaign): array
    {
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaign)) {
            throw new \InvalidArgumentException('Invalid Google campaign resource or account scope.');
        }

        return $this->rows($customerId, "SELECT campaign.id, campaign_asset.asset, asset.final_urls, asset.sitelink_asset.link_text FROM campaign_asset WHERE campaign_asset.campaign = '{$campaign}' AND campaign_asset.field_type = 'SITELINK' AND campaign_asset.status = 'ENABLED'");
    }

    public function read(string $customerId, string $campaign): array
    {
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaign)) {
            throw new \InvalidArgumentException('Invalid Google campaign resource or account scope.');
        }

        return [
            'campaign' => $this->rows($customerId, 'SELECT campaign.resource_name, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, '.SearchBudgetReadback::FIELDS." FROM campaign WHERE campaign.resource_name = '{$campaign}'")[0] ?? [],
            'ads' => $this->ads($customerId, $campaign),
            'keywords' => $this->rows($customerId, "SELECT campaign.resource_name, ad_group_criterion.ad_group, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.negative FROM ad_group_criterion WHERE campaign.resource_name = '{$campaign}' AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status = 'ENABLED'"),
            'criteria' => $this->rows($customerId, "SELECT campaign.resource_name, campaign_criterion.type, campaign_criterion.negative, campaign_criterion.location.geo_target_constant, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.resource_name = '{$campaign}' AND campaign_criterion.status != 'REMOVED'"),
            'assets' => $this->rows($customerId, "SELECT campaign.resource_name, campaign_asset.asset, campaign_asset.field_type, asset.final_urls, asset.sitelink_asset.link_text, asset.price_asset.price_offerings, asset.promotion_asset.promotion_target, asset.promotion_asset.percent_off, asset.promotion_asset.money_amount_off.amount_micros, asset.promotion_asset.money_amount_off.currency_code FROM campaign_asset WHERE campaign.resource_name = '{$campaign}' AND campaign_asset.status = 'ENABLED'"),
            'goals' => $this->rows($customerId, "SELECT campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable FROM campaign_conversion_goal WHERE campaign.resource_name = '{$campaign}'"),
            'conversion_actions' => $this->rows($customerId, "SELECT conversion_action.resource_name, conversion_action.status, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal FROM conversion_action WHERE conversion_action.status = 'ENABLED'"),
            'conversion_goal_config' => $this->rows($customerId, "SELECT conversion_goal_campaign_config.custom_conversion_goal, conversion_goal_campaign_config.goal_config_level FROM conversion_goal_campaign_config WHERE campaign.resource_name = '{$campaign}'")[0] ?? [],
            'custom_conversion_goals' => $this->rows($customerId, "SELECT custom_conversion_goal.resource_name, custom_conversion_goal.status, custom_conversion_goal.conversion_actions FROM custom_conversion_goal WHERE custom_conversion_goal.status = 'ENABLED'"),
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
