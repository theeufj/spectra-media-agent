<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SearchReachPlannerTest extends TestCase
{
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

    public function __construct(private KeywordResearchService $research, private ?\Closure $response) {}

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        return ['success' => true, 'keywords' => array_map(fn ($text) => [
            'text' => $text, 'avg_monthly_searches' => 20, 'low_top_of_page_bid_micros' => 7_000_000,
        ], $texts)];
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $this->requests[] = compact('keywords', 'ceiling', 'context');

        return $this->response ? ($this->response)($keywords, $ceiling, $context)
            : ['success' => true, 'clicks' => count($keywords) > 1 ? 60 : 5, 'impressions' => 100,
                'period_days' => 30, 'auto_repair_safe' => true];
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
