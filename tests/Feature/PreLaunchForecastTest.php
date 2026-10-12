<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
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

    private function runPreflight(array $forecast, string $assessmentStatus = 'evidence_ready', string $primaryId = '456', array $previousState = [],
        array $snapshotOverrides = [], array $assessmentOverrides = [], array $campaignOverrides = [], bool $inspectionFails = false,
        array $postResearchChanges = [], ?\Closure $duringResearch = null, array $activationChanges = []): array
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create($campaignOverrides + ['customer_id' => $customer->id, 'google_ads_campaign_id' => $primaryId,
            'search_delivery_state' => $previousState ?: null, 'approved_daily_budget' => '10.00', 'daily_budget' => '10.00',
            'budget_confirmed_at' => now(), 'end_date' => now()->addDays(40)]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $snapshot = $snapshotOverrides + ['status' => GoogleCampaignStatus::PAUSED, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'bidding_strategy' => BiddingStrategyType::TARGET_SPEND,
            'actual_cpc_ceiling_micros' => 3_000_000, 'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000,
            'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT']],
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'excluded_geo_target_constants' => [], 'ad_schedule' => [], 'device_bid_modifiers' => [],
            'unsupported_targeting' => [], 'search_partners_enabled' => false];
        $forecast += ['source' => 'google_keyword_forecast', 'checked_at' => now()->toIso8601String(), 'caveats' => [],
            'period_days' => 30, 'cost_micros' => 30_000_000,
            'forecast_period' => ['from' => now('Australia/Sydney')->addDay()->toDateString(), 'through' => now('Australia/Sydney')->addDays(30)->toDateString()],
            'context' => ['bidding_strategy' => 'MAXIMIZE_CLICKS', 'currency_code' => $snapshot['currency_code'],
                'time_zone' => $snapshot['time_zone'], 'cpc_bid_ceiling_micros' => $snapshot['actual_cpc_ceiling_micros'],
                'daily_budget_micros' => $snapshot['daily_budget_micros'], 'keywords' => $snapshot['keywords'],
                'geo_target_constants' => $snapshot['geo_target_constants'], 'language_constants' => $snapshot['language_constants'],
                'negative_keywords' => $snapshot['negative_keywords']]];
        $assessment = $assessmentOverrides + ['status' => $assessmentStatus, 'forecast' => $forecast, 'issues' => [],
            'historical' => ['success' => true, 'checked_at' => now()->toIso8601String(),
                'keywords' => ['managed ppc services' => ['text' => 'managed ppc services', 'avg_monthly_searches' => 40]]]];
        $inspector = new class($snapshot, $inspectionFails, $postResearchChanges, $activationChanges) extends InspectSearchDelivery
        {
            public int $calls = 0;

            public function __construct(private array $snapshot, private bool $fails, private array $afterResearch, private array $activation) {}

            public function inspect(Campaign $campaign, ?\DateTimeInterface $since = null): array
            {
                if ($campaign->google_ads_campaign_id !== 'customers/1234567890/campaigns/456') {
                    throw new \RuntimeException('Inspected an old campaign instead of the staged resource.');
                }
                if ($this->fails) {
                    throw new \RuntimeException('Read-only inspection failed.');
                }

                $this->calls++;

                return ($this->calls > 2 ? $this->activation : ($this->calls === 2 ? $this->afterResearch : [])) + $this->snapshot;
            }
        };
        $this->app->bind(InspectSearchDelivery::class, fn () => $inspector);
        $test = $this;
        if (! empty($campaign->spend_guardrails['enabled'])) {
            $spendReader = new class($snapshot['reported_lifetime_cost_micros'] ?? 0) extends GetCampaignSpendSafety
            {
                public function __construct(private int $spend) {}

                public function forCampaign(Campaign $campaign): array
                {
                    return ['cost_micros' => $this->spend];
                }
            };
            $this->app->bind(GetCampaignSpendSafety::class, fn () => $spendReader);
            $snapshot['reported_lifetime_cost_micros'] = $snapshot['reported_lifetime_cost_micros'] ?? 0;
            $snapshot['reported_spend_checked_at'] = now()->toIso8601String();
        }
        $planner = new class($campaign->id, $snapshot, $assessment, $test, $duringResearch) extends GoogleSearchReachPlanner
        {
            public function __construct(private int $id, private array $snapshot, private array $assessment, private PreLaunchForecastTest $test,
                private ?\Closure $duringResearch) {}

            public function assessMutation(Campaign $campaign, array $snapshot, array $keywords): array
            {
                $this->test->assertSame($this->id, $campaign->id);
                $this->test->assertSame($this->snapshot, $snapshot);
                $this->test->assertSame(array_values(array_filter($snapshot['keywords'], fn ($keyword) => ($keyword['status'] ?? 'ENABLED') === 'ENABLED'
                    && ($keyword['ad_group_status'] ?? 'ENABLED') === 'ENABLED')), $keywords);
                if ($this->duringResearch) {
                    ($this->duringResearch)($campaign);
                }

                return $this->assessment;
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
        $this->assertContains('search_reach_below_useful_threshold', array_column($result->errors, 'code'));
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

    public function test_unmodeled_targeting_blocks_launch_instead_of_treating_unknown_as_ready(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => false, 'clicks' => 0, 'impressions' => 0]);
        $this->assertFalse($allowed);
        $this->assertFalse($result->success);
        $this->assertContains('search_reach_forecast_unverified', array_column($result->errors, 'code'));
    }

    public function test_provider_failure_is_saved_as_unknown_and_blocks_serving_ads_until_retried(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => false], 'unavailable');
        $this->assertFalse($allowed);
        $this->assertFalse($result->success);
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
        $this->assertFalse($allowed);
        $this->assertFalse($result->success);
        $this->assertSame($previous['repair_attempts'], $campaign->search_delivery_state['repair_attempts']);
        $this->assertSame($previous['repair'], $campaign->search_delivery_state['repair']);
        $this->assertSame('needs_review', $campaign->search_delivery_state['status']);
        $this->assertNull($campaign->search_delivery_state['measurement'], 'Old traffic cannot certify the retried deployment.');
    }

    public function test_verified_low_volume_keywords_can_launch_when_the_whole_set_has_useful_forecast_reach(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 45, 'impressions' => 120]);
        $this->assertTrue($allowed, 'A per-keyword arbitrary volume floor must not replace the useful full-set forecast.');
        $this->assertTrue($result->success);
        $this->assertSame(40, $result->metadata['search_reach_preflight']['gate']['metadata']['verified_keyword_demand']['managed ppc services']);
        $this->assertSame('collecting_evidence', $campaign->search_delivery_state['status']);
    }

    public function test_positive_but_insufficient_forecast_is_blocked_and_recorded(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 3.63, 'impressions' => 30]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_below_useful_threshold', array_column($result->errors, 'code'));
        $this->assertEquals(3.63, $result->metadata['search_reach_preflight']['gate']['metadata']['forecast_clicks_per_30_days']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'search_reach_preflight_blocked')->count());
    }

    public function test_unknown_keyword_demand_never_becomes_zero_or_launch_authorization(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            assessmentOverrides: ['historical' => ['success' => true, 'checked_at' => now()->toIso8601String(),
                'keywords' => ['managed ppc services' => ['avg_monthly_searches' => null]]]]);
        $this->assertFalse($allowed);
        $gate = $result->metadata['search_reach_preflight']['gate'];
        $this->assertSame('unavailable', $gate['status']);
        $this->assertSame([], $gate['metadata']['verified_keyword_demand']);
        $this->assertSame(['managed ppc services'], $gate['metadata']['unknown_keyword_demand']);
    }

    public function test_an_unknown_term_can_coexist_with_verified_set_demand_and_useful_full_set_forecast(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            snapshotOverrides: ['keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT'],
                ['text' => 'new commercial close variant', 'match_type' => 'PHRASE']]]);
        $this->assertTrue($allowed);
        $gate = $result->metadata['search_reach_preflight']['gate'];
        $this->assertSame(['managed ppc services' => 40], $gate['metadata']['verified_keyword_demand']);
        $this->assertSame(['new commercial close variant'], $gate['metadata']['unknown_keyword_demand']);
    }

    public function test_unused_manual_bid_fields_do_not_change_a_maximize_clicks_forecast_context(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100,
            'context' => ['bidding_strategy' => 'MAXIMIZE_CLICKS', 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
                'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000,
                'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT']],
                'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'], 'negative_keywords' => []]],
            snapshotOverrides: ['keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT', 'cpc_bid_micros' => 1_000_000]]]);
        $this->assertTrue($allowed);
        $this->assertTrue($result->success);
    }

    public function test_manual_cpc_requires_verified_effective_keyword_bids_in_the_matching_forecast(): void
    {
        $context = ['bidding_strategy' => 'MANUAL_CPC', 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000,
            'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT']],
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'], 'negative_keywords' => []];
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100,
            'context' => $context], snapshotOverrides: ['bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
                'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT', 'cpc_bid_micros' => 0]]]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_manual_keyword_bid_unverified', array_column($result->errors, 'code'));
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100,
            'context' => $context], snapshotOverrides: ['bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
                'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT', 'cpc_bid_micros' => 1_000_000]]]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_forecast_context_mismatch', array_column($result->errors, 'code'));
    }

    public function test_missing_fresh_forecast_proof_cannot_pass_a_positive_traffic_estimate(): void
    {
        foreach ([['checked_at' => null], ['checked_at' => now()->subHours(7)->toIso8601String()],
            ['forecast_period' => ['from' => now('Australia/Sydney')->subDays(29)->toDateString(), 'through' => now('Australia/Sydney')->toDateString()]],
            ['caveats' => ['An omitted restriction.']]] as $proof) {
            [$allowed, $result] = $this->runPreflight($proof + ['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100]);
            $this->assertFalse($allowed);
            $this->assertContains('search_reach_forecast_unverified', array_column($result->errors, 'code'));
        }
    }

    public function test_forecast_must_fit_google_serving_dates_even_when_local_end_date_is_later(): void
    {
        foreach ([['end_date' => now('Australia/Sydney')->addDays(10)->toDateString()],
            ['end_date' => now('Australia/Sydney')->subDay()->format('Ymd')],
            ['start_date' => now('Australia/Sydney')->addDays(5)->toDateString()]] as $dates) {
            [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
                snapshotOverrides: $dates);
            $this->assertFalse($allowed);
            $this->assertContains('search_reach_forecast_outside_live_dates', array_column($result->errors, 'code'));
        }
    }

    public function test_forecast_context_must_match_live_bid_budget_currency_match_type_and_negatives(): void
    {
        $context = ['bidding_strategy' => 'MAXIMIZE_CLICKS', 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000,
            'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT']],
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'], 'negative_keywords' => []];
        foreach ([['cpc_bid_ceiling_micros' => 4_000_000], ['daily_budget_micros' => 20_000_000], ['currency_code' => 'USD'],
            ['keywords' => [['text' => 'managed ppc services', 'match_type' => 'PHRASE']]],
            ['negative_keywords' => [['text' => 'free', 'match_type' => 'BROAD']]], ['geo_target_constants' => ['geoTargetConstants/2840']]] as $mismatch) {
            [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100,
                'context' => $mismatch + $context]);
            $this->assertFalse($allowed);
            $this->assertContains('search_reach_forecast_context_mismatch', array_column($result->errors, 'code'));
        }
    }

    public function test_read_only_inspection_failure_holds_deployment_before_serving_ads(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight([], inspectionFails: true);
        $this->assertFalse($allowed);
        $this->assertFalse($result->success);
        $this->assertSame('search_reach_unavailable', $result->errors[0]->code);
        $this->assertSame('unavailable', $campaign->search_delivery_state['status']);
        $this->assertFalse($result->metadata['search_reach_preflight']['gate']['ready']);
    }

    public function test_demand_warnings_do_not_erase_the_blocking_gate_reason_or_fail_activity_recording(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 3, 'impressions' => 30],
            assessmentOverrides: ['issues' => [['code' => 'limited_keyword_demand', 'message' => 'Current terms have low measured demand.']]]);
        $this->assertFalse($allowed);
        $this->assertSame('limited_keyword_demand', $result->warnings[0]->code);
        $this->assertSame('search_reach_below_useful_threshold', $result->errors[0]->code);
        $this->assertSame('search_reach_below_useful_threshold', $campaign->search_delivery_state['blocked_reason']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'search_reach_preflight_blocked')->count());
    }

    public function test_unapproved_or_excessive_daily_budget_and_unmodeled_restrictions_block_launch(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            campaignOverrides: ['budget_confirmed_at' => null]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_approved_budget_unverified', array_column($result->errors, 'code'));
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            snapshotOverrides: ['daily_budget_micros' => 11_000_000, 'unsupported_targeting' => ['shared_negative_list']]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_approved_budget_exceeded', array_column($result->errors, 'code'));
        $this->assertContains('search_reach_restrictions_unmodeled', array_column($result->errors, 'code'));
    }

    public function test_trial_forecast_uses_the_remaining_reported_spend_envelope_and_an_explicit_short_horizon(): void
    {
        $trial = ['enabled' => true, 'baseline_cost_micros' => 100_000_000, 'max_spend_micros' => 50_000_000,
            'max_daily_budget_micros' => 10_000_000, 'max_cpc_bid_micros' => 3_000_000];
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100,
            'cost_micros' => 45_000_000], snapshotOverrides: ['reported_lifetime_cost_micros' => 110_000_000],
            campaignOverrides: ['spend_guardrails' => $trial]);
        $this->assertFalse($allowed);
        $this->assertSame(40_000_000, $result->metadata['search_reach_preflight']['gate']['metadata']['trial_remaining_reported_spend_micros']);
        $this->assertContains('search_reach_trial_remaining_spend_exceeded', array_column($result->errors, 'code'));
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 10, 'impressions' => 50,
            'cost_micros' => 30_000_000, 'period_days' => 10,
            'forecast_period' => ['from' => now('Australia/Sydney')->addDay()->toDateString(), 'through' => now('Australia/Sydney')->addDays(10)->toDateString()]],
            snapshotOverrides: ['reported_lifetime_cost_micros' => 110_000_000], campaignOverrides: ['spend_guardrails' => $trial]);
        $this->assertTrue($allowed);
        $this->assertEquals(30, $result->metadata['search_reach_preflight']['gate']['metadata']['forecast_clicks_per_30_days']);
        $this->assertSame(10, $result->metadata['search_reach_preflight']['gate']['metadata']['forecast_period_days']);
    }

    public function test_forecast_after_campaign_end_does_not_authorize_a_shorter_live_campaign(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            campaignOverrides: ['end_date' => now()->addDays(14)]);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_forecast_after_campaign_end', array_column($result->errors, 'code'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedServingSettings')]
    public function test_targeting_and_settings_changed_during_forecasting_do_not_authorize_serving(array $changes): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            postResearchChanges: $changes);
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_settings_changed_during_forecast', array_column($result->errors, 'code'));
        $this->assertSame('needs_review', $campaign->search_delivery_state['status']);
    }

    public static function changedServingSettings(): array
    {
        return [['changes' => ['negative_keywords' => [['text' => 'services', 'match_type' => 'PHRASE', 'scope' => 'campaign']]]],
            ['changes' => ['geo_target_constants' => ['geoTargetConstants/2840']]],
            ['changes' => ['positive_geo_target_type' => 6]], ['changes' => ['portfolio_bidding_strategy' => 'biddingStrategies/789']],
            ['changes' => ['display_enabled' => true]], ['changes' => ['ad_group_targeting_modes' => ['adGroups/1' => 'targeting']]],
            ['changes' => ['audience_criteria' => [['resource_name' => 'criteria/1', 'type' => 'USER_LIST']]]]];
    }

    public function test_customer_budget_approval_lowered_during_forecasting_is_rechecked(): void
    {
        [$allowed, $result] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            duringResearch: fn ($campaign) => $campaign->fresh()->update(['approved_daily_budget' => '5.00']));
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_approved_budget_exceeded', array_column($result->errors, 'code'));
        $this->assertSame(5_000_000, $result->metadata['search_reach_preflight']['gate']['metadata']['approved_daily_budget_micros']);
    }

    public function test_owner_pause_during_forecasting_is_preserved_and_holds_launch(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            duringResearch: fn ($campaign) => $campaign->fresh()->update(['status' => 'paused']));
        $this->assertFalse($allowed);
        $this->assertContains('search_reach_launch_authorization_changed', array_column($result->errors, 'code'));
        $this->assertSame(\App\Enums\CampaignStatus::Paused, $campaign->status);
    }

    public function test_immediate_activation_rejects_a_changed_remote_setting_after_saved_reach_proof(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100],
            activationChanges: ['daily_budget_micros' => 20_000_000]);
        $this->assertTrue($allowed);
        $campaign->update(['status' => 'active']);
        $activation = ExecutionResult::success();
        $ready = (new SearchKeywordBuilder($campaign->customer))->confirmActivationProof($campaign, '1234567890',
            'customers/1234567890/campaigns/456', $result->metadata['search_reach_preflight'], $activation);
        $this->assertFalse($ready);
        $this->assertContains('search_reach_activation_settings_changed', array_column($activation->errors, 'code'));
        $this->assertContains('search_reach_approved_budget_exceeded', array_column($activation->errors, 'code'));
    }

    public function test_immediate_activation_uses_current_customer_approval_with_unchanged_remote_settings(): void
    {
        [$allowed, $result, $campaign] = $this->runPreflight(['success' => true, 'auto_repair_safe' => true, 'clicks' => 40, 'impressions' => 100]);
        $this->assertTrue($allowed);
        $campaign->update(['status' => 'active', 'approved_daily_budget' => '5.00']);
        $activation = ExecutionResult::success();
        $ready = (new SearchKeywordBuilder($campaign->customer))->confirmActivationProof($campaign, '1234567890',
            'customers/1234567890/campaigns/456', $result->metadata['search_reach_preflight'], $activation);
        $this->assertFalse($ready);
        $this->assertContains('search_reach_approved_budget_exceeded', array_column($activation->errors, 'code'));
    }
}
