<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SearchReachPlannerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function campaign(array $attributes = []): Campaign
    {
        $customer = new Customer(['name' => 'SiteToSpend', 'website' => 'https://example.com',
            'description' => 'Managed PPC services for small businesses.', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 90001;
        $campaign = new Campaign($attributes + ['name' => 'Managed Search', 'product_focus' => 'Managed Google Ads']);
        $campaign->id = 90001;
        $campaign->setRelation('customer', $customer);

        return $campaign;
    }

    private function snapshot(): array
    {
        return ['bidding_strategy' => 11, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'ad_schedule' => [], 'search_partners_enabled' => false,
            'device_bid_modifiers' => [], 'unsupported_targeting' => [],
            'keywords' => [['text' => 'managed ppc services', 'match_type' => 'EXACT', 'status' => 'ENABLED',
                'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/42']]];
    }

    private function planner(array $ideas = [], ?\Closure $forecast = null): FakeReachPlanner
    {
        Cache::flush();

        return new FakeReachPlanner(new FakeReachKeywordResearch($ideas), $forecast);
    }

    public function test_actual_bid_match_type_markets_and_restrictions_are_forecast_without_guessing_a_higher_bid(): void
    {
        $planner = $this->planner();
        $snapshot = $this->snapshot();
        $snapshot['device_bid_modifiers'] = [['device' => 'MOBILE', 'bid_modifier' => 0]];
        $snapshot['unsupported_targeting'] = ['shared_negative_list'];
        $result = $planner->assess($this->campaign(), $snapshot);
        $this->assertSame(3_000_000, $planner->requests[0]['ceiling']);
        $this->assertSame('EXACT', $planner->requests[0]['keywords'][0]['match_type']);
        $this->assertSame($snapshot['geo_target_constants'], $planner->requests[0]['context']['geo_target_constants']);
        $this->assertSame($snapshot['device_bid_modifiers'], $planner->requests[0]['context']['device_bid_modifiers']);
        $this->assertSame($snapshot['unsupported_targeting'], $planner->requests[0]['context']['unsupported_targeting']);
        $this->assertContains('bid_ceiling_below_estimates', array_column($result['issues'], 'code'));
        $this->assertContains('insufficient_forecast_reach', array_column($result['issues'], 'code'));
        $this->assertFalse($result['auto_repair_safe']);
    }

    public function test_v26_intraday_start_and_end_only_forecast_complete_serving_days(): void
    {
        CarbonImmutable::setTestNow('2026-10-12T23:30:00Z');
        $planner = $this->planner();
        $snapshot = $this->snapshot() + ['start_date' => '2026-10-22', 'end_date' => '2026-10-26',
            'start_date_time' => '2026-10-22 15:30:00', 'end_date_time' => '2026-10-26 10:30:00'];
        $planner->assess($this->campaign(), $snapshot);

        $this->assertSame('2026-10-23', $planner->requests[0]['context']['forecast_start_date']);
        $this->assertSame('2026-10-25', $planner->requests[0]['context']['forecast_end_date_limit']);
        $this->assertSame(3, $planner->requests[0]['context']['forecast_period_days']);
    }

    public function test_a_trial_gets_a_researched_proposal_without_becoming_mutation_authorization(): void
    {
        $planner = $this->planner([['text' => 'outsource google ads', 'match_type' => 'PHRASE', 'selection_reason' => 'Commercial buyer of managed PPC.']]);
        $campaign = $this->campaign(['spend_guardrails' => ['enabled' => true, 'started_at' => now()->subDay()->toIso8601String()]]);
        $result = $planner->assess($campaign, $this->snapshot(), true);
        $this->assertSame('outsource google ads', $result['proposal']['candidate_keywords'][0]['text']);
        $this->assertSame(60, $result['combined_forecast']['clicks']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertStringContainsString('controlled test', implode(' ', $result['proposal']['blocked_by']));
        $this->assertSame([3_000_000], array_values(array_unique(array_column($planner->requests, 'ceiling'))));
    }

    public function test_only_relevance_reviewed_nonconflicting_exact_or_phrase_candidates_with_extra_reach_can_be_approved(): void
    {
        $planner = $this->planner([
            ['text' => 'management consulting', 'match_type' => 'EXACT'],
            ['text' => 'google ads jobs', 'match_type' => 'PHRASE', 'selection_reason' => 'Rejected by negatives.'],
            ['text' => 'google ads agency', 'match_type' => 'BROAD', 'selection_reason' => 'Too broad for a bounded repair.'],
            ['text' => 'managed ppc services', 'match_type' => 'PHRASE', 'selection_reason' => 'Already present.'],
            ['text' => 'outsource google ads', 'match_type' => 'EXACT', 'selection_reason' => 'Actual paid search service.'],
        ]);
        $snapshot = $this->snapshot();
        $snapshot['negative_keywords'] = [['text' => 'jobs', 'match_type' => 'BROAD', 'scope' => 'campaign']];
        $result = $planner->assess($this->campaign(), $snapshot, true);
        $this->assertSame(['outsource google ads'], array_column($result['candidate_keywords'], 'text'));
        $this->assertTrue($result['auto_repair_safe']);
        $this->assertTrue($result['candidate_keywords'][0]['forecasted']);
    }

    public function test_a_keyword_forecast_that_does_not_improve_combined_reach_is_not_a_safe_repair(): void
    {
        $planner = $this->planner([['text' => 'outsource google ads', 'match_type' => 'EXACT', 'selection_reason' => 'Actual service.']],
            fn () => ['success' => true, 'clicks' => 5, 'auto_repair_safe' => true]);
        $result = $planner->assess($this->campaign(), $this->snapshot(), true);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertStringContainsString('sufficient extra reach', implode(' ', $result['proposal']['blocked_by']));
    }

    public function test_research_can_find_a_viable_later_keyword_without_exceeding_the_mutation_limit(): void
    {
        $ideas = array_map(fn ($i) => ['text' => 'commercial ppc service '.$i, 'match_type' => 'EXACT',
            'selection_reason' => 'Actual commercial offer.'], range(1, 8));
        $planner = $this->planner($ideas, fn ($keywords) => ['success' => true,
            'clicks' => count($keywords) > 1 ? 90 : (($keywords[0]['text'] ?? '') === 'commercial ppc service 4' ? 40 : 0),
            'auto_repair_safe' => true]);
        $result = $planner->assess($this->campaign(), $this->snapshot(), true);
        $this->assertTrue($result['auto_repair_safe']);
        $this->assertSame(['commercial ppc service 4'], array_column($result['candidate_keywords'], 'text'));
        $this->assertCount(6, $result['researched_candidates']);
        $this->assertLessThanOrEqual(3, count($result['proposal']['candidate_keywords']));
        $this->assertCount(8, $planner->requests, 'One baseline, six individual candidates and one combined forecast.');
    }

    public function test_missing_cpc_cap_or_failed_forecast_cannot_be_turned_into_healthy_evidence(): void
    {
        $planner = $this->planner();
        $snapshot = $this->snapshot();
        $snapshot['cpc_bid_ceiling_micros'] = 0;
        $result = $planner->assess($this->campaign(), $snapshot);
        $this->assertSame([], $planner->requests);
        $this->assertNull($result['forecast']);
        $this->assertContains('cpc_ceiling_unverified', array_column($result['issues'], 'code'));
        $this->assertFalse($result['auto_repair_safe']);
        $planner = $this->planner([], fn () => ['success' => false]);
        $result = $planner->assess($this->campaign(), $this->snapshot());
        $this->assertSame('unavailable', $result['status']);
        $this->assertFalse($result['auto_repair_safe']);
    }

    public function test_measured_affordable_candidates_survive_the_first_six_keyword_quota(): void
    {
        Cache::flush();
        $expensive = array_map(fn ($i) => ['text' => 'expensive buyer service '.$i, 'match_type' => 'EXACT',
            'selection_reason' => 'Directly buys the offered service.', 'average_cpc_micros' => 20_000_000], range(1, 12));
        $cheap = ['text' => 'affordable relevant provider', 'match_type' => 'PHRASE',
            'selection_reason' => 'Same paid service through a different buying task.', 'average_cpc_micros' => 500_000];
        $research = new ProfiledReachKeywordResearch(['keywords' => $expensive, 'cost_candidates' => [$cheap]]);
        $planner = new FakeReachPlanner($research, fn ($keywords) => ['success' => true, 'clicks' => count($keywords) > 1
            ? 80 : ($keywords[0]['text'] === $cheap['text'] ? 40 : 0), 'auto_repair_safe' => true]);

        $result = $planner->assess($this->campaign(), $this->snapshot(), true);

        $this->assertSame($cheap['text'], $planner->requests[1]['keywords'][0]['text']);
        $this->assertSame([$cheap['text']], array_column($result['candidate_keywords'], 'text'));
        $this->assertTrue($result['auto_repair_safe']);
        $this->assertCount(1, $research->calls, 'A useful affordable portfolio needs no additional discovery.');
    }

    public function test_many_terms_with_tiny_positive_forecasts_still_trigger_one_alternative_theme_pass(): void
    {
        Cache::flush();
        $initial = array_map(fn ($i) => ['text' => 'expensive buyer service '.$i, 'match_type' => 'EXACT',
            'selection_reason' => 'Offered service with costly terminology.'], range(1, 15));
        $alternative = ['text' => 'another supported buying task', 'match_type' => 'PHRASE',
            'selection_reason' => 'Direct paid task supported by the same offer.'];
        $research = new ProfiledReachKeywordResearch(['keywords' => $initial], ['cost_candidates' => [$alternative], 'keywords' => [$alternative]]);
        $planner = new FakeReachPlanner($research, fn ($keywords) => ['success' => true,
            'clicks' => in_array($alternative['text'], array_column($keywords, 'text'), true) ? (count($keywords) > 1 ? 70 : 40) : 0.1,
            'cost_micros' => 1_000_000, 'auto_repair_safe' => true]);

        $result = $planner->assess($this->campaign(), $this->snapshot(), true);

        $this->assertSame(['ordinary', 'affordable'], array_column($research->calls, 'kind'));
        $this->assertTrue($result['affordable_discovery']['attempted']);
        $this->assertSame([$alternative['text']], array_column($result['candidate_keywords'], 'text'));
        $this->assertTrue($result['auto_repair_safe']);
        $this->assertSame(10_000_000, $research->calls[1]['business']['daily_budget_micros']);
        $this->assertSame(3_000_000, $research->calls[1]['business']['max_cpc_bid_micros']);
        $this->assertCount(6, $research->calls[1]['business']['failed_candidates']);
        $this->assertLessThanOrEqual(15, count($planner->requests), 'Discovery has a fixed two-pass forecast budget.');
    }

    public function test_canonical_close_variants_do_not_consume_every_affordable_forecast_slot(): void
    {
        $aliases = array_map(fn ($i) => ['text' => 'provider alias '.$i, 'match_type' => 'EXACT',
            'selection_reason' => 'Same offered task.'], range(1, 8));
        $planner = $this->planner([...$aliases, ['text' => 'different buying task', 'match_type' => 'EXACT',
            'selection_reason' => 'Another task actually delivered.']], fn ($keywords) => ['success' => true,
                'clicks' => in_array('different buying task', array_column($keywords, 'text'), true) ? 40 : 0,
                'auto_repair_safe' => true]);
        foreach ($aliases as $alias) {
            $planner->metrics[$alias['text']] = ['text' => 'canonical offered task', 'avg_monthly_searches' => 500,
                'low_top_of_page_bid_micros' => 1_000_000];
        }

        $result = $planner->assess($this->campaign(), $this->snapshot(), true);

        $this->assertCount(2, $result['researched_candidates']);
        $this->assertSame(['different buying task'], array_column($result['candidate_keywords'], 'text'));
        $this->assertTrue($result['auto_repair_safe']);
    }

    public function test_manual_candidates_use_the_verified_group_bid_and_preserve_lower_existing_bids(): void
    {
        $planner = $this->planner([['text' => 'supported paid task', 'match_type' => 'EXACT', 'selection_reason' => 'Actual paid offer.']]);
        $snapshot = $this->snapshot();
        $snapshot['bidding_strategy'] = 3;
        $snapshot['cpc_bid_ceiling_micros'] = 2_000_000;
        $snapshot['keywords'][0] += ['ad_group_cpc_bid_micros' => 2_000_000, 'cpc_bid_micros' => 1_000_000];

        $result = $planner->assess($this->campaign(), $snapshot, true);

        $candidate = $result['candidate_keywords'][0];
        $this->assertSame(2_000_000, $candidate['cpc_bid_micros']);
        $this->assertTrue($candidate['set_keyword_bid']);
        $combined = $planner->requests[array_key_last($planner->requests)];
        $this->assertSame([1_000_000, 2_000_000], array_column($combined['keywords'], 'cpc_bid_micros'));
        $this->assertSame(2_000_000, $combined['context']['cpc_bid_ceiling_micros']);

        $snapshot['keywords'][0]['ad_group_cpc_bid_micros'] = 4_000_000;
        $result = $planner->assess($this->campaign(), $snapshot, true);
        $this->assertSame([], $result['candidate_keywords']);
        $this->assertStringContainsString('inherited Manual CPC', implode(' ', $result['proposal']['blocked_by']));
    }

    public function test_over_budget_combined_traffic_is_not_a_useful_repair_and_optional_discovery_failure_preserves_evidence(): void
    {
        Cache::flush();
        $research = new ProfiledReachKeywordResearch(['keywords' => [['text' => 'supported paid task', 'match_type' => 'EXACT',
            'selection_reason' => 'Actual paid offer.']]], failure: true);
        $planner = new FakeReachPlanner($research, fn ($keywords) => ['success' => true, 'clicks' => count($keywords) > 1 ? 60 : 5,
            'cost_micros' => 999_000_000, 'auto_repair_safe' => true]);
        $result = $planner->assess($this->campaign(), $this->snapshot(), true);

        $this->assertSame('evidence_ready', $result['status']);
        $this->assertSame('unavailable', $result['affordable_discovery']['status']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertSame(999_000_000, $result['combined_forecast']['cost_micros']);
        $this->assertCount(2, $research->calls);
    }

    public function test_the_known_negative_limitation_is_retained_for_a_bounded_candidate_without_claiming_safe_reach(): void
    {
        $message = 'Google cannot model retained negative keywords.';
        $planner = $this->planner([['text' => 'supported paid task', 'match_type' => 'EXACT', 'selection_reason' => 'Actual offer.']],
            fn ($keywords) => ['success' => true, 'clicks' => count($keywords) > 1 ? 60 : 5,
                'auto_repair_safe' => false, 'caveats' => [$message],
                'limitations' => [['code' => 'negative_keywords_unmodeled', 'message' => $message]]]);
        $result = $planner->assess($this->campaign(), $this->snapshot(), true);

        $this->assertFalse($result['auto_repair_safe']);
        $this->assertTrue($result['bounded_expansion_candidate']);
        $this->assertSame([$message], $result['combined_forecast']['caveats']);
        $this->assertFalse($result['combined_forecast']['auto_repair_safe']);
    }

    public function test_mutation_check_refreshes_both_providers_instead_of_reusing_a_good_cached_assessment(): void
    {
        Cache::flush();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->exactly(2))->method('__invoke')->willReturnOnConsecutiveCalls(
            ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => ['managed ppc services' => ['avg_monthly_searches' => 10]]],
            ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => ['managed ppc services' => ['avg_monthly_searches' => null]]],
        );
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->exactly(2))->method('__invoke')->willReturnOnConsecutiveCalls(
            ['success' => true, 'clicks' => 60, 'period_days' => 30], ['success' => true, 'clicks' => 0, 'period_days' => 30],
        );
        $planner = new ProviderSeamReachPlanner($historical, $forecast);
        $campaign = $this->campaign();
        $snapshot = $this->snapshot();
        $this->assertSame(60, $planner->assess($campaign, $snapshot)['forecast']['clicks']);
        $this->assertSame(60, $planner->assess($campaign, $snapshot)['forecast']['clicks']);
        $fresh = $planner->assessMutation($campaign, $snapshot, $snapshot['keywords']);
        $this->assertSame(0, $fresh['forecast']['clicks']);
        $this->assertNull($fresh['historical']['keywords']['managed ppc services']['avg_monthly_searches']);
    }

    public function test_trial_forecast_horizon_fits_campaign_end_and_fresh_remaining_spend_using_new_google_estimates(): void
    {
        Cache::flush();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [['text' => 'managed ppc services', 'avg_monthly_searches' => 10]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $periods = [];
        $forecast->expects($this->exactly(2))->method('__invoke')->willReturnCallback(function ($customerId, $keywords, $cap, $rate, $days, $context) use (&$periods) {
            $this->assertNull($rate, 'No fictional conversion rate is supplied to the repair forecast.');
            $this->assertSame(3.0, $cap);
            $this->assertSame(10_000_000, $context['daily_budget_micros']);
            $this->assertSame(20_000_000, $context['max_forecast_cost_micros']);
            $periods[] = $days;

            return ['success' => true, 'auto_repair_safe' => true, 'period_days' => $days,
                'cost_micros' => $days === 14 ? 40_000_000 : 15_000_000, 'clicks' => $days === 14 ? 20 : 15];
        });
        $planner = new ProviderSeamReachPlanner($historical, $forecast);
        $campaign = $this->campaign(['end_date' => now('Australia/Sydney')->startOfDay()->addDays(14), 'spend_guardrails' => ['enabled' => true,
            'baseline_cost_micros' => 1_000_000, 'max_spend_micros' => 30_000_000]]);
        $snapshot = $this->snapshot();
        $snapshot['reported_lifetime_cost_micros'] = 11_000_000;
        $result = $planner->assessMutation($campaign, $snapshot, $snapshot['keywords']);

        $this->assertSame([14, 7], $periods);
        $this->assertSame(7, $result['forecast']['period_days']);
        $this->assertSame(15, $result['forecast']['clicks'], 'Final totals come from the second provider forecast, never scaled first estimates.');
        $this->assertNotContains('insufficient_forecast_reach', array_column($result['issues'], 'code'), 'A shorter approved period uses a disclosed monthly equivalent.');
    }

    public function test_future_launch_forecasts_the_intersection_of_approved_and_live_serving_dates(): void
    {
        CarbonImmutable::setTestNow('2026-10-12T23:30:00Z');
        Cache::flush();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [['text' => 'managed ppc services', 'avg_monthly_searches' => 100]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->exactly(2))->method('__invoke')->willReturnCallback(function ($customerId, $keywords, $cap, $rate, $days, $context) {
            $this->assertSame(3.0, $cap);
            $this->assertNull($rate);
            $this->assertSame(10_000_000, $context['daily_budget_micros']);
            $this->assertSame('2026-10-22', $context['forecast_start_date']);
            $this->assertSame('2026-10-26', $context['forecast_end_date_limit']);
            $this->assertSame(5, $days);

            return ['success' => true, 'auto_repair_safe' => true, 'period_days' => $days,
                'forecast_period' => ['from' => $context['forecast_start_date'], 'through' => $context['forecast_end_date_limit']],
                'cost_micros' => 10_000_000, 'clicks' => 10];
        });
        $planner = new ProviderSeamReachPlanner($historical, $forecast);
        $campaign = $this->campaign(['start_date' => '2026-10-20', 'end_date' => '2026-10-26']);
        $snapshot = $this->snapshot() + ['start_date' => '20261022', 'end_date' => '20261028'];

        $deployment = $planner->assess($campaign, $snapshot);
        $repair = $planner->assessMutation($campaign, $snapshot, $snapshot['keywords']);

        $this->assertSame('evidence_ready', $deployment['status']);
        $this->assertSame(['from' => '2026-10-22', 'through' => '2026-10-26'], $deployment['forecast']['forecast_period']);
        $this->assertSame($deployment['forecast']['forecast_period'], $repair['forecast']['forecast_period']);
        $this->assertNotContains('insufficient_forecast_reach', array_column($repair['issues'], 'code'));
    }

    public function test_future_trial_shortens_the_forecast_cost_horizon_from_its_launch_date(): void
    {
        CarbonImmutable::setTestNow('2026-10-12T23:30:00Z');
        Cache::flush();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [['text' => 'managed ppc services', 'avg_monthly_searches' => 100]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $periods = [];
        $forecast->expects($this->exactly(2))->method('__invoke')->willReturnCallback(function ($customerId, $keywords, $cap, $rate, $days, $context) use (&$periods) {
            $this->assertSame(3.0, $cap);
            $this->assertSame('2026-10-22', $context['forecast_start_date']);
            $this->assertSame('2026-11-04', $context['forecast_end_date_limit']);
            $this->assertSame(20_000_000, $context['max_forecast_cost_micros']);
            $periods[] = $days;

            return ['success' => true, 'auto_repair_safe' => true, 'period_days' => $days,
                'cost_micros' => $days === 14 ? 40_000_000 : 15_000_000, 'clicks' => $days === 14 ? 20 : 15];
        });
        $campaign = $this->campaign(['start_date' => '2026-10-20', 'end_date' => '2026-11-04',
            'spend_guardrails' => ['enabled' => true, 'baseline_cost_micros' => 1_000_000, 'max_spend_micros' => 30_000_000]]);
        $snapshot = $this->snapshot() + ['start_date' => '20261022', 'end_date' => '20261110', 'reported_lifetime_cost_micros' => 11_000_000];
        $result = (new ProviderSeamReachPlanner($historical, $forecast))->assessMutation($campaign, $snapshot, $snapshot['keywords']);

        $this->assertSame([14, 7], $periods);
        $this->assertSame(15_000_000, $result['forecast']['cost_micros']);
        $this->assertSame(7, $result['forecast']['period_days']);
    }

    public function test_forecast_horizon_is_truncated_at_the_google_one_year_limit(): void
    {
        CarbonImmutable::setTestNow('2026-10-12T23:30:00Z');
        Cache::flush();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [['text' => 'managed ppc services', 'avg_monthly_searches' => 100]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->once())->method('__invoke')->willReturnCallback(function ($customerId, $keywords, $cap, $rate, $days, $context) {
            $this->assertSame('2027-10-10', $context['forecast_start_date']);
            $this->assertSame('2027-10-13', $context['forecast_end_date_limit']);
            $this->assertSame(4, $days);

            return ['success' => true, 'auto_repair_safe' => true, 'period_days' => $days, 'cost_micros' => 10_000_000, 'clicks' => 10];
        });
        $campaign = $this->campaign(['start_date' => '2027-10-10', 'end_date' => '2027-11-10']);
        $snapshot = $this->snapshot() + ['start_date' => '20271008', 'end_date' => '20271108'];

        $this->assertSame('evidence_ready', (new ProviderSeamReachPlanner($historical, $forecast))->assessMutation($campaign, $snapshot, $snapshot['keywords'])['status']);
    }

    public function test_invalid_or_empty_campaign_serving_windows_never_request_a_repair_forecast(): void
    {
        CarbonImmutable::setTestNow('2026-10-12T23:30:00Z');
        foreach ([
            ['start_date' => '2026-10-22', 'end_date' => '2026-10-21'],
            ['start_date' => '2026-10-22', 'end_date' => '2026-10-12'],
            ['start_date' => '2027-10-14', 'end_date' => '2027-11-10'],
            ['start_date' => '2026-02-30', 'end_date' => '2026-10-26'],
            ['start_date' => '20261020', 'end_date' => '20261032'],
        ] as $window) {
            Cache::flush();
            $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
            $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [['text' => 'managed ppc services', 'avg_monthly_searches' => 100]]]);
            $forecast = $this->createMock(GenerateKeywordForecast::class);
            $forecast->expects($this->never())->method('__invoke');
            $snapshot = $window + $this->snapshot();
            $result = (new ProviderSeamReachPlanner($historical, $forecast))->assessMutation($this->campaign(), $snapshot, $snapshot['keywords']);

            $this->assertSame('unavailable', $result['status'], json_encode($window));
            $this->assertFalse($result['auto_repair_safe']);
            $this->assertNull($result['forecast']);
        }
    }

    public function test_historical_requested_key_is_preferred_over_google_canonical_close_variant_text(): void
    {
        $row = ['text' => 'google ads software', 'avg_monthly_searches' => 10];
        $this->assertSame($row, GoogleSearchReachPlanner::historicalMetric(['google ad software' => $row], ' GOOGLE AD SOFTWARE '));
        $this->assertNull(GoogleSearchReachPlanner::historicalMetric(['unknown' => $row], 'unrelated consulting'));
    }

    public function test_negative_matching_respects_scope_and_all_supported_match_types(): void
    {
        $group = 'customers/1234567890/adGroups/42';
        $this->assertTrue(GoogleSearchReachPlanner::negativeConflict('google ads jobs', [['text' => 'jobs', 'match_type' => 'BROAD']], $group));
        $this->assertTrue(GoogleSearchReachPlanner::negativeConflict('hire google ads manager', [['text' => 'google ads', 'match_type' => 'PHRASE']], $group));
        $this->assertFalse(GoogleSearchReachPlanner::negativeConflict('hire google ads manager', [['text' => 'google ads', 'match_type' => 'EXACT']], $group));
        $this->assertFalse(GoogleSearchReachPlanner::negativeConflict('google ads jobs', [['text' => 'jobs', 'match_type' => 'BROAD', 'scope' => 'ad_group', 'ad_group_resource' => 'other']], $group));
        $this->assertTrue(GoogleSearchReachPlanner::negativeConflict('google ads manager', [['text' => 'unknown', 'match_type' => 'UNSPECIFIED']], $group));
    }
}

class FakeReachPlanner extends GoogleSearchReachPlanner
{
    public array $requests = [];

    public array $metrics = [];

    public function __construct(private KeywordResearchService $research, private ?\Closure $response) {}

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        return ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => array_combine(array_map('mb_strtolower', $texts), array_map(fn ($text) => $this->metrics[mb_strtolower($text)] ?? [
            'text' => $text, 'avg_monthly_searches' => 20, 'low_top_of_page_bid_micros' => 7_000_000,
        ], $texts))];
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $this->requests[] = compact('keywords', 'ceiling', 'context');

        $forecast = $this->response ? ($this->response)($keywords, $ceiling, $context)
            : ['success' => true, 'clicks' => count($keywords) > 1 ? 60 : 5, 'impressions' => 100,
                'period_days' => 30, 'auto_repair_safe' => true];

        return $forecast + ['cost_micros' => ($forecast['clicks'] ?? 0) > 0 ? 1_000_000 : 0,
            'period_days' => 30];
    }

    protected function researchProvider(Customer $customer): KeywordResearchService
    {
        return $this->research;
    }
}

class FakeReachKeywordResearch extends KeywordResearchService
{
    public function __construct(private array $ideas) {}

    public function research(string $customerId, string $businessName, ?string $industry = null,
        ?string $landingPageUrl = null, ?string $language = 'languageConstants/1000', array $geoTargets = [],
        int $maxKeywords = 20, array $userSeedKeywords = [], array $businessContext = []): array
    {
        return ['keywords' => $this->ideas];
    }
}

class ProviderSeamReachPlanner extends GoogleSearchReachPlanner
{
    public function __construct(private GenerateKeywordHistoricalMetrics $historicalApi, private GenerateKeywordForecast $forecastApi) {}

    protected function historicalProvider(Customer $customer): GenerateKeywordHistoricalMetrics
    {
        return $this->historicalApi;
    }

    protected function forecastProvider(Customer $customer): GenerateKeywordForecast
    {
        return $this->forecastApi;
    }
}

class ProfiledReachKeywordResearch extends KeywordResearchService
{
    public array $calls = [];

    public function __construct(private array $ordinary, private array $affordable = [], private bool $failure = false) {}

    public function research(string $customerId, string $businessName, ?string $industry = null,
        ?string $landingPageUrl = null, ?string $language = 'languageConstants/1000', array $geoTargets = [],
        int $maxKeywords = 20, array $userSeedKeywords = [], array $businessContext = []): array
    {
        $this->calls[] = ['kind' => 'ordinary', 'business' => $businessContext];

        return $this->ordinary;
    }

    public function researchAffordableAlternatives(string $customerId, string $businessName, ?string $industry = null,
        ?string $landingPageUrl = null, ?string $language = 'languageConstants/1000', array $geoTargets = [],
        int $maxKeywords = 20, array $userSeedKeywords = [], array $businessContext = []): array
    {
        $this->calls[] = ['kind' => 'affordable', 'business' => $businessContext];
        if ($this->failure) {
            throw new \RuntimeException('Alternative provider unavailable.');
        }

        return $this->affordable;
    }
}
