<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Agents\Optimization\RecommendationApplier;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSearchMutationGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed']);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Active,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61',
            'start_date' => now('Australia/Sydney')->startOfDay()->subDays(3),
            'end_date' => now('Australia/Sydney')->startOfDay()->addDays(30),
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()])->load('customer');
    }

    private function snapshot(): array
    {
        return ['status' => 2, 'channel' => 2, 'bidding_strategy' => BiddingStrategyType::TARGET_SPEND,
            'start_date' => now('Australia/Sydney')->startOfDay()->subDays(3)->toDateString(),
            'end_date' => now('Australia/Sydney')->startOfDay()->addDays(30)->toDateString(),
            'google_search_enabled' => true, 'search_partners_enabled' => false,
            'daily_budget_micros' => 10_000_000, 'actual_cpc_ceiling_micros' => 3_000_000, 'cpc_bid_ceiling_micros' => 3_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'approved_ads' => 1,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'excluded_geo_target_constants' => [],
            'language_constants' => ['languageConstants/1000'], 'negative_keywords' => [], 'ad_schedule' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => [],
            'keywords' => [['text' => 'google ads management', 'match_type' => 'EXACT', 'status' => 'ENABLED',
                'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/10',
                'resource_name' => 'customers/1234567890/adGroupCriteria/10~1', 'ad_group_cpc_bid_micros' => 1_000_000],
                ['text' => 'ppc software', 'match_type' => 'PHRASE', 'status' => 'PAUSED', 'ad_group_status' => 'ENABLED',
                    'ad_group_resource' => 'customers/1234567890/adGroups/10', 'resource_name' => 'customers/1234567890/adGroupCriteria/10~2',
                    'ad_group_cpc_bid_micros' => 1_000_000]]];
    }

    private function change(string $text = 'google ads pricing'): array
    {
        return ['action' => 'add', 'text' => $text, 'match_type' => 'PHRASE',
            'ad_group_resource' => 'customers/1234567890/adGroups/10', 'selection_reason' => 'Commercial research for the offered paid Google Ads service.'];
    }

    private function guard(?array $snapshot = null, ?\Closure $evidence = null): ScriptedSearchMutationGuard
    {
        return new ScriptedSearchMutationGuard(new ScriptedMutationReachPlanner($evidence), $snapshot ?? $this->snapshot());
    }

    public function test_addition_is_checked_as_the_entire_live_portfolio_with_low_known_demand_allowed(): void
    {
        $guard = $this->guard();
        $result = $guard->assess($this->campaign(), [$this->change()]);

        $this->assertTrue($result['ready']);
        $this->assertSame(['google ads management', 'google ads pricing'], array_column($result['assessment']['forecast']['context']['keywords'], 'text'));
        $this->assertSame(['google ads management' => 1, 'google ads pricing' => 1], $result['reach_gate']['metadata']['verified_keyword_demand']);
        $this->assertSame(2, $guard->inspections, 'Settings are inspected again after the provider evidence, immediately before writes.');
        Http::assertNothingSent();
    }

    public function test_stale_unknown_caveated_or_mismatched_forecasts_cannot_enable_a_keyword(): void
    {
        $campaign = $this->campaign();
        $mutations = [
            fn ($e) => array_replace_recursive($e, ['historical' => ['checked_at' => now()->subDays(2)->toIso8601String()]]),
            fn ($e) => array_replace_recursive($e, ['forecast' => ['checked_at' => now()->subDay()->toIso8601String()]]),
            fn ($e) => array_replace_recursive($e, ['forecast' => ['caveats' => ['Targeting cannot be modelled.']]]),
            fn ($e) => array_replace_recursive($e, ['forecast' => ['context' => ['cpc_bid_ceiling_micros' => 4_000_000]]]),
            fn ($e) => array_replace_recursive($e, ['forecast' => ['clicks' => 0, 'impressions' => 0]]),
        ];
        foreach ($mutations as $mutation) {
            $result = $this->guard(evidence: $mutation)->assess($campaign, [['action' => 'enable',
                'resource_name' => 'customers/1234567890/adGroupCriteria/10~2']]);
            $this->assertFalse($result['ready']);
            $this->assertNotEmpty($result['issues']);
        }
        $this->assertFalse($this->guard(evidence: function ($e) {
            $e['historical']['keywords'] = [];

            return $e;
        })->assess($campaign, [$this->change()])['ready']);
    }

    public function test_pausing_or_removing_the_only_active_keyword_is_deferred_without_a_provider_call(): void
    {
        $campaign = $this->campaign();
        foreach (['pause', 'remove'] as $action) {
            $guard = $this->guard();
            $this->assertFalse($guard->assess($campaign, [['action' => $action, 'resource_name' => 'customers/1234567890/adGroupCriteria/10~1']])['ready']);
            $this->assertSame(0, $guard->planner->checks);
        }
    }

    public function test_manual_keyword_bid_changes_and_inherited_addition_bids_are_forecast_exactly(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['bidding_strategy'] = BiddingStrategyType::MANUAL_CPC;
        $snapshot['keywords'][0]['cpc_bid_micros'] = 2_000_000;
        $snapshot['keywords'][1]['cpc_bid_micros'] = 1_000_000;
        $result = $this->guard($snapshot)->assess($this->campaign(), [$this->change(),
            ['action' => 'bid', 'resource_name' => 'customers/1234567890/adGroupCriteria/10~1', 'bid_micros' => 800_000]]);

        $this->assertTrue($result['ready']);
        $this->assertSame([800_000, 1_000_000], array_column($result['assessment']['forecast']['context']['keywords'], 'cpc_bid_micros'));
        $this->assertSame(1_000_000, $result['assessment']['forecast']['context']['cpc_bid_ceiling_micros']);
    }

    public function test_new_hold_settings_change_and_remote_pause_during_research_block_an_otherwise_good_proposal(): void
    {
        foreach (['hold', 'bid', 'remote_pause'] as $change) {
            $campaign = $this->campaign();
            $guard = $this->guard();
            $guard->onInspection = function ($count) use ($change, $campaign, $guard): void {
                if ($count !== 2) {
                    return;
                }
                if ($change === 'hold') {
                    $campaign->fresh()->forceFill(['spend_safety_hold' => ['reason' => 'spend_review']])->save();
                } elseif ($change === 'bid') {
                    $guard->snapshot['actual_cpc_ceiling_micros'] = 4_000_000;
                } else {
                    $guard->snapshot['status'] = 3;
                }
            };
            $result = $guard->assess($campaign, [$this->change()]);
            $this->assertFalse($result['ready']);
            $this->assertStringContainsString('changed during reach research', $result['message']);
        }
    }

    public function test_lowered_local_budget_approval_during_research_invalidates_an_unchanged_google_snapshot(): void
    {
        $campaign = $this->campaign();
        $guard = $this->guard();
        $guard->onInspection = function ($count) use ($campaign): void {
            if ($count === 2) {
                $campaign->fresh()->update(['approved_daily_budget' => '5.00']);
            }
        };
        $result = $guard->assess($campaign, [$this->change()]);
        $this->assertFalse($result['ready']);
        $this->assertContains('search_reach_approved_budget_exceeded', array_column($result['issues'], 'code'));
        $this->assertSame(1, $guard->planner->checks, 'Provider estimates are reusable only if fresh local approval still permits the proposed settings.');
    }

    public function test_duplicate_foreign_group_and_negative_conflicting_additions_are_not_counted_as_extra_reach(): void
    {
        $campaign = $this->campaign();
        $snapshot = $this->snapshot();
        $snapshot['negative_keywords'] = [['text' => 'free', 'match_type' => 'BROAD', 'scope' => 'campaign']];
        $proposals = [$this->change('free google ads'),
            [...$this->change('google ads management'), 'match_type' => 'EXACT'],
            [...$this->change(), 'ad_group_resource' => 'customers/9999999999/adGroups/10']];
        foreach ($proposals as $proposal) {
            $guard = $this->guard($snapshot);
            $this->assertFalse($guard->assess($campaign, [$proposal])['ready']);
            $this->assertSame(0, $guard->planner->checks);
        }
    }

    public function test_exclusions_are_projected_before_additions_even_when_producer_lists_them_last(): void
    {
        $guard = $this->guard();
        $result = $guard->assess($this->campaign(), [$this->change('google ads pricing'),
            ['action' => 'negative', 'text' => 'google ads', 'match_type' => 'PHRASE', 'scope' => 'campaign']]);
        $this->assertFalse($result['ready']);
        $this->assertSame(0, $guard->planner->checks, 'The newly excluded proposal must not inflate the forecast.');
    }

    public function test_trial_evidence_does_not_authorize_automatic_changes_and_owner_approval_still_respects_remaining_spend(): void
    {
        $campaign = $this->campaign();
        $campaign->forceFill(['spend_guardrails' => ['enabled' => true, 'started_at' => now()->subHour()->toIso8601String(),
            'baseline_cost_micros' => 100_000_000, 'max_spend_micros' => 50_000_000,
            'max_cpc_bid_micros' => 3_000_000, 'max_daily_budget_micros' => 10_000_000]])->save();
        $guard = $this->guard();
        $this->assertFalse($guard->assess($campaign, [$this->change()])['ready']);
        $this->assertSame(0, $guard->inspections);
        $guard->lifetimeSpend = 145_000_000;
        $this->assertFalse($guard->assess($campaign, [$this->change()], true)['ready']);
        $guard->lifetimeSpend = 140_000_000;
        $this->assertTrue($guard->assess($campaign, [$this->change()], true)['ready']);
    }

    public function test_unmanaged_customers_cannot_use_automatic_keyword_changes_even_after_queueing(): void
    {
        $campaign = $this->campaign();
        $campaign->customer->update(['service_type' => 'setup_only']);
        $guard = $this->guard();
        $this->assertFalse($guard->assess($campaign, [$this->change()])['ready']);
        $this->assertSame(0, $guard->inspections);
    }

    public function test_capped_bidding_repair_forecasts_the_exact_proposed_strategy_before_it_can_be_written(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['bidding_strategy'] = BiddingStrategyType::MAXIMIZE_CONVERSIONS;
        $snapshot['actual_cpc_ceiling_micros'] = $snapshot['cpc_bid_ceiling_micros'] = 0;
        $campaign = $this->campaign();
        $change = ['action' => 'bidding', 'strategy' => 'MAXIMIZE_CLICKS', 'cpc_bid_ceiling_micros' => 2_000_000,
            'expected_bidding_strategy' => BiddingStrategyType::MAXIMIZE_CONVERSIONS];
        $result = $this->guard($snapshot)->assess($campaign, [$change]);
        $this->assertTrue($result['ready']);
        $this->assertSame(BiddingStrategyType::TARGET_SPEND, $result['assessment']['forecast']['context']['bidding_strategy']);
        $this->assertSame(2_000_000, $result['assessment']['forecast']['context']['cpc_bid_ceiling_micros']);
        $this->assertFalse($this->guard($this->snapshot())->assess($campaign, [$change])['ready'], 'Stale original strategy cannot authorize a new experiment.');
    }

    public function test_negative_recommendations_assess_all_terms_and_their_actual_match_types_before_writes(): void
    {
        $campaign = $this->campaign();
        $guard = $this->guard(evidence: fn ($e) => array_replace_recursive($e, ['forecast' => ['clicks' => 0, 'impressions' => 0]]));
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $result = app(RecommendationApplier::class)->apply($campaign, ['type' => 'NEGATIVE_KEYWORDS',
            'keywords' => ['google ads', 'software'], 'match_type' => 'BROAD'], true);
        $this->assertFalse($result['applied']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame(['google ads', 'software'], array_column($result['reach_evidence']['snapshot']['negative_keywords'], 'text'));
        $this->assertSame(['BROAD', 'BROAD'], array_column($result['reach_evidence']['snapshot']['negative_keywords'], 'match_type'));
        Http::assertNothingSent();
    }

    public function test_remote_inactive_campaign_needs_explicit_owner_review_even_when_local_status_is_active(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['status'] = 3;
        $guard = $this->guard($snapshot);
        $campaign = $this->campaign();
        $this->assertFalse($guard->assess($campaign, [$this->change()])['ready']);
        $this->assertTrue($guard->assess($campaign, [$this->change()], true)['ready']);
    }

    public function test_keyword_recommendations_use_the_same_guard_before_any_api_mutation(): void
    {
        $campaign = $this->campaign();
        foreach (['enable', 'pause', 'remove'] as $action) {
            $guard = $this->guard(evidence: fn ($e) => array_replace_recursive($e, ['forecast' => ['clicks' => 0, 'impressions' => 0]]));
            $this->app->instance(GoogleSearchMutationGuard::class, $guard);
            $result = app(RecommendationApplier::class)->apply($campaign, ['type' => 'KEYWORDS', 'direction' => $action,
                'criterion_resource_name' => 'customers/1234567890/adGroupCriteria/10~'.($action === 'enable' ? 2 : 1)], true);
            $this->assertFalse($result['applied']);
            $this->assertTrue($result['requires_review']);
            $this->assertArrayHasKey('reach_evidence', $result);
        }
        Http::assertNothingSent();
    }
}

class ScriptedSearchMutationGuard extends GoogleSearchMutationGuard
{
    public int $inspections = 0;

    public int $lifetimeSpend = 0;

    public ?\Closure $onInspection = null;

    public function __construct(public ScriptedMutationReachPlanner $planner, public array $snapshot)
    {
        parent::__construct($planner, new GoogleSearchReachGate);
    }

    protected function inspect(Campaign $campaign): ?array
    {
        $this->inspections++;
        if ($this->onInspection) {
            ($this->onInspection)($this->inspections);
        }

        return $this->snapshot;
    }

    protected function spend(Campaign $campaign): array
    {
        return ['cost_micros' => $this->lifetimeSpend];
    }
}

class ScriptedMutationReachPlanner extends GoogleSearchReachPlanner
{
    public int $checks = 0;

    public function __construct(private ?\Closure $evidence = null) {}

    public function assessMutation(Campaign $campaign, array $snapshot, array $keywords): array
    {
        $this->checks++;
        $start = now($snapshot['time_zone'])->startOfDay()->addDay();
        $evidence = ['status' => 'evidence_ready', 'historical' => ['success' => true,
            'checked_at' => now()->toIso8601String(), 'keywords' => array_map(fn ($k) => ['text' => $k['text'], 'avg_monthly_searches' => 1], $keywords)],
            'forecast' => ['success' => true, 'auto_repair_safe' => true, 'source' => 'google_keyword_forecast',
                'checked_at' => now()->toIso8601String(), 'caveats' => [], 'period_days' => 30,
                'forecast_period' => ['from' => $start->toDateString(), 'through' => $start->copy()->addDays(29)->toDateString()],
                'clicks' => 60, 'impressions' => 100, 'cost_micros' => 10_000_000,
                'context' => ['bidding_strategy' => $snapshot['bidding_strategy'], 'daily_budget_micros' => $snapshot['daily_budget_micros'],
                    'cpc_bid_ceiling_micros' => $snapshot['actual_cpc_ceiling_micros'], 'currency_code' => $snapshot['currency_code'],
                    'time_zone' => $snapshot['time_zone'], 'keywords' => $keywords, 'geo_target_constants' => $snapshot['geo_target_constants'],
                    'language_constants' => $snapshot['language_constants'], 'negative_keywords' => $snapshot['negative_keywords']]]];

        return $this->evidence ? ($this->evidence)($evidence) : $evidence;
    }
}
