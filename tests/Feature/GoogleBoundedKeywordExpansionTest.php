<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Agents\GoogleSearchReachRecovery;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V26\Services\KeywordForecastMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleBoundedKeywordExpansionTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $customer->update(['service_type' => 'managed', 'currency_code' => 'AUD', 'is_sandbox' => false]);
        Feature::for($customer)->activate(AutoHealing::class);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'daily_budget' => '50.00',
            'approved_daily_budget' => '50.00', 'budget_confirmed_at' => now(), 'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(31), 'spend_guardrails' => ['automation_limits' => [
                'enabled' => true, 'max_cpc_bid_micros' => 10_000_000, 'max_daily_budget_micros' => 50_000_000,
                'currency_code' => 'AUD', 'approved_at' => now()->toIso8601String(), 'source' => 'owner_cost_repair',
            ]]])->load('customer');
        $snapshot = ['status' => 2, 'channel' => 2, 'bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
            'google_search_enabled' => true, 'search_partners_enabled' => false, 'approved_ads' => 1, 'eligible_keywords' => 1,
            'start_date' => now('Australia/Sydney')->subDays(5)->toDateString(),
            'end_date' => now('Australia/Sydney')->addDays(31)->toDateString(),
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true,
            'daily_budget_micros' => 50_000_000, 'actual_cpc_ceiling_micros' => 10_000_000, 'cpc_bid_ceiling_micros' => 10_000_000,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'excluded_geo_target_constants' => [],
            'language_constants' => ['languageConstants/1000'], 'negative_keywords' => [['text' => 'jobs', 'match_type' => 'BROAD', 'scope' => 'campaign']],
            'ad_schedule' => [], 'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'rank_evidence' => ['status' => 'collecting_evidence'],
            'keywords' => [['text' => 'google ads setup', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'ad_group_status' => 'ENABLED',
                'ad_group_resource' => 'customers/1234567890/adGroups/7', 'resource_name' => 'customers/1234567890/adGroupCriteria/7~1',
                'cpc_bid_micros' => 10_000_000, 'ad_group_cpc_bid_micros' => 10_000_000, 'keyword_cpc_bid_micros' => 0]]];
        $planner = new BoundedRealForecastPlanner;

        return [$campaign, new BoundedExpansionMutationGuard($planner, $snapshot), $planner];
    }

    private function change(): array
    {
        return ['action' => 'add', 'text' => 'google ads for landscapers', 'match_type' => 'PHRASE',
            'ad_group_resource' => 'customers/1234567890/adGroups/7', 'bid_micros' => 10_000_000,
            'selection_reason' => 'Landscaping business owners seeking the offered paid campaign setup.'];
    }

    public function test_actual_v26_estimate_can_authorize_bounded_additions_without_claiming_faithful_reach(): void
    {
        [$campaign, $guard] = $this->fixture();
        $result = $guard->assessBoundedExpansion($campaign, [$this->change()]);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertSame('bounded_expansion', $result['status']);
        $this->assertTrue($result['reach_not_verified']);
        $this->assertFalse($result['assessment']['forecast']['auto_repair_safe']);
        $this->assertSame('negative_keywords_unmodeled', $result['assessment']['forecast']['limitations'][0]['code']);
        $this->assertNotEmpty($result['assessment']['forecast']['caveats']);
        $this->assertFalse($guard->assess($campaign, [$this->change()])['ready']);
        $this->assertSame(50_000_000, $result['snapshot']['daily_budget_micros']);
        $this->assertSame(10_000_000, $result['snapshot']['actual_cpc_ceiling_micros']);
    }

    public function test_overbids_broad_or_mixed_changes_missing_profiles_and_negative_conflicts_are_blocked(): void
    {
        foreach (['bid_increase', 'broad', 'mixed', 'too_many', 'no_profile', 'negative_conflict', 'disabled', 'paused'] as $case) {
            [$campaign, $guard] = $this->fixture();
            $changes = [$this->change()];
            match ($case) {
                'bid_increase' => $changes[0]['bid_micros'] = 10_000_001,
                'broad' => $changes[0]['match_type'] = 'BROAD',
                'mixed' => $changes[] = ['action' => 'pause', 'resource_name' => 'customers/1234567890/adGroupCriteria/7~1'],
                'too_many' => $changes = array_fill(0, 4, $this->change()),
                'no_profile' => $campaign->update(['spend_guardrails' => null]),
                'negative_conflict' => $guard->snapshot['negative_keywords'][] = ['text' => 'landscapers', 'match_type' => 'BROAD', 'scope' => 'campaign'],
                'disabled' => Feature::for($campaign->customer)->deactivate(AutoHealing::class),
                'paused' => $campaign->update(['status' => 'paused']),
            };
            $this->assertFalse($guard->assessBoundedExpansion($campaign, $changes)['ready'], $case);
        }
    }

    public function test_insufficient_improvement_unknown_new_demand_and_any_additional_limitation_block_expansion(): void
    {
        foreach (['low_clicks', 'no_improvement', 'unknown_new_demand', 'stale_forecast', 'extra_caveat', 'mismatched_budget'] as $case) {
            [$campaign, $guard, $planner] = $this->fixture();
            $planner->case = $case;
            $this->assertFalse($guard->assessBoundedExpansion($campaign, [$this->change()])['ready'], $case);
        }
    }

    public function test_new_holds_or_changed_live_bids_during_research_cannot_authorize_additions(): void
    {
        foreach (['hold', 'bid', 'healing'] as $case) {
            [$campaign, $guard] = $this->fixture();
            $guard->onInspection = function (int $count) use ($campaign, $guard, $case): void {
                if ($count === 2) {
                    match ($case) {
                        'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]),
                        'bid' => $guard->snapshot['keywords'][0]['cpc_bid_micros'] = 9_000_000,
                        'healing' => Feature::for($campaign->customer)->deactivate(AutoHealing::class),
                    };
                }
            };
            $this->assertFalse($guard->assessBoundedExpansion($campaign, [$this->change()])['ready'], $case);
        }
    }

    private function pendingRepair(): array
    {
        [$campaign, $guard, $planner] = $this->fixture();
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'active', 'deployed_at' => now()->subDays(3)]);
        $snapshot = $guard->snapshot;
        $snapshot['keywords'][] = [...$snapshot['keywords'][0], 'text' => 'google ads for landscapers', 'match_type' => 'PHRASE',
            'resource_name' => 'customers/1234567890/adGroupCriteria/7~2'];
        $at = now()->subHours(30)->toIso8601String();
        $snapshot['measurement'] = ['started_at' => $at, 'from' => $at, 'through' => now()->subHours(3)->toIso8601String(),
            'complete_hours' => 27, 'impressions' => 1, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0];
        $watchdog = new BoundedRollbackWatchdog($planner, $snapshot);
        $fingerprint = (new \ReflectionMethod(GoogleSearchReachRecovery::class, 'fingerprint'))->invoke($watchdog, $campaign, $snapshot);
        $campaign->update(['search_delivery_state' => ['evaluation_started_at' => $at, 'config_fingerprint' => $fingerprint,
            'repair' => ['purpose' => 'bounded_expansion', 'started_at' => $at, 'errors' => [],
                'baseline_keyword_resources' => ['customers/1234567890/adGroupCriteria/7~1'],
                'added_keywords' => [['resource' => 'customers/1234567890/adGroupCriteria/7~2', 'text' => 'google ads for landscapers',
                    'match_type' => 'PHRASE', 'ad_group_resource' => 'customers/1234567890/adGroups/7']]]]]);

        return [$campaign, $watchdog];
    }

    public function test_matured_failed_bounded_test_pauses_only_the_new_keywords_and_keeps_the_spending_limits(): void
    {
        [$campaign, $watchdog] = $this->pendingRepair();
        $state = $watchdog->check($campaign);

        $this->assertSame('bounded_expansion_rolled_back', $state['blocked_reason'], json_encode($state));
        $this->assertSame('verified', $state['repair']['rollback']['status']);
        $this->assertSame([[true, ['customers/1234567890/adGroupCriteria/7~2']], [false, ['customers/1234567890/adGroupCriteria/7~2']]], $watchdog->pauses);
        $this->assertSame('ENABLED', $watchdog->snapshot['keywords'][0]['status']);
        $this->assertSame('PAUSED', $watchdog->snapshot['keywords'][1]['status']);
        $this->assertSame('50.00', $campaign->fresh()->approved_daily_budget);
        $this->assertSame(10_000_000, $campaign->fresh()->spend_guardrails['automation_limits']['max_cpc_bid_micros']);
    }

    public function test_an_unknown_rollback_is_never_replayed_and_new_permissions_stop_the_write(): void
    {
        foreach (['unknown', 'hold'] as $case) {
            [$campaign, $watchdog] = $this->pendingRepair();
            $watchdog->unknownResult = $case === 'unknown';
            if ($case === 'hold') {
                $watchdog->onInspection = function (int $count) use ($campaign): void {
                    if ($count === 3) {
                        $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]);
                    }
                };
            }
            $first = $watchdog->check($campaign);
            $count = count($watchdog->pauses);
            $second = $watchdog->check($campaign);
            $this->assertSame($count, count($watchdog->pauses), $case);
            if ($case === 'unknown') {
                $this->assertSame('bounded_expansion_rollback_unverified', $second['blocked_reason']);
                $this->assertNotEmpty($first['repair']['rollback']['started_at']);
            } else {
                $this->assertCount(1, $watchdog->pauses);
                $this->assertSame('ENABLED', $watchdog->snapshot['keywords'][1]['status']);
            }
        }
    }

    public function test_a_low_volume_forecast_is_not_rolled_back_before_its_researched_measurement_window(): void
    {
        [$campaign, $watchdog] = $this->pendingRepair();
        $state = $campaign->search_delivery_state;
        $state['repair']['verification_after_hours'] = 72;
        $campaign->update(['search_delivery_state' => $state]);

        $result = $watchdog->check($campaign);

        $this->assertSame('verifying', $result['status']);
        $this->assertSame([], $watchdog->pauses);
        $this->assertSame('ENABLED', $watchdog->snapshot['keywords'][1]['status']);
    }

    public function test_an_insufficient_remaining_horizon_requires_review_without_extending_the_campaign(): void
    {
        [$campaign, $watchdog] = $this->pendingRepair();
        $end = now('Australia/Sydney')->toDateString();
        $watchdog->snapshot['end_date'] = $end;
        $state = $campaign->search_delivery_state;
        $state['repair']['verification_after_hours'] = 72;
        $state['config_fingerprint'] = (new \ReflectionMethod(GoogleSearchReachRecovery::class, 'fingerprint'))
            ->invoke($watchdog, $campaign, $watchdog->snapshot);
        $campaign->update(['search_delivery_state' => $state]);

        $result = $watchdog->check($campaign);

        $this->assertSame('insufficient_evaluation_horizon', $result['blocked_reason']);
        $this->assertSame([], $watchdog->pauses);
        $this->assertSame($end, $watchdog->snapshot['end_date']);
        $this->assertSame('50.00', $campaign->fresh()->approved_daily_budget);
    }
}

class BoundedExpansionMutationGuard extends GoogleSearchMutationGuard
{
    public int $inspections = 0;

    public ?\Closure $onInspection = null;

    public function __construct(GoogleSearchReachPlanner $planner, public array $snapshot)
    {
        parent::__construct($planner, new GoogleSearchReachGate);
    }

    protected function inspect(Campaign $campaign): ?array
    {
        ($this->onInspection ?? static fn () => null)(++$this->inspections);

        return $this->snapshot;
    }
}

class BoundedRealForecastPlanner extends GoogleSearchReachPlanner
{
    public string $case = '';

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        return ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => array_map(fn ($text) => [
            'text' => $text, 'avg_monthly_searches' => $this->case === 'unknown_new_demand' && str_contains($text, 'landscapers') ? null : 100,
        ], $texts)];
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $clicks = count($keywords) > 1 ? match ($this->case) {
            'low_clicks' => 5, 'no_improvement' => 1, default => 60,
        } : 1;
        $provider = new class($clicks) extends GenerateKeywordForecast
        {
            public function __construct(private int $clicks) {}

            protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
            {
                return new GenerateKeywordForecastMetricsResponse(['campaign_forecast_metrics' => new KeywordForecastMetrics([
                    'clicks' => $this->clicks, 'cost_micros' => $this->clicks * 1_000_000,
                ])]);
            }
        };
        $result = $provider($customer->cleanGoogleCustomerId(), $keywords, $ceiling / 1_000_000, null, $context['forecast_period_days'], $context);
        if ($this->case === 'stale_forecast') {
            $result['checked_at'] = now()->subHours(7)->toIso8601String();
        } elseif ($this->case === 'extra_caveat') {
            $result['caveats'][] = 'Additional unmodeled targeting.';
        } elseif ($this->case === 'mismatched_budget') {
            $result['context']['daily_budget_micros'] = 51_000_000;
        }

        return $result;
    }
}

class BoundedRollbackWatchdog extends GoogleSearchReachRecovery
{
    public array $pauses = [];

    public bool $unknownResult = false;

    public ?\Closure $onInspection = null;

    private int $inspection = 0;

    public function __construct(GoogleSearchReachPlanner $planner, public array $snapshot)
    {
        parent::__construct($planner);
    }

    protected function inspect(Campaign $campaign, \DateTimeInterface $since): ?array
    {
        ($this->onInspection ?? static fn () => null)(++$this->inspection);

        return $this->snapshot;
    }

    protected function pauseNewKeywords(Customer $customer, array $resources, bool $validateOnly): void
    {
        $this->pauses[] = [$validateOnly, $resources];
        if (! $validateOnly) {
            $this->snapshot['keywords'][1]['status'] = 'PAUSED';
            if ($this->unknownResult) {
                throw new \RuntimeException('Unknown remote result after the pause.');
            }
        }
    }
}
