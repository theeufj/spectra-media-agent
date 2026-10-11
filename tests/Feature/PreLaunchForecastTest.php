<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PreLaunchForecastTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_resource_from_another_account_is_blocked_without_reading_or_writing_google(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = new Strategy;
        $result = ExecutionResult::success();
        $allowed = (new SearchKeywordBuilder($customer))->preflightReach($campaign, $strategy,
            '1234567890', 'customers/9999999999/campaigns/456', $result);
        $this->assertFalse($allowed);
        $this->assertSame('search_reach_account_mismatch', $result->errors[0]->code);
        $this->assertNull($campaign->fresh()->search_delivery_state);
    }

    public function test_generated_maximize_clicks_strategy_preserves_its_reviewed_cpc_ceiling(): void
    {
        $customer = new Customer;
        $updater = new class extends UpdateCampaignBiddingStrategy
        {
            public array $received = [];

            public function __construct() {}

            public function __invoke(string $customerId, string $campaignResourceName, string $strategy,
                ?float $targetCpa = null, ?float $targetRoas = null, ?int $cpcBidCeilingMicros = null): bool
            {
                $this->received = compact('customerId', 'campaignResourceName', 'strategy', 'cpcBidCeilingMicros');

                return true;
            }
        };
        $applier = new class($customer, $updater) extends BiddingStrategyApplier
        {
            public function __construct(Customer $customer, private UpdateCampaignBiddingStrategy $service)
            {
                parent::__construct($customer);
            }

            protected function updater(): UpdateCampaignBiddingStrategy
            {
                return $this->service;
            }
        };
        $strategy = new Strategy(['bidding_strategy' => ['name' => 'MaximizeClicks',
            'parameters' => ['cpcBidCeilingMicros' => 3_000_000]]]);
        $result = ExecutionResult::success();
        $applier->applyBiddingStrategy('1234567890', 'customers/1234567890/campaigns/456', $strategy, $result);
        $this->assertSame('MAXIMIZE_CLICKS', $updater->received['strategy']);
        $this->assertSame(3_000_000, $updater->received['cpcBidCeilingMicros']);
        $this->assertSame('TARGET_SPEND', $result->metadata['expected_bidding']);
    }

    private function runPreflight(array $forecast, string $assessmentStatus = 'evidence_ready', string $primaryId = '456', array $previousState = []): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => $primaryId,
            'search_delivery_state' => $previousState ?: null]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $snapshot = ['currency_code' => 'AUD', 'cpc_bid_ceiling_micros' => 3_000_000,
            'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT']],
            'geo_target_constants' => ['geoTargetConstants/2036']];
        $inspector = new class($snapshot) extends InspectSearchDelivery
        {
            public function __construct(private array $snapshot) {}

            public function inspect(Campaign $campaign, ?\DateTimeInterface $since = null): array
            {
                if ($campaign->google_ads_campaign_id !== 'customers/1234567890/campaigns/456') {
                    throw new \RuntimeException('Inspected an old campaign instead of the staged resource.');
                }

                return $this->snapshot;
            }
        };
        $this->app->bind(InspectSearchDelivery::class, fn () => $inspector);
        $test = $this;
        $planner = new class($campaign->id, $snapshot, $forecast, $assessmentStatus, $test) extends GoogleSearchReachPlanner
        {
            public function __construct(private int $id, private array $snapshot, private array $forecast,
                private string $status, private PreLaunchForecastTest $test) {}

            public function assess(Campaign $campaign, array $snapshot, bool $research = false): array
            {
                $this->test->assertSame($this->id, $campaign->id);
                $this->test->assertSame($this->snapshot, $snapshot);
                $this->test->assertFalse($research);

                return ['status' => $this->status, 'forecast' => $this->forecast, 'issues' => []];
            }
        };
        $this->app->instance(GoogleSearchReachPlanner::class, $planner);
        $result = ExecutionResult::success();
        $allowed = (new SearchKeywordBuilder($customer))->preflightReach($campaign, $strategy, '1234567890', 'customers/1234567890/campaigns/456', $result);

        return [$allowed, $result, $campaign->fresh()];
    }

    public function test_a_faithful_zero_traffic_forecast_blocks_adding_serving_ads(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 0, 'impressions' => 0]);
        $this->assertFalse($allowed);
        $this->assertFalse($result->success);
        $this->assertSame('search_reach_unviable', $result->errors[0]->code);
        $this->assertSame('needs_review', $campaign->search_delivery_state['status']);
        $this->assertSame('456', $campaign->google_ads_campaign_id, 'The inspected resource must not overwrite the campaign binding.');
    }

    public function test_a_second_search_strategy_is_inspected_without_replacing_the_primary_campaign_status(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true,
            'clicks' => 0, 'impressions' => 0], primaryId: '123');
        $this->assertFalse($allowed);
        $this->assertNotEmpty($result->metadata['search_reach_preflight']);
        $this->assertNull($campaign->search_delivery_state);
        $this->assertSame('123', $campaign->google_ads_campaign_id);
    }

    public function test_unmodeled_targeting_does_not_make_an_uncertain_zero_forecast_a_launch_block(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => false, 'clicks' => 0, 'impressions' => 0]);
        $this->assertTrue($allowed);
        $this->assertTrue($result->success);
    }

    public function test_provider_failure_is_saved_as_unknown_and_can_be_retried_by_the_delivery_monitor(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => false], 'unavailable');
        $this->assertTrue($allowed);
        $this->assertTrue($result->success);
        $this->assertSame('unavailable', $campaign->search_delivery_state['status']);
        $this->assertFalse($campaign->search_delivery_state['mutation_allowed']);
    }

    public function test_deployment_retry_preserves_durable_partial_repair_and_attempt_limit(): void
    {
        $previous = ['status' => 'unavailable', 'repair_attempts' => [now()->subHour()->toIso8601String()],
            'repair' => ['started_at' => now()->subHour()->toIso8601String(), 'added_keywords' => [['resource' => 'created']],
                'errors' => [['code' => 'repair_readback_unavailable', 'message' => 'Read-back needs review.']]],
            'measurement' => ['impressions' => 100]];
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true,
            'clicks' => 40, 'impressions' => 100], previousState: $previous);
        $this->assertTrue($allowed);
        $this->assertTrue($result->success);
        $this->assertSame($previous['repair_attempts'], $campaign->search_delivery_state['repair_attempts']);
        $this->assertSame($previous['repair'], $campaign->search_delivery_state['repair']);
        $this->assertSame('needs_review', $campaign->search_delivery_state['status']);
        $this->assertNull($campaign->search_delivery_state['measurement'], 'Old traffic cannot certify the retried deployment.');
    }
}
