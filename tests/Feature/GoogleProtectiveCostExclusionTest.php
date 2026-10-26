<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V26\Services\KeywordForecastMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleProtectiveCostExclusionTest extends TestCase
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
            'google_search_enabled' => true, 'search_partners_enabled' => false, 'approved_ads' => 1,
            'start_date' => now('Australia/Sydney')->subDays(5)->toDateString(),
            'end_date' => now('Australia/Sydney')->addDays(31)->toDateString(),
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true,
            'daily_budget_micros' => 50_000_000, 'actual_cpc_ceiling_micros' => 10_000_000, 'cpc_bid_ceiling_micros' => 10_000_000,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'excluded_geo_target_constants' => [],
            'language_constants' => ['languageConstants/1000'], 'negative_keywords' => [], 'ad_schedule' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'keywords' => [[
                'text' => 'google ads setup', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'ad_group_status' => 'ENABLED',
                'ad_group_resource' => 'customers/1234567890/adGroups/7', 'resource_name' => 'customers/1234567890/adGroupCriteria/7~1',
                'cpc_bid_micros' => 10_000_000, 'ad_group_cpc_bid_micros' => 10_000_000, 'keyword_cpc_bid_micros' => 0,
            ]]];
        $today = now('Australia/Sydney')->startOfDay();
        $row = ['search_term' => 'google ads setup jobs', 'campaign_resource_name' => $campaign->googleAdsResourceName(),
            'ad_group_resource_name' => 'customers/1234567890/adGroups/7', 'clicks' => 2, 'cost_micros' => 40_000_000,
            'conversions' => 0, 'all_conversions' => 0, 'account_timezone' => 'Australia/Sydney',
            'reported_from' => $today->copy()->subDays(30)->toDateString()];
        $reports = ['LAST_30_DAYS_INCLUDING_TODAY' => [[...$row, 'matured' => false, 'reported_through' => $today->toDateString()]],
            'MATURED_LAST_30_DAYS' => [[...$row, 'matured' => true, 'matured_through' => $today->copy()->subDays(3)->toDateString()]]];
        $planner = new ProtectiveRealForecastPlanner;

        return [$campaign, new ProtectiveCostMutationGuard($planner, $snapshot, $reports), $planner];
    }

    private function change(): array
    {
        return ['action' => 'negative', 'text' => 'google ads setup jobs', 'match_type' => 'EXACT', 'scope' => 'campaign'];
    }

    public function test_real_v26_negative_caveat_is_retained_for_cost_containment_and_still_blocks_normal_mutations(): void
    {
        [$campaign, $guard] = $this->fixture();
        $result = $guard->assessCostExclusions($campaign, [$this->change()]);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertTrue($result['reach_not_verified']);
        $this->assertTrue($result['cost_containment_only']);
        $this->assertSame('cost_containment_only', $result['status']);
        $this->assertFalse($result['assessment']['forecast']['auto_repair_safe']);
        $this->assertSame('negative_keywords_unmodeled', $result['assessment']['forecast']['limitations'][0]['code']);
        $this->assertNotEmpty($result['assessment']['forecast']['caveats']);
        $this->assertSame(3, $guard->inspections);
        $this->assertSame(4, $guard->reportsRead);
        $this->assertSame(25_000_000, $result['cost_containment_evidence']['cost_threshold_micros']);
        $this->assertFalse($guard->assess($campaign, [$this->change()])['ready']);
        $this->assertSame(50_000_000, $result['snapshot']['daily_budget_micros']);
        $this->assertSame(10_000_000, $result['snapshot']['actual_cpc_ceiling_micros']);
    }

    public function test_recent_secondary_conversions_in_any_group_and_unknown_or_unmatured_cost_block_exclusion(): void
    {
        foreach (['recent_conversion', 'secondary_other_group', 'recent_only', 'wrong_timezone', 'stale_window', 'one_click', 'below_cost'] as $case) {
            [$campaign, $guard] = $this->fixture();
            if ($case === 'recent_conversion') {
                $guard->reports['LAST_30_DAYS_INCLUDING_TODAY'][0]['conversions'] = 1;
            } elseif ($case === 'secondary_other_group') {
                $guard->reports['LAST_30_DAYS_INCLUDING_TODAY'][] = [...$guard->reports['LAST_30_DAYS_INCLUDING_TODAY'][0],
                    'ad_group_resource_name' => 'customers/1234567890/adGroups/8', 'all_conversions' => 1];
            } elseif ($case === 'recent_only') {
                $guard->reports['MATURED_LAST_30_DAYS'] = [];
            } else {
                $field = match ($case) {
                    'wrong_timezone' => 'account_timezone', 'stale_window' => 'matured_through', 'one_click' => 'clicks', 'below_cost' => 'cost_micros'
                };
                $guard->reports['MATURED_LAST_30_DAYS'][0][$field] = match ($case) {
                    'wrong_timezone' => 'UTC', 'stale_window' => now()->subDays(10)->toDateString(), 'one_click' => 1, 'below_cost' => 24_999_999,
                };
            }
            $this->assertFalse($guard->assessCostExclusions($campaign, [$this->change()])['ready'], $case);
        }
    }

    public function test_forged_expansion_phrase_or_conflicting_exact_changes_are_not_protective_cost_exclusions(): void
    {
        foreach ([['action' => 'add'], ['action' => 'bid', 'bid_micros' => 1], ['match_type' => 'PHRASE'],
            ['scope' => 'ad_group'], ['text' => 'google ads setup'], ['cost_micros' => 99_000_000]] as $override) {
            [$campaign, $guard] = $this->fixture();
            $this->assertFalse($guard->assessCostExclusions($campaign, [array_replace($this->change(), $override)])['ready'], json_encode($override));
        }
    }

    public function test_low_traffic_unknown_demand_stale_forecast_or_additional_caveats_still_fail_closed(): void
    {
        foreach (['low_clicks', 'unknown_demand', 'stale_forecast', 'extra_caveat'] as $case) {
            [$campaign, $guard, $planner] = $this->fixture();
            $planner->case = $case;
            $result = $guard->assessCostExclusions($campaign, [$this->change()]);
            $this->assertFalse($result['ready'], $case);
            $this->assertNotEmpty($result['issues']);
        }
    }

    public function test_permissions_and_settings_are_rechecked_after_the_final_provider_calls(): void
    {
        foreach (['hold', 'healing_disabled', 'remote_bid', 'new_conversion'] as $case) {
            [$campaign, $guard] = $this->fixture();
            $guard->onReport = function (int $count) use ($campaign, $guard, $case): void {
                if ($count !== 3) {
                    return;
                }
                match ($case) {
                    'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]),
                    'healing_disabled' => Feature::for($campaign->customer)->deactivate(AutoHealing::class),
                    'remote_bid' => $guard->snapshot['daily_budget_micros'] = 40_000_000,
                    'new_conversion' => $guard->reports['LAST_30_DAYS_INCLUDING_TODAY'][0]['all_conversions'] = 1,
                };
            };
            $this->assertFalse($guard->assessCostExclusions($campaign, [$this->change()])['ready'], $case);
        }
    }
}

class ProtectiveCostMutationGuard extends GoogleSearchMutationGuard
{
    public int $inspections = 0;

    public int $reportsRead = 0;

    public ?\Closure $onReport = null;

    public function __construct(GoogleSearchReachPlanner $planner, public array $snapshot, public array $reports)
    {
        parent::__construct($planner, new GoogleSearchReachGate);
    }

    protected function inspect(Campaign $campaign): ?array
    {
        $this->inspections++;

        return $this->snapshot;
    }

    protected function searchTermReport(Campaign $campaign, string $range): array
    {
        $this->reportsRead++;
        ($this->onReport ?? static fn () => null)($this->reportsRead);

        return $this->reports[$range];
    }
}

class ProtectiveRealForecastPlanner extends GoogleSearchReachPlanner
{
    public string $case = '';

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        return ['success' => true, 'checked_at' => now()->toIso8601String(),
            'keywords' => array_map(fn ($text) => ['text' => $text, 'avg_monthly_searches' => $this->case === 'unknown_demand' ? null : 100], $texts)];
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $provider = new class($this->case) extends GenerateKeywordForecast
        {
            public function __construct(private string $case) {}

            protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
            {
                return new GenerateKeywordForecastMetricsResponse(['campaign_forecast_metrics' => new KeywordForecastMetrics([
                    'clicks' => $this->case === 'low_clicks' ? 2 : 60, 'cost_micros' => 100_000_000,
                ])]);
            }
        };
        $forecast = $provider($customer->cleanGoogleCustomerId(), $keywords, $ceiling / 1_000_000, null, $context['forecast_period_days'], $context);
        if ($this->case === 'stale_forecast') {
            $forecast['checked_at'] = now()->subHours(7)->toIso8601String();
        } elseif ($this->case === 'extra_caveat') {
            $forecast['caveats'][] = 'Another unsupported restriction remains.';
        }

        return $forecast;
    }
}
