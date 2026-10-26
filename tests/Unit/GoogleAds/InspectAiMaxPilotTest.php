<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\InspectAiMaxPilot;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\PositiveGeoTargetTypeEnum\PositiveGeoTargetType;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/** No database, OAuth or production traffic. */
class InspectAiMaxPilotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Exceptions::fake();
        CarbonImmutable::setTestNow('2026-10-26T06:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function campaign(): Campaign
    {
        $customer = new Customer(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD']);
        $customer->id = 7;
        $campaign = new Campaign(['customer_id' => 7, 'google_ads_campaign_id' => '999']);
        $campaign->setRelation('customer', $customer);

        return $campaign;
    }

    private function pilot(): array
    {
        return ['strategy_id' => 55, 'landing_page' => 'https://example.com/one-time-setup',
            'geo_target_constants' => ['geoTargetConstants/2840'], 'max_daily_budget_micros' => 50_000_000,
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney',
            'ends_at' => '2026-11-09T23:59:59+11:00',
            'selected_conversion_action' => 'customers/1234567890/conversionActions/444'];
    }

    public function test_a_complete_pilot_is_observed_read_only_and_gets_a_stable_fingerprint(): void
    {
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $result = $inspector->inspect($campaign, $this->pilot());
        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['issues']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['fingerprint']);
        $this->assertSame(4, count($inspector->queries));
        foreach ($inspector->queries as $query) {
            $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/999'", $query);
        }
        $inspector->snapshot['status'] = 3; // Configuration can be prepared while the campaign is paused.
        $again = $inspector->inspect($campaign, [...$this->pilot(), 'settings_fingerprint' => $result['fingerprint']]);
        $this->assertTrue($again['ready']);
        $this->assertSame($result['fingerprint'], $again['fingerprint']);
    }

    public function test_native_copy_variations_are_observed_without_invalidating_approved_controls(): void
    {
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $inspector->ads[0]['adGroupAd']['ad']['responsiveSearchAd'] = ['headlines' => [['text' => 'Original asset']]];
        $first = $inspector->inspect($campaign, $this->pilot());
        $this->assertTrue($first['ready']);
        $inspector->ads[0]['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['text'] = 'Native generated variation';
        $next = $inspector->inspect($campaign, [...$this->pilot(), 'settings_fingerprint' => $first['fingerprint']]);
        $this->assertTrue($next['ready']);
        $this->assertSame($first['fingerprint'], $next['fingerprint']);
        $this->assertNotSame($first['observed']['ads'], $next['observed']['ads']);
    }

    public function test_conventional_utm_parameters_match_the_offer_but_remain_observed_and_fingerprinted(): void
    {
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $url = 'https://example.com/one-time-setup?utm_source=google&utm_medium=search&utm_campaign=spectra_65&utm_content=strategy_775&utm_term=setup';
        $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = [$url];
        $inspector->keywordUrls[0]['adGroupCriterion']['finalMobileUrls'] = [$url];
        $first = $inspector->inspect($campaign, $this->pilot());
        $this->assertTrue($first['ready']);
        $this->assertSame([$url], $first['observed']['ads'][0]['adGroupAd']['ad']['finalUrls']);
        $this->assertSame([$url], $first['observed']['keyword_urls'][0]['adGroupCriterion']['finalMobileUrls']);

        $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = [str_replace('utm_campaign=spectra_65', 'utm_campaign=changed', $url)];
        $next = $inspector->inspect($campaign, [...$this->pilot(), 'settings_fingerprint' => $first['fingerprint']]);
        $this->assertFalse($next['ready']);
        $this->assertNotSame($first['fingerprint'], $next['fingerprint']);
        $this->assertSame(['The Google campaign configuration changed after pilot approval.'], $next['issues']);
    }

    public function test_utm_normalization_preserves_offer_and_unknown_parameters_and_url_safety(): void
    {
        foreach (['plan=monthly', 'redirect=https%3A%2F%2Fexample.com%2Fmonthly', 'offer=other', 'utm_offer=monthly',
            'utm_source%5Bplan%5D=monthly'] as $query) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = ['https://example.com/one-time-setup?utm_source=google&'.$query];
            $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready'], $query);
        }
        foreach (['https://example.com/one-time-setup?utm_source=google#monthly',
            'https://user:pass@example.com/one-time-setup?utm_source=google'] as $url) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = [$url];
            $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready'], $url);
        }
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $pilot = [...$this->pilot(), 'landing_page' => 'https://example.com/one-time-setup?offer=setup&offer=approved'];
        $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = [$pilot['landing_page'].'&utm_source=google'];
        $this->assertTrue($inspector->inspect($campaign, $pilot)['ready']);
        $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = ['https://example.com/one-time-setup?offer=setup&utm_source=google'];
        $this->assertFalse($inspector->inspect($campaign, $pilot)['ready']);
    }

    public function test_missing_approval_inputs_and_foreign_scope_fail_before_any_provider_read(): void
    {
        foreach (['strategy_id', 'landing_page', 'geo_target_constants', 'selected_conversion_action', 'max_daily_budget_micros', 'ends_at'] as $key) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            $pilot = $this->pilot();
            unset($pilot[$key]);
            $this->assertFalse($inspector->inspect($campaign, $pilot)['ready'], $key);
            $this->assertSame([], $inspector->queries);
        }
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $campaign->customer_id = 999;
        $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready']);
        $this->assertSame([], $inspector->queries);
    }

    public function test_native_flags_shared_or_changed_budgets_and_wrong_inventory_never_pass(): void
    {
        $cases = [
            fn ($s) => $s->configuration['campaign']['aiMaxSetting']['enableAiMax'] = false,
            fn ($s) => $s->configuration['campaign']['assetAutomationSettings'][1]['assetAutomationStatus'] = 'OPTED_IN',
            fn ($s) => $s->configuration['campaign']['assetAutomationSettings'][0]['assetAutomationStatus'] = 'OPTED_OUT',
            fn ($s) => $s->configuration['campaign']['textGuidelines']['messagingRestrictions'] = [],
            fn ($s) => $s->configuration['campaignBudget']['explicitlyShared'] = true,
            fn ($s) => $s->configuration['campaignBudget']['amountMicros'] = '51000000',
            fn ($s) => $s->configuration['campaignBudget']['period'] = 'CUSTOM_PERIOD',
            fn ($s) => $s->configuration['customer']['currencyCode'] = 'USD',
            fn ($s) => $s->configuration['customer']['timeZone'] = 'UTC',
            fn ($s) => $s->snapshot['end_date_time'] = '2026-11-10 23:59:59',
            fn ($s) => $s->snapshot['bidding_strategy'] = BiddingStrategyType::TARGET_SPEND,
            fn ($s) => $s->snapshot['target_cpa_micros'] = 20_000_000,
            fn ($s) => $s->snapshot['portfolio_bidding_strategy'] = 'customers/1234567890/biddingStrategies/42',
            fn ($s) => $s->snapshot['actual_cpc_ceiling_micros'] = 10_000_000,
            fn ($s) => $s->snapshot['positive_geo_target_type'] = PositiveGeoTargetType::PRESENCE_OR_INTEREST,
            fn ($s) => $s->snapshot['geo_target_constants'] = ['geoTargetConstants/2036'],
            fn ($s) => $s->snapshot['search_partners_enabled'] = true,
            fn ($s) => $s->snapshot['display_enabled'] = true,
            fn ($s) => $s->snapshot['unsupported_targeting'] = [['type' => 'unverified_list']],
            fn ($s) => $s->groups[0]['adGroup']['resourceName'] = 'customers/8888888888/adGroups/10',
            fn ($s) => $s->ads[0]['adGroup']['resourceName'] = 'customers/1234567890/adGroups/999',
            fn ($s) => $s->keywordUrls[0]['adGroup']['resourceName'] = 'customers/1234567890/adGroups/999',
        ];
        foreach ($cases as $i => $change) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            $change($inspector);
            $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready'], 'case '.$i);
        }
    }

    public function test_disabled_matching_ad_urls_and_keyword_url_overrides_are_rejected(): void
    {
        foreach (['group', 'ad', 'keyword'] as $case) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            if ($case === 'group') {
                $inspector->groups[0]['adGroup']['aiMaxAdGroupSetting']['disableSearchTermMatching'] = true;
            } elseif ($case === 'ad') {
                $inspector->ads[0]['adGroupAd']['ad']['finalUrls'] = ['https://example.com/monthly-pricing'];
            } else {
                $inspector->keywordUrls[0]['adGroupCriterion']['finalUrls'] = ['https://example.com/one-time-setup?plan=monthly'];
            }
            $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready'], $case);
        }
    }

    public function test_negative_copy_url_and_ad_group_drift_invalidate_the_approved_fingerprint(): void
    {
        foreach (['negative', 'guideline', 'url', 'group'] as $case) {
            $campaign = $this->campaign();
            $inspector = new ScriptedAiMaxInspector($campaign->customer);
            $baseline = $inspector->inspect($campaign, $this->pilot());
            $this->assertTrue($baseline['ready']);
            if ($case === 'negative') {
                $inspector->snapshot['negative_keywords'][] = ['scope' => 'campaign', 'text' => 'agency', 'match_type' => 'BROAD'];
            } elseif ($case === 'guideline') {
                $inspector->configuration['campaign']['textGuidelines']['messagingRestrictions'][0]['restrictionText'] = 'A different offer';
            } elseif ($case === 'url') {
                $inspector->ads[0]['adGroupAd']['ad']['finalUrlSuffix'] = 'plan=monthly';
            } else {
                $inspector->groups[0]['adGroup']['trackingUrlTemplate'] = 'https://tracker.example/?u={lpurl}';
            }
            $result = $inspector->inspect($campaign, [...$this->pilot(), 'settings_fingerprint' => $baseline['fingerprint']]);
            $this->assertFalse($result['ready'], $case);
            $this->assertContains('The Google campaign configuration changed after pilot approval.', $result['issues']);
        }
    }

    public function test_unknown_or_different_conversion_goals_and_provider_failures_fail_closed(): void
    {
        $campaign = $this->campaign();
        $inspector = new ScriptedAiMaxInspector($campaign->customer);
        $inspector->conversion['intent']['action_resource'] = 'customers/1234567890/conversionActions/777';
        $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready']);
        $inspector->conversion = ['ready' => false];
        $this->assertFalse($inspector->inspect($campaign, $this->pilot())['ready']);
        $inspector->unavailable = true;
        $failed = $inspector->inspect($campaign, $this->pilot());
        $this->assertFalse($failed['ready']);
        $this->assertNull($failed['fingerprint']);
        $this->assertSame([], $failed['observed']);
        Exceptions::assertReported(\RuntimeException::class);
    }
}

class ScriptedAiMaxInspector extends InspectAiMaxPilot
{
    public array $queries = [];

    public bool $unavailable = false;

    public array $snapshot;

    public array $configuration;

    public array $groups;

    public array $ads;

    public array $keywordUrls;

    public array $conversion = ['ready' => true, 'intent' => ['category' => 'PURCHASE', 'mode' => 'custom',
        'action_resource' => 'customers/1234567890/conversionActions/444']];

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->snapshot = ['status' => 2, 'channel' => AdvertisingChannelType::SEARCH,
            'bidding_strategy' => BiddingStrategyType::MAXIMIZE_CONVERSIONS, 'portfolio_bidding_strategy' => '',
            'target_cpa_micros' => 0, 'actual_cpc_ceiling_micros' => 0, 'daily_budget_micros' => 50_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true,
            'end_date_time' => '2026-11-09 23:59:59', 'geo_target_constants' => ['geoTargetConstants/2840'],
            'positive_geo_target_type' => PositiveGeoTargetType::PRESENCE, 'google_search_enabled' => true,
            'search_partners_enabled' => false, 'display_enabled' => false, 'unsupported_targeting' => [],
            'negative_keywords' => [], 'keywords' => []];
        $this->configuration = ['customer' => ['currencyCode' => 'AUD', 'timeZone' => 'Australia/Sydney'],
            'campaign' => ['resourceName' => 'customers/1234567890/campaigns/999', 'aiMaxSetting' => ['enableAiMax' => true],
                'assetAutomationSettings' => [['assetAutomationType' => 'TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_IN'],
                    ['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT']],
                'textGuidelines' => ['messagingRestrictions' => [['restrictionText' => 'Do not describe recurring management.',
                    'restrictionType' => 'RESTRICTION_BASED_EXCLUSION']]]],
            'campaignBudget' => ['resourceName' => 'customers/1234567890/campaignBudgets/8',
                'amountMicros' => '50000000', 'explicitlyShared' => false, 'period' => 'DAILY']];
        $this->groups = [['adGroup' => ['resourceName' => 'customers/1234567890/adGroups/10', 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD']]];
        $this->ads = [['adGroup' => ['resourceName' => 'customers/1234567890/adGroups/10', 'status' => 'ENABLED'],
            'adGroupAd' => ['status' => 'ENABLED', 'ad' => ['finalUrls' => ['https://example.com/one-time-setup']]]]];
        $this->keywordUrls = [['adGroup' => ['resourceName' => 'customers/1234567890/adGroups/10', 'status' => 'ENABLED'],
            'adGroupCriterion' => ['status' => 'ENABLED']]];
    }

    protected function delivery(Campaign $campaign): ?array
    {
        if ($this->unavailable) {
            throw new \RuntimeException('Provider unavailable.');
        }

        return $this->snapshot;
    }

    protected function goal(Campaign $campaign, array $pilot, string $resource): array
    {
        return $this->conversion;
    }

    protected function rows(string $customerId, string $query): array
    {
        $this->queries[] = $query;

        return match (true) {
            str_contains($query, 'FROM ad_group_ad ') => $this->ads,
            str_contains($query, 'FROM ad_group_criterion ') => $this->keywordUrls,
            str_contains($query, 'FROM ad_group ') => $this->groups,
            str_contains($query, 'FROM campaign ') => [$this->configuration],
            default => throw new \LogicException('Unexpected query.'),
        };
    }
}
