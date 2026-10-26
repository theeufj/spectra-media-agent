<?php

namespace App\Services\GoogleAds;

use App\Models\Campaign;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\PositiveGeoTargetTypeEnum\PositiveGeoTargetType;

/** Fresh configuration evidence only. Never enables, repairs or spends on a campaign. */
class InspectAiMaxPilot extends BaseGoogleAdsService
{
    private const TRACKING_QUERY_PARAMETERS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function inspect(Campaign $campaign, array $pilot): array
    {
        $issues = [];
        $observed = [];
        $fingerprint = null;
        try {
            $customerId = $this->customer?->cleanGoogleCustomerId();
            $resource = $campaign->googleAdsResourceName();
            if (! $customerId || $campaign->customer_id !== $this->customer->id
                || $resource !== "customers/{$customerId}/campaigns/{$campaign->googleCampaignNumericId()}") {
                throw new \LogicException('AI Max inspection requires the campaign customer account.');
            }
            $landing = $this->url($pilot['landing_page'] ?? null);
            $geos = $pilot['geo_target_constants'] ?? null;
            if (! $landing || ! is_array($geos) || $geos === [] || ! array_is_list($geos)
                || array_filter($geos, fn ($geo) => ! is_string($geo) || ! preg_match('#^geoTargetConstants/[0-9]+$#D', $geo)) !== []
                || ! is_int($pilot['strategy_id'] ?? null) || $pilot['strategy_id'] <= 0
                || ! is_int($pilot['max_daily_budget_micros'] ?? null) || $pilot['max_daily_budget_micros'] <= 0
                || ! is_string($pilot['selected_conversion_action'] ?? null)
                || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/conversionActions/[0-9]+$#D', $pilot['selected_conversion_action'])
                || ! is_string($pilot['currency_code'] ?? null) || ! is_string($pilot['account_timezone'] ?? null)
                || ! in_array($pilot['account_timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
                || ! is_string($pilot['ends_at'] ?? null) || trim($pilot['ends_at']) === '') {
                throw new \InvalidArgumentException('AI Max pilot configuration is incomplete.');
            }
            $delivery = $this->delivery($campaign);
            $rows = $this->rows($customerId, "SELECT campaign.resource_name, campaign.ai_max_setting.enable_ai_max,
                campaign.asset_automation_settings, campaign.text_guidelines.term_exclusions,
                campaign.text_guidelines.messaging_restrictions, campaign.tracking_url_template, campaign.final_url_suffix,
                campaign_budget.resource_name, campaign_budget.amount_micros, campaign_budget.total_amount_micros,
                campaign_budget.explicitly_shared, campaign_budget.period, customer.currency_code, customer.time_zone
                FROM campaign WHERE campaign.resource_name = '{$resource}'");
            if (! $delivery || count($rows) !== 1 || ($rows[0]['campaign']['resourceName'] ?? null) !== $resource) {
                throw new \RuntimeException('Google did not return complete AI Max configuration evidence.');
            }
            $remote = $rows[0]['campaign'];
            $budget = $rows[0]['campaignBudget'] ?? [];
            $account = $rows[0]['customer'] ?? [];
            if (($delivery['channel'] ?? null) !== AdvertisingChannelType::SEARCH
                || ($delivery['bidding_strategy'] ?? null) !== BiddingStrategyType::MAXIMIZE_CONVERSIONS
                || ($delivery['portfolio_bidding_strategy'] ?? null) !== ''
                || ($delivery['target_cpa_micros'] ?? null) !== 0
                || ($delivery['actual_cpc_ceiling_micros'] ?? null) !== 0) {
                $issues[] = 'The pilot requires standard Search Maximize Conversions without CPA or CPC targets.';
            }
            if (($remote['aiMaxSetting']['enableAiMax'] ?? null) !== true) {
                $issues[] = 'Google has not confirmed AI Max is enabled.';
            }
            $automation = [];
            foreach ($remote['assetAutomationSettings'] ?? [] as $setting) {
                $type = $setting['assetAutomationType'] ?? null;
                if (! is_string($type) || isset($automation[$type])) {
                    $issues[] = 'Asset automation settings are ambiguous.';

                    continue;
                }
                $automation[$type] = $setting['assetAutomationStatus'] ?? null;
            }
            if (($automation['TEXT_ASSET_AUTOMATION'] ?? null) !== 'OPTED_IN'
                || ($automation['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION'] ?? null) !== 'OPTED_OUT') {
                $issues[] = 'Text customization must be explicitly enabled and final URL expansion explicitly disabled.';
            }
            if (($remote['textGuidelines']['messagingRestrictions'] ?? []) === []) {
                $issues[] = 'The reviewed offer needs explicit text-generation messaging restrictions.';
            }
            if (($budget['explicitlyShared'] ?? null) !== false || ($budget['period'] ?? null) !== 'DAILY'
                || $this->micros($budget['amountMicros'] ?? null) !== $pilot['max_daily_budget_micros']
                || $this->micros($budget['totalAmountMicros'] ?? '0') !== 0
                || ($delivery['daily_budget_micros'] ?? null) !== $pilot['max_daily_budget_micros']) {
                $issues[] = 'The live non-shared daily budget does not match the approved pilot.';
            }
            if (($account['currencyCode'] ?? null) !== $pilot['currency_code']
                || ($delivery['currency_code'] ?? null) !== $pilot['currency_code']
                || ($account['timeZone'] ?? null) !== $pilot['account_timezone']
                || ($delivery['time_zone'] ?? null) !== $pilot['account_timezone']
                || ($delivery['account_time_zone_verified'] ?? null) !== true) {
                $issues[] = 'The Google account currency or timezone differs from the approved pilot.';
            }
            $endsAt = $delivery['end_date_time'] ?? null;
            if (! is_string($endsAt) || $endsAt === ''
                || ! CarbonImmutable::parse($endsAt, $pilot['account_timezone'])->equalTo(CarbonImmutable::parse($pilot['ends_at']))) {
                $issues[] = 'The Google campaign end timestamp does not match the pilot end.';
            }
            $actualGeos = $delivery['geo_target_constants'] ?? [];
            sort($geos);
            sort($actualGeos);
            if ($actualGeos !== array_values(array_unique($geos))
                || ($delivery['positive_geo_target_type'] ?? null) !== PositiveGeoTargetType::PRESENCE
                || ($delivery['google_search_enabled'] ?? null) !== true
                || ($delivery['search_partners_enabled'] ?? null) !== false
                || ($delivery['display_enabled'] ?? null) !== false
                || ($delivery['unsupported_targeting'] ?? null) !== []) {
                $issues[] = 'The pilot requires its approved presence-only markets and Google Search inventory.';
            }
            $groups = $this->rows($customerId, "SELECT ad_group.resource_name, ad_group.status, ad_group.type,
                ad_group.ai_max_ad_group_setting.disable_search_term_matching, ad_group.tracking_url_template,
                ad_group.final_url_suffix, ad_group.targeting_setting.target_restrictions
                FROM ad_group WHERE campaign.resource_name = '{$resource}' AND ad_group.status != REMOVED");
            $enabledGroups = [];
            foreach ($groups as $row) {
                $group = $row['adGroup'] ?? [];
                if (($group['status'] ?? null) === 'ENABLED') {
                    if (($group['type'] ?? null) !== 'SEARCH_STANDARD'
                        || ($group['aiMaxAdGroupSetting']['disableSearchTermMatching'] ?? false) !== false) {
                        $issues[] = 'Every enabled ad group must retain AI Max search-term matching.';
                    }
                    $groupResource = $group['resourceName'] ?? null;
                    if (! is_string($groupResource) || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/adGroups/[0-9]+$#D', $groupResource)) {
                        $issues[] = 'An enabled ad group does not belong to the inspected Google account.';
                    } else {
                        $enabledGroups[] = $groupResource;
                    }
                }
            }
            if ($enabledGroups === []) {
                $issues[] = 'No enabled Search ad group was observed.';
            }
            $ads = $this->rows($customerId, "SELECT ad_group.resource_name, ad_group.status, ad_group_ad.resource_name,
                ad_group_ad.status, ad_group_ad.ad.type, ad_group_ad.ad.final_urls, ad_group_ad.ad.final_mobile_urls,
                ad_group_ad.ad.tracking_url_template, ad_group_ad.ad.final_url_suffix,
                ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions
                FROM ad_group_ad WHERE campaign.resource_name = '{$resource}' AND ad_group_ad.status != REMOVED AND ad_group.status != REMOVED");
            $activeAds = 0;
            foreach ($ads as $row) {
                if (($row['adGroup']['status'] ?? null) !== 'ENABLED' || ($row['adGroupAd']['status'] ?? null) !== 'ENABLED') {
                    continue;
                }
                if (! in_array($row['adGroup']['resourceName'] ?? null, $enabledGroups, true)) {
                    $issues[] = 'An enabled ad does not belong to a verified pilot ad group.';
                }
                $activeAds++;
                $urls = $row['adGroupAd']['ad']['finalUrls'] ?? [];
                $mobile = $row['adGroupAd']['ad']['finalMobileUrls'] ?? [];
                if ($urls === [] || ! $this->matchingUrls([...$urls, ...$mobile], $landing)) {
                    $issues[] = 'An enabled ad can send visitors outside the reviewed offer URL.';
                }
            }
            if ($activeAds === 0) {
                $issues[] = 'No enabled ad with the reviewed offer URL was observed.';
            }
            $keywordUrls = $this->rows($customerId, "SELECT ad_group.resource_name, ad_group.status,
                ad_group_criterion.resource_name, ad_group_criterion.status, ad_group_criterion.keyword.text,
                ad_group_criterion.keyword.match_type, ad_group_criterion.final_urls, ad_group_criterion.final_mobile_urls,
                ad_group_criterion.tracking_url_template, ad_group_criterion.final_url_suffix
                FROM ad_group_criterion WHERE campaign.resource_name = '{$resource}' AND ad_group_criterion.type = KEYWORD
                AND ad_group_criterion.negative = FALSE AND ad_group_criterion.status != REMOVED AND ad_group.status != REMOVED");
            foreach ($keywordUrls as $row) {
                $criterion = $row['adGroupCriterion'] ?? [];
                if (($row['adGroup']['status'] ?? null) === 'ENABLED' && ($criterion['status'] ?? null) === 'ENABLED'
                    && (! in_array($row['adGroup']['resourceName'] ?? null, $enabledGroups, true) || ! $this->matchingUrls([...($criterion['finalUrls'] ?? []), ...($criterion['finalMobileUrls'] ?? [])], $landing))) {
                    $issues[] = 'An enabled keyword URL overrides the reviewed offer URL.';
                }
            }
            $goal = $this->goal($campaign, $pilot, $resource);
            if (($goal['ready'] ?? null) !== true
                || ($goal['intent']['category'] ?? null) !== 'PURCHASE'
                || ($goal['intent']['action_resource'] ?? null) !== $pilot['selected_conversion_action']) {
                $issues[] = 'The campaign purchase goal does not match the pinned setup purchase action.';
            }
            $stableDelivery = $delivery;
            unset($stableDelivery['status']);
            $observed = ['campaign_resource' => $resource, 'campaign_status' => $delivery['status'] ?? null,
                'delivery_fingerprint' => GoogleSearchReachGate::settingsFingerprint($stableDelivery),
                'campaign' => $remote, 'budget' => $budget, 'account' => $account, 'ad_groups' => $groups,
                'ads' => $ads, 'keyword_urls' => $keywordUrls, 'conversion_intent' => $goal['intent'] ?? null];
            $stable = $observed;
            unset($stable['campaign_status']);
            // Native text customization may vary assets without changing the
            // approved offer, destination or automation controls. Observe its
            // copy separately; do not pause the pilot for a text variation.
            foreach ($stable['ads'] as &$adRow) {
                unset($adRow['adGroupAd']['ad']['responsiveSearchAd']);
            }
            unset($adRow);
            $fingerprint = hash('sha256', json_encode($this->sorted($stable), JSON_THROW_ON_ERROR));
            if (isset($pilot['settings_fingerprint']) && (! is_string($pilot['settings_fingerprint'])
                || ! hash_equals($pilot['settings_fingerprint'], $fingerprint))) {
                $issues[] = 'The Google campaign configuration changed after pilot approval.';
            }
        } catch (\Throwable $e) {
            report($e);
            $issues[] = 'Fresh AI Max pilot configuration could not be verified.';
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)),
            'observed' => $observed, 'fingerprint' => $fingerprint, 'checked_at' => now()->toIso8601String()];
    }

    protected function delivery(Campaign $campaign): ?array
    {
        return (new InspectSearchDelivery($this->customer))->inspect($campaign);
    }

    protected function goal(Campaign $campaign, array $pilot, string $resource): array
    {
        $strategy = $campaign->strategies()->whereKey($pilot['strategy_id'])->first();

        return $strategy ? (new ReconcileCampaignConversionGoals($this->customer))->inspect($strategy, $resource) : ['ready' => false];
    }

    protected function rows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $data = json_decode($row->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
            // A selected protobuf false can be omitted in JSON; its SDK getter
            // still represents the successfully queried explicit sharing field.
            if (str_contains($query, 'campaign_budget.explicitly_shared')) {
                $data['campaignBudget']['explicitlyShared'] = $row->getCampaignBudget()->getExplicitlyShared();
            }
            $rows[] = $data;
        }

        return $rows;
    }

    private function micros(mixed $value): ?int
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d{1,17}$/D', $value)) ? (int) $value : null;
    }

    private function matchingUrls(array $urls, string $expected): bool
    {
        foreach ($urls as $url) {
            if ($this->url($url) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! empty($parts['user'])
            || ! empty($parts['pass']) || ! empty($parts['fragment']) || empty($parts['host'])) {
            return null;
        }

        // Compare the offer destination separately from conventional campaign
        // attribution. Preserve every other raw pair: parsing/rebuilding the
        // query could silently discard duplicate or offer-selecting parameters.
        $query = isset($parts['query']) ? array_values(array_filter(explode('&', $parts['query']),
            fn (string $pair): bool => ! in_array(rawurldecode(explode('=', $pair, 2)[0]), self::TRACKING_QUERY_PARAMETERS, true))) : [];

        return 'https://'.mb_strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '')
            .(rtrim($parts['path'] ?? '/', '/') ?: '/').($query !== [] ? '?'.implode('&', $query) : '');
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => $this->sorted($item), $value);
        if (array_is_list($value)) {
            usort($value, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        } else {
            ksort($value);
        }

        return $value;
    }
}
