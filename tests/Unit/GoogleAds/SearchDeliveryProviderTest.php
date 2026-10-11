<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Common\AdScheduleInfo;
use Google\Ads\GoogleAds\V22\Common\DeviceInfo;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Common\LanguageInfo;
use Google\Ads\GoogleAds\V22\Common\LocationInfo;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\Segments;
use Google\Ads\GoogleAds\V22\Common\TargetingSetting;
use Google\Ads\GoogleAds\V22\Common\TargetRestriction;
use Google\Ads\GoogleAds\V22\Common\TargetSpend;
use Google\Ads\GoogleAds\V22\Enums\AdNetworkTypeEnum\AdNetworkType;
use Google\Ads\GoogleAds\V22\Enums\CriterionTypeEnum\CriterionType;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\TargetingDimensionEnum\TargetingDimension;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAdPolicySummary;
use Google\Ads\GoogleAds\V22\Resources\AdGroupBidModifier;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V22\Resources\Campaign\GeoTargetTypeSetting;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Services\KeywordForecastMetrics;
use Tests\TestCase;

/** SDK fixtures only: no database, OAuth or live API calls. */
class SearchDeliveryProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function campaign(): Campaign
    {
        $campaign = new Campaign([
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61',
            'last_bidding_changed_at' => '2026-10-10T04:20:00Z',
            'spend_guardrails' => ['enabled' => true, 'started_at' => '2026-10-10T04:42:00Z'],
        ]);
        $campaign->setRelation('customer', new Customer(['google_ads_customer_id' => '1234567890', 'timezone' => 'Australia/Sydney']));

        return $campaign;
    }

    private function inspector(array $hours = [], array $extra = []): InspectSearchDelivery
    {
        return new class($hours, $extra) extends InspectSearchDelivery
        {
            public function __construct(private array $hours, private array $extra) {}

            protected function deploymentStartedAt(Campaign $campaign): mixed
            {
                return '2026-10-01T00:00:00Z';
            }

            protected function rows(string $customerId, string $query): iterable
            {
                if (str_contains($query, 'customer.currency_code')) {
                    return [new GoogleAdsRow([
                        'customer' => new GoogleCustomer(['time_zone' => 'Australia/Sydney', 'currency_code' => 'AUD']),
                        'campaign' => new GoogleCampaign(['status' => 2, 'advertising_channel_type' => 2, 'bidding_strategy_type' => 9,
                            'target_spend' => new TargetSpend(['cpc_bid_ceiling_micros' => 3_000_000]),
                            'geo_target_type_setting' => new GeoTargetTypeSetting(['positive_geo_target_type' => 7]),
                            'network_settings' => new \Google\Ads\GoogleAds\V22\Resources\Campaign\NetworkSettings(['target_google_search' => true])]),
                        'campaign_budget' => new CampaignBudget(['amount_micros' => 10_000_000]),
                    ])];
                }
                if (str_contains($query, 'segments.hour')) {
                    return $this->hours;
                }
                if (str_contains($query, 'segments.date, segments.ad_network_type')) {
                    return [new GoogleAdsRow(['segments' => new Segments(['date' => '2026-10-10', 'ad_network_type' => AdNetworkType::SEARCH]),
                        'metrics' => new Metrics(['impressions' => 26])])];
                }
                if (str_contains($query, 'search_impression_share')) {
                    return [];
                }
                if (str_contains($query, 'FROM ad_group WHERE')) {
                    $restrictions = [];
                    if (array_key_exists('observation', $this->extra)) {
                        $restriction = new TargetRestriction(['targeting_dimension' => TargetingDimension::AUDIENCE]);
                        if ($this->extra['observation'] !== null) {
                            $restriction->setBidOnly($this->extra['observation']);
                        }
                        $restrictions[] = $restriction;
                    }

                    return [new GoogleAdsRow(['ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/5',
                        'targeting_setting' => new TargetingSetting(['target_restrictions' => $restrictions])])])];
                }
                if (str_contains($query, 'ad_group_criterion.type != KEYWORD')) {
                    if (! ($this->extra['interest'] ?? false)) {
                        return [];
                    }

                    return [new GoogleAdsRow(['ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/5', 'status' => 2]),
                        'ad_group_criterion' => new AdGroupCriterion(['resource_name' => 'customers/1234567890/adGroupCriteria/5~9',
                            'type' => CriterionType::USER_INTEREST, 'status' => 2])])];
                }
                if (str_contains($query, 'FROM ad_group_bid_modifier')) {
                    if (! array_key_exists('device_modifier', $this->extra)) {
                        return [];
                    }
                    $modifier = new AdGroupBidModifier(['resource_name' => 'customers/1234567890/adGroupBidModifiers/5~30000',
                        'device' => new DeviceInfo(['type' => 2])]);
                    if ($this->extra['device_modifier'] !== null) {
                        $modifier->setBidModifier($this->extra['device_modifier']);
                    }

                    return [new GoogleAdsRow(['ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/5', 'status' => 2]),
                        'ad_group_bid_modifier' => $modifier])];
                }
                if (str_contains($query, 'FROM campaign_shared_set')) {
                    return [];
                }
                if (str_contains($query, 'FROM ad_group_criterion')) {
                    return [new GoogleAdsRow([
                        'ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/5', 'status' => 2]),
                        'ad_group_criterion' => new AdGroupCriterion(['resource_name' => 'customers/1234567890/adGroupCriteria/5~7',
                            'status' => 2, 'primary_status' => 2, 'keyword' => new KeywordInfo(['text' => 'google ads management', 'match_type' => KeywordMatchType::EXACT])]),
                    ]), new GoogleAdsRow([
                        'ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/5', 'status' => 2]),
                        'ad_group_criterion' => new AdGroupCriterion(['resource_name' => 'customers/1234567890/adGroupCriteria/5~8',
                            'status' => 2, 'negative' => true, 'keyword' => new KeywordInfo(['text' => 'jobs', 'match_type' => KeywordMatchType::PHRASE])]),
                    ])];
                }
                if (str_contains($query, 'FROM ad_group_ad')) {
                    return [new GoogleAdsRow(['ad_group' => new AdGroup(['status' => 2]),
                        'ad_group_ad' => new AdGroupAd(['status' => 2, 'policy_summary' => new AdGroupAdPolicySummary(['approval_status' => 4])])])];
                }
                if (str_contains($query, 'FROM campaign_criterion')) {
                    return [new GoogleAdsRow(['campaign_criterion' => new CampaignCriterion(['type' => CriterionType::LOCATION, 'status' => 2,
                        'location' => new LocationInfo(['geo_target_constant' => 'geoTargetConstants/2036'])])]),
                        new GoogleAdsRow(['campaign_criterion' => new CampaignCriterion(['type' => CriterionType::LANGUAGE, 'status' => 2,
                            'language' => new LanguageInfo(['language_constant' => 'languageConstants/1000'])])]),
                        new GoogleAdsRow(['campaign_criterion' => new CampaignCriterion(['type' => CriterionType::KEYWORD, 'status' => 2, 'negative' => true,
                            'keyword' => new KeywordInfo(['text' => 'free', 'match_type' => KeywordMatchType::BROAD])])]),
                        new GoogleAdsRow(['campaign_criterion' => new CampaignCriterion(['type' => CriterionType::AD_SCHEDULE, 'status' => 2,
                            'ad_schedule' => new AdScheduleInfo(['day_of_week' => 2, 'start_hour' => 9, 'end_hour' => 17, 'start_minute' => 2, 'end_minute' => 2])])])];
                }
                throw new \LogicException('Unexpected fixture query: '.$query);
            }
        };
    }

    private function hour(string $date, int $hour, int $impressions, int $network = AdNetworkType::SEARCH): GoogleAdsRow
    {
        return new GoogleAdsRow(['segments' => new Segments(['date' => $date, 'hour' => $hour, 'ad_network_type' => $network]),
            'metrics' => new Metrics(['impressions' => $impressions, 'clicks' => 1, 'cost_micros' => 500_000, 'conversions' => 0.5])]);
    }

    public function test_restart_measurement_excludes_old_partial_hour_reporting_lag_and_other_networks(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T00:12:00Z');
        config(['optimization.search_delivery.reporting_lag_hours' => 3]);
        $snapshot = $this->inspector([
            $this->hour('2026-10-10', 9, 100),
            $this->hour('2026-10-10', 15, 100),
            $this->hour('2026-10-10', 16, 5),
            $this->hour('2026-10-10', 16, 100, AdNetworkType::SEARCH_PARTNERS),
            $this->hour('2026-10-11', 7, 7),
            $this->hour('2026-10-11', 8, 100),
        ])->inspect($this->campaign());

        $this->assertSame(26, $snapshot['impressions']['google_search']);
        $this->assertSame('2026-10-10T16:00:00+11:00', $snapshot['measurement']['from']);
        $this->assertSame('2026-10-11T08:00:00+11:00', $snapshot['measurement']['through']);
        $this->assertSame(16, $snapshot['measurement']['complete_hours']);
        $this->assertSame(12, $snapshot['measurement']['impressions']);
        $this->assertSame(2, $snapshot['measurement']['clicks']);
        $this->assertSame(1_000_000, $snapshot['measurement']['cost_micros']);
        $this->assertSame(1.0, $snapshot['measurement']['conversions']);
        $this->assertSame(3_000_000, $snapshot['actual_cpc_ceiling_micros']);
        $this->assertSame('AUD', $snapshot['currency_code']);
        $this->assertSame(1, $snapshot['eligible_keywords']);
        $this->assertSame(1, $snapshot['approved_ads']);
        $this->assertSame('EXACT', $snapshot['keywords'][0]['match_type']);
        $this->assertSame(['geoTargetConstants/2036'], $snapshot['geo_target_constants']);
        $this->assertSame(['languageConstants/1000'], $snapshot['language_constants']);
        $this->assertCount(2, $snapshot['negative_keywords']);
        $this->assertCount(1, $snapshot['ad_schedule']);
        $this->assertSame([], $snapshot['device_bid_modifiers']);
        $this->assertSame([], $snapshot['unsupported_targeting']);
    }

    public function test_explicit_later_baseline_wins_and_a_future_trial_timestamp_is_ignored(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T00:12:00Z');
        $campaign = $this->campaign();
        $campaign->spend_guardrails = ['started_at' => '2027-01-01T00:00:00Z'];
        $snapshot = $this->inspector()->inspect($campaign, CarbonImmutable::parse('2026-10-10T06:00:00Z'));

        $this->assertSame('2026-10-10T06:00:00+00:00', $snapshot['evaluation_started_at']);
        $this->assertSame(15, $snapshot['measurement']['complete_hours']);
    }

    public function test_new_stall_evidence_never_uses_pre_restart_impressions(): void
    {
        $snapshot = ['status' => 2, 'google_search_enabled' => true, 'impressions' => ['google_search' => 26],
            'measurement' => ['complete_hours' => 48, 'impressions' => 0]];
        $this->assertTrue(InspectSearchDelivery::isStalled($snapshot, CarbonImmutable::now()->subDays(30)));
        $snapshot['measurement']['complete_hours'] = 18;
        $this->assertFalse(InspectSearchDelivery::isStalled($snapshot, CarbonImmutable::now()->subDays(30)));
        $snapshot['measurement'] = ['complete_hours' => 48, 'impressions' => 1];
        $this->assertFalse(InspectSearchDelivery::isStalled($snapshot, CarbonImmutable::now()->subDays(30)));
    }

    public function test_rolling_window_catches_a_later_stop_even_after_initial_restart_traffic(): void
    {
        CarbonImmutable::setTestNow('2026-10-15T00:12:00Z');
        config(['optimization.search_delivery.reporting_lag_hours' => 3, 'optimization.search_delivery.measurement_window_hours' => 48]);
        $snapshot = $this->inspector([$this->hour('2026-10-10', 16, 5)])->inspect($this->campaign());

        $this->assertSame('2026-10-10T04:42:00+00:00', $snapshot['measurement']['started_at']);
        $this->assertSame('2026-10-13T08:00:00+11:00', $snapshot['measurement']['from']);
        $this->assertSame(48, $snapshot['measurement']['complete_hours']);
        $this->assertSame(0, $snapshot['measurement']['impressions']);
        $this->assertTrue(InspectSearchDelivery::isStalled($snapshot, CarbonImmutable::parse('2026-10-01')));
    }

    public function test_unset_device_bid_and_confirmed_audience_observation_are_neutral(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T00:12:00Z');
        $snapshot = $this->inspector([], ['interest' => true, 'observation' => true, 'device_modifier' => null])->inspect($this->campaign());

        $this->assertSame([], $snapshot['unsupported_targeting']);
        $this->assertTrue($snapshot['audience_criteria'][0]['observation_only']);
        $this->assertSame(1.0, $snapshot['device_bid_modifiers'][0]['bid_modifier']);
        $this->assertFalse($snapshot['device_bid_modifiers'][0]['explicit']);
        $this->assertSame(['AUDIENCE' => true], $snapshot['ad_group_targeting_modes']['customers/1234567890/adGroups/5']);
    }

    public function test_unknown_or_restrictive_audience_mode_remains_unsupported_and_explicit_zero_is_retained(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T00:12:00Z');
        foreach ([null, false] as $mode) {
            $snapshot = $this->inspector([], ['interest' => true, 'observation' => $mode, 'device_modifier' => 0.0])->inspect($this->campaign());
            $this->assertNotEmpty($snapshot['unsupported_targeting']);
            $this->assertFalse($snapshot['audience_criteria'][0]['observation_only']);
            $this->assertSame(0.0, $snapshot['device_bid_modifiers'][0]['bid_modifier']);
            $this->assertTrue($snapshot['device_bid_modifiers'][0]['explicit']);
        }
    }

    private function forecast(): FakeSearchDeliveryForecast
    {
        return new FakeSearchDeliveryForecast;
    }

    private function context(): array
    {
        return ['bidding_strategy' => 9, 'daily_budget_micros' => 10_000_000, 'cpc_bid_ceiling_micros' => 3_000_000,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'ad_schedule' => [], 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney'];
    }

    public function test_forecast_models_actual_exact_match_geo_language_budget_cap_and_negative_scope(): void
    {
        CarbonImmutable::setTestNow('2026-10-10T23:30:00Z');
        $forecast = $this->forecast();
        $context = $this->context();
        $context['negative_keywords'] = [['text' => 'free', 'match_type' => 'BROAD', 'scope' => 'campaign'],
            ['text' => 'jobs', 'match_type' => 'PHRASE', 'scope' => 'ad_group', 'ad_group_resource' => 'group5']];
        $result = $forecast('1234567890', [['text' => 'google ads management', 'match_type' => 'EXACT', 'ad_group_resource' => 'group5']], 9.99, null, 7, $context);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['auto_repair_safe']);
        $this->assertArrayNotHasKey('conversions', $result);
        $this->assertSame(10_000_000, $result['cost_micros']);
        $this->assertSame(7, $result['period_days']);
        $this->assertInstanceOf(GenerateKeywordForecastMetricsRequest::class, $forecast->request);
        $campaign = $forecast->request->getCampaign();
        $this->assertSame('2026-10-12', $forecast->request->getForecastPeriod()->getStartDate());
        $this->assertSame(3_000_000, $campaign->getBiddingStrategy()->getMaximizeClicksBiddingStrategy()->getMaxCpcBidCeilingMicros());
        $this->assertSame(10_000_000, $campaign->getBiddingStrategy()->getMaximizeClicksBiddingStrategy()->getDailyTargetSpendMicros());
        $this->assertSame(KeywordMatchType::EXACT, $campaign->getAdGroups()[0]->getBiddableKeywords()[0]->getKeyword()->getMatchType());
        $this->assertSame('geoTargetConstants/2036', $campaign->getGeoModifiers()[0]->getGeoTargetConstant());
        $this->assertSame('languageConstants/1000', $campaign->getLanguageConstants()[0]);
        $this->assertSame('free', $campaign->getNegativeKeywords()[0]->getText());
        $this->assertSame('jobs', $campaign->getAdGroups()[0]->getNegativeKeywords()[0]->getText());
        $this->assertFalse($campaign->hasConversionRate());
    }

    public function test_unsupported_daypart_and_excluded_geo_disable_automatic_repair_certainty(): void
    {
        $context = $this->context();
        $context['ad_schedule'] = [['day_of_week' => 'MONDAY', 'start_hour' => 9, 'end_hour' => 17]];
        $context['excluded_geo_target_constants'] = ['geoTargetConstants/1023191'];
        $result = ($this->forecast())('1234567890', [['text' => 'google ads management', 'match_type' => 'EXACT']], 3, null, 7, $context);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertCount(2, $result['caveats']);
    }

    public function test_legacy_phrase_call_keeps_explicit_disclosed_conversion_assumption(): void
    {
        $forecast = $this->forecast();
        $result = $forecast('1234567890', [' google ads management ', 'google ads management'], 2.034, 0.02);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertSame('supplied_assumption', $result['conversion_rate_source']);
        $this->assertSame(2_030_000, $result['context']['cpc_bid_ceiling_micros']);
        $this->assertSame([['text' => 'google ads management', 'match_type' => 'PHRASE']], $result['context']['keywords']);
        $this->assertSame(1.0, $result['conversions']);
    }

    public function test_device_restrictions_and_shared_negative_uncertainty_cannot_certify_repair(): void
    {
        $context = $this->context();
        $context['device_bid_modifiers'] = [['scope' => 'campaign', 'device' => 'MOBILE', 'bid_modifier' => 0.0]];
        $context['unsupported_targeting'] = [['type' => 'shared_negative_keyword_list', 'resource_name' => 'customers/1234567890/sharedSets/5']];
        $result = ($this->forecast())('1234567890', [['text' => 'google ads management', 'match_type' => 'EXACT']], 3, null, 7, $context);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertNotEmpty($result['caveats']);
    }
}

class FakeSearchDeliveryForecast extends GenerateKeywordForecast
{
    public ?GenerateKeywordForecastMetricsRequest $request = null;

    public function __construct() {}

    protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
    {
        $this->request = $request;

        return new GenerateKeywordForecastMetricsResponse(['campaign_forecast_metrics' => new KeywordForecastMetrics([
            'impressions' => 100, 'clicks' => 5, 'cost_micros' => 10_000_000, 'average_cpc_micros' => 2_000_000, 'conversions' => 1,
        ])]);
    }
}
