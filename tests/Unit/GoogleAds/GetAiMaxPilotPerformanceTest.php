<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\GetAiMaxPilotPerformance;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Common\Segments;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Resources\AiMaxSearchTermAdCombinationView;
use Google\Ads\GoogleAds\V26\Resources\Campaign\AiMaxSetting;
use Google\Ads\GoogleAds\V26\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V26\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Tests\TestCase;

class GetAiMaxPilotPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-29T19:00:00Z'); // 30 October 06:00 in the actual Google account timezone.
        config(['optimization.search_delivery.reporting_lag_hours' => 3]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $customer = new Customer(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD']);
        $customer->id = 7;
        $campaign = new Campaign(['customer_id' => 7, 'google_ads_campaign_id' => '999',
            'spend_guardrails' => ['ai_max_pilot' => ['starts_at' => '2026-10-26T06:00:00Z', 'ends_at' => '2026-11-09T06:00:00Z']],
            'search_delivery_state' => ['ai_max_setup' => ['customer_id' => '1234567890',
                'campaign_resource' => 'customers/1234567890/campaigns/999',
                'activated_at' => '2026-10-26T06:00:00Z', 'configuration_verified_at' => '2026-10-26T06:00:01Z']]]);
        $campaign->setRelation('customer', $customer);

        return [$campaign, new ScriptedAiMaxPerformance($customer)];
    }

    public function test_reports_only_complete_account_days_after_activation_with_same_period_cpa(): void
    {
        [$campaign, $reporter] = $this->fixture();
        $before = $campaign->getAttributes();
        $result = $reporter->inspect($campaign);
        $this->assertSame('observed', $result['status']);
        $this->assertSame('Australia/Sydney', $result['time_zone']);
        $this->assertSame('AUD', $result['currency_code']);
        $this->assertSame('2026-10-27', $result['period']['from']);
        $this->assertSame('2026-10-29', $result['period']['through']);
        $this->assertSame(3, $result['period']['complete_days']);
        $this->assertSame(5, $result['metrics']['clicks']);
        $this->assertSame(60, $result['metrics']['impressions']);
        $this->assertSame(30_000_000, $result['metrics']['cost_micros']);
        $this->assertSame(2.0, $result['metrics']['conversions']);
        $this->assertSame(401_000_000, $result['metrics']['conversion_value_micros']);
        $this->assertSame(15_000_000, $result['metrics']['cpa_micros']);
        foreach (array_slice($reporter->queries, 1) as $query) {
            $this->assertStringContainsString("segments.date BETWEEN '2026-10-27' AND '2026-10-29'", $query);
            $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/999'", $query);
        }
        $this->assertSame($before, $campaign->getAttributes(), 'Observation must not change holds, policy or fingerprints.');
    }

    public function test_first_partial_day_and_unverified_activation_collect_evidence_without_fake_zeros(): void
    {
        [$campaign, $reporter] = $this->fixture();
        CarbonImmutable::setTestNow('2026-10-26T10:00:00Z');
        $result = $reporter->inspect($campaign);
        $this->assertSame('collecting_evidence', $result['status']);
        $this->assertNull($result['metrics']);
        $this->assertNull($result['period']['through']);
        $this->assertCount(1, $reporter->queries);
        $reporter->queries = [];
        $campaign->search_delivery_state = [];
        $this->assertSame('activation_not_verified', $reporter->inspect($campaign)['reason']);
        $this->assertSame([], $reporter->queries);
    }

    public function test_reporting_lag_and_pilot_end_bound_the_complete_day_window(): void
    {
        [$campaign, $reporter] = $this->fixture();
        CarbonImmutable::setTestNow('2026-10-28T13:30:00Z'); // 00:30 Sydney: yesterday is not three hours old yet.
        $this->assertSame('2026-10-27', $reporter->inspect($campaign)['period']['through']);
        CarbonImmutable::setTestNow('2026-11-20T12:00:00Z');
        $result = $reporter->inspect($campaign);
        $this->assertSame('2026-11-08', $result['period']['through'], 'The pilot ends during 9 November; exclude that partial day.');
    }

    public function test_native_combinations_are_limited_and_identifiers_are_not_returned(): void
    {
        [$campaign, $reporter] = $this->fixture();
        $reporter->combinations = [];
        for ($i = 0; $i < 24; $i++) {
            $reporter->combinations[] = $reporter->combination($i === 0 ? 'email josh@example.com' : 'google ads setup',
                'https://example.com/one-time-setup?gclid=SECRET&id=123#personal');
        }
        $result = $reporter->inspect($campaign);
        $this->assertSame('observed', $result['combinations']['status']);
        $this->assertCount(20, $result['combinations']['rows']);
        $this->assertSame('[redacted]', $result['combinations']['rows'][0]['search_term']);
        $this->assertSame('https://example.com/one-time-setup', $result['combinations']['rows'][0]['landing_page']);
        $this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('josh@example.com', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('aiMaxSearchTermAdCombinationViews', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('LIMIT 20', $reporter->queries[2]);
    }

    public function test_api_failures_are_unavailable_and_do_not_become_zero_or_change_campaign_state(): void
    {
        [$campaign, $reporter] = $this->fixture();
        $before = $campaign->getAttributes();
        $reporter->failure = 'metrics';
        $failed = $reporter->inspect($campaign);
        $this->assertSame('unavailable', $failed['status']);
        $this->assertNull($failed['metrics']);
        $this->assertSame($before, $campaign->getAttributes());
        $reporter->failure = 'combinations';
        $partial = $reporter->inspect($campaign);
        $this->assertSame('partial', $partial['status']);
        $this->assertSame(30_000_000, $partial['metrics']['cost_micros']);
        $this->assertSame('unavailable', $partial['combinations']['status']);
        $reporter->failure = null;
        $reporter->daily = [];
        $empty = $reporter->inspect($campaign);
        $this->assertSame('observed', $empty['status'], 'A successful query can report Google-suppressed zero-activity dates.');
        $this->assertSame(0, $empty['metrics']['cost_micros']);
        $this->assertNull($empty['metrics']['cpa_micros']);
    }

    public function test_missing_metrics_or_unverified_native_mode_never_look_like_success(): void
    {
        [$campaign, $reporter] = $this->fixture();
        $reporter->daily[1]->setMetrics(new Metrics(['clicks' => 3]));
        $this->assertSame('unavailable', $reporter->inspect($campaign)['status']);
        $reporter->context->getCampaign()->getAiMaxSetting()->setEnableAiMax(false);
        $this->assertSame('unavailable', $reporter->inspect($campaign)['status']);
        $campaign->customer_id = 999;
        $reporter->queries = [];
        $this->assertSame('unavailable', $reporter->inspect($campaign)['status']);
        $this->assertSame([], $reporter->queries);
    }
}

class ScriptedAiMaxPerformance extends GetAiMaxPilotPerformance
{
    public array $queries = [];

    public array $daily;

    public array $combinations;

    public ?string $failure = null;

    public GoogleAdsRow $context;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->context = new GoogleAdsRow(['campaign' => new GoogleCampaign(['resource_name' => 'customers/1234567890/campaigns/999',
            'ai_max_setting' => new AiMaxSetting(['enable_ai_max' => true]), 'bidding_strategy_type' => BiddingStrategyType::MAXIMIZE_CONVERSIONS]),
            'customer' => new GoogleCustomer(['currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney'])]);
        $this->daily = [$this->daily('2026-10-26', 500, 200, 900_000_000, 40, 40000),
            $this->daily('2026-10-27', 20, 2, 10_000_000, 0.5, 100.25), $this->daily('2026-10-28', 40, 3, 20_000_000, 1.5, 300.75)];
        $this->combinations = [$this->combination('google ads setup', 'https://example.com/one-time-setup')];
    }

    private function daily(string $date, int $impressions, int $clicks, int $cost, float $conversions, float $value): GoogleAdsRow
    {
        return new GoogleAdsRow(['campaign' => new GoogleCampaign(['resource_name' => 'customers/1234567890/campaigns/999']),
            'segments' => new Segments(['date' => $date]), 'metrics' => new Metrics(['impressions' => $impressions,
                'clicks' => $clicks, 'cost_micros' => $cost, 'conversions' => $conversions, 'conversions_value' => $value])]);
    }

    public function combination(string $term, string $url): GoogleAdsRow
    {
        return new GoogleAdsRow(['campaign' => new GoogleCampaign(['resource_name' => 'customers/1234567890/campaigns/999']),
            'ai_max_search_term_ad_combination_view' => new AiMaxSearchTermAdCombinationView(['resource_name' => 'customers/1234567890/aiMaxSearchTermAdCombinationViews/ENCODED_IDENTIFIER',
                'search_term' => $term, 'headline' => 'US$999 one-time setup', 'landing_page' => $url]),
            'metrics' => new Metrics(['impressions' => 10, 'clicks' => 1, 'cost_micros' => 5_000_000, 'conversions' => 0.0, 'conversions_value' => 0.0])]);
    }

    protected function rows(string $customerId, string $query): iterable
    {
        $this->queries[] = $query;
        if (str_contains($query, 'customer.currency_code')) {
            return [$this->context];
        }
        if (str_contains($query, 'FROM ai_max_search_term_ad_combination_view')) {
            if ($this->failure === 'combinations') {
                throw new \RuntimeException('Native reporting unavailable.');
            }

            return $this->combinations;
        }
        if ($this->failure === 'metrics') {
            throw new \RuntimeException('Campaign reporting unavailable.');
        }
        preg_match("/BETWEEN '([0-9-]+)' AND '([0-9-]+)'/", $query, $dates);

        return array_values(array_filter($this->daily, fn ($row) => $row->getSegments()->getDate() >= $dates[1] && $row->getSegments()->getDate() <= $dates[2]));
    }
}
