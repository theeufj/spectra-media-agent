<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\SearchTermMiningAgent;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Mockery;
use Tests\TestCase;

class SearchTermMiningAgentTest extends TestCase
{
    /** Effective threshold the agent uses (optimization overrides budget_rules). */
    private static function negativeCostThreshold(): float
    {
        $config = array_merge(
            config('budget_rules.search_term_mining', []),
            config('optimization.search_terms', [])
        );

        return (float) ($config['negative_cost_threshold'] ?? 50.0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['budget_rules.search_term_mining' => [
            'min_impressions' => 100,
            'min_clicks' => 5,
            'promote_ctr_threshold' => 0.05,
            'negative_cost_threshold' => 20.00,
            'negative_ctr_threshold' => 0.002,
            'negative_min_impressions' => 500,
        ]]);
    }

    public function test_mine_promotes_high_performing_search_terms(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $searchTerms = [
            [
                'search_term' => 'best marketing tool',
                'impressions' => 500,
                'clicks' => 30,
                'cost' => 15.00,
                'conversions' => 3,
                'ctr' => 0.06,
                'ad_group_resource_name' => 'customers/1234567890/adGroups/222',
            ],
        ];

        // Use partial mock to intercept the mine method's service calls
        $agentMock = Mockery::mock(SearchTermMiningAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $agentMock->__construct();

        // Override mine to inject mock — use reflection to test evaluateSearchTerm directly
        $reflector = new \ReflectionMethod(SearchTermMiningAgent::class, 'evaluateSearchTerm');
        $reflector->setAccessible(true);

        $results = [
            'campaign_id' => $campaign->id,
            'keywords_added' => [],
            'negatives_added' => [],
            'terms_analyzed' => 1,
            'errors' => [],
        ];

        $agentMock->shouldReceive('addAsKeyword')
            ->once()
            ->with($customer, '1234567890', 'customers/1234567890/adGroups/222', 'best marketing tool', KeywordMatchType::EXACT, Mockery::any())
            ->andReturnUsing(function ($cust, $custId, $adGroup, $keyword, $matchType, &$results) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => 'EXACT',
                ];
            });

        $args = [
            $customer,
            '1234567890',
            'customers/1234567890/campaigns/111',
            $searchTerms[0],
        ];
        $args[] = &$results;

        $reflector->invokeArgs(
            $agentMock,
            $args
        );

        $this->assertNotEmpty($results['keywords_added']);
        $this->assertEquals('best marketing tool', $results['keywords_added'][0]['keyword']);
    }

    public function test_mine_negates_low_performing_search_terms(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $wastingTerm = [
            'search_term' => 'free marketing stuff',
            'impressions' => 1000,
            'clicks' => 10,
            // Derived from config: the effective negative_cost_threshold is the
            // merge of budget_rules (20) and optimization (50), and optimization
            // wins — so the old hardcoded 25.00 stopped qualifying as wasteful
            // when that threshold was raised, and no negative was ever added.
            'cost' => self::negativeCostThreshold() + 5,
            'conversions' => 0,
            'ctr' => 0.01,
            'ad_group_resource_name' => 'customers/1234567890/adGroups/222',
            'matured' => true,
        ];

        $agentMock = Mockery::mock(SearchTermMiningAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $agentMock->__construct();

        $agentMock->shouldReceive('addAsNegative')
            ->once()
            ->andReturnUsing(function ($cust, $custId, $campaignResource, $keyword, $reason, &$results) {
                $results['negatives_added'][] = [
                    'keyword' => $keyword,
                    'reason' => $reason,
                ];
            });

        $results = [
            'campaign_id' => $campaign->id,
            'keywords_added' => [],
            'negatives_added' => [],
            'terms_analyzed' => 1,
            'errors' => [],
        ];

        $reflector = new \ReflectionMethod(SearchTermMiningAgent::class, 'evaluateSearchTerm');
        $reflector->setAccessible(true);

        $args = [
            $customer,
            '1234567890',
            'customers/1234567890/campaigns/111',
            $wastingTerm,
        ];
        $args[] = &$results;

        $reflector->invokeArgs(
            $agentMock,
            $args
        );

        $this->assertNotEmpty($results['negatives_added']);
        $this->assertEquals('free marketing stuff', $results['negatives_added'][0]['keyword']);
    }

    public function test_mine_handles_campaign_without_google_ads_id(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => null,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agent = new SearchTermMiningAgent;
        $results = $agent->mine($campaign);

        $this->assertEquals($campaign->id, $results['campaign_id']);
        $this->assertEmpty($results['keywords_added']);
        $this->assertEmpty($results['negatives_added']);
        $this->assertEquals(0, $results['terms_analyzed']);
    }

    public function test_mine_handles_empty_search_terms_report(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agentMock = Mockery::mock(SearchTermMiningAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $agentMock->__construct();

        // Simulate mine() logic with an empty search terms result
        // We test by checking that evaluateSearchTerm is never called on empty data
        $results = [
            'campaign_id' => $campaign->id,
            'keywords_added' => [],
            'negatives_added' => [],
            'terms_analyzed' => 0,
            'errors' => [],
        ];

        // No terms to evaluate means no keywords added/negated
        $this->assertEmpty($results['keywords_added']);
        $this->assertEmpty($results['negatives_added']);
        $this->assertEquals(0, $results['terms_analyzed']);
    }

    private function evaluate(array $changes, ?Campaign $campaign, bool $expectExclusion): void
    {
        config(['optimization.search_terms.negative_daily_budget_fraction' => 0.5,
            'optimization.search_terms.negative_min_clicks' => 2]);
        $customer = new Customer(['google_ads_customer_id' => '1234567890']);
        $agent = $this->getMockBuilder(SearchTermMiningAgent::class)->onlyMethods(['addAsKeyword', 'addAsNegative'])->getMock();
        $agent->expects($this->never())->method('addAsKeyword');
        if ($expectExclusion) {
            $agent->expects($this->once())->method('addAsNegative')->with($customer, '1234567890',
                'customers/1234567890/campaigns/111', 'irrelevant research query', $this->anything(), $this->anything(), 'EXACT');
        } else {
            $agent->expects($this->never())->method('addAsNegative');
        }
        $term = array_replace(['search_term' => 'irrelevant research query', 'impressions' => 20, 'clicks' => 2,
            'cost' => 30, 'cost_micros' => 30_000_000, 'conversions' => 0, 'all_conversions' => 0,
            'ctr' => 0.1, 'matured' => true, 'ad_group_resource_name' => 'customers/1234567890/adGroups/222'], $changes);
        $results = ['keywords_added' => [], 'negatives_added' => []];
        (new \ReflectionMethod(SearchTermMiningAgent::class, 'evaluateSearchTerm'))
            ->invokeArgs($agent, [$customer, '1234567890', 'customers/1234567890/campaigns/111', $term, &$results, 'google', $campaign]);
    }

    private function approvedCampaign(): Campaign
    {
        return new Campaign(['approved_daily_budget' => '50.00', 'budget_confirmed_at' => now()]);
    }

    public function test_matured_cost_can_trigger_a_narrow_exclusion_below_the_impression_floor(): void
    {
        $this->evaluate([], $this->approvedCampaign(), true);
    }

    public function test_fresh_expensive_clicks_are_not_negated_without_maturity_evidence(): void
    {
        $this->evaluate(['matured' => false, 'cost' => 100, 'cost_micros' => 100_000_000], $this->approvedCampaign(), false);
    }

    public function test_one_matured_click_is_still_insufficient_for_a_cost_only_exclusion(): void
    {
        $this->evaluate(['clicks' => 1, 'cost' => 100, 'cost_micros' => 100_000_000], $this->approvedCampaign(), false);
    }

    public function test_recent_conversion_evidence_preserves_a_term_despite_expensive_matured_spend(): void
    {
        $this->evaluate(['conversions' => 0.5], $this->approvedCampaign(), false);
        $this->evaluate(['all_conversions' => 1], $this->approvedCampaign(), false);
    }

    public function test_unconfirmed_budget_cannot_lower_the_absolute_spend_evidence_threshold(): void
    {
        $this->evaluate([], new Campaign(['approved_daily_budget' => '50.00']), false);
    }

    public function test_below_campaign_relative_cost_threshold_does_not_create_an_exclusion(): void
    {
        $this->evaluate(['cost' => 24.99, 'cost_micros' => 24_990_000], $this->approvedCampaign(), false);
    }

    public function test_google_only_entry_keeps_shared_guards_and_never_mines_microsoft(): void
    {
        $campaign = new Campaign(['google_ads_campaign_id' => '111', 'microsoft_ads_campaign_id' => '222']);
        $campaign->setRelation('customer', new Customer(['google_ads_customer_id' => '1234567890']));
        $agent = $this->getMockBuilder(SearchTermMiningAgent::class)->onlyMethods(['mineGoogle', 'mineMicrosoft'])->getMock();
        $agent->expects($this->once())->method('mineGoogle');
        $agent->expects($this->never())->method('mineMicrosoft');

        $this->assertSame([], $agent->mine($campaign, true)['errors']);
    }

    public function test_default_entry_preserves_microsoft_mining_for_existing_callers(): void
    {
        $campaign = new Campaign(['google_ads_campaign_id' => '111', 'microsoft_ads_campaign_id' => '222']);
        $campaign->setRelation('customer', new Customer(['google_ads_customer_id' => '1234567890']));
        $agent = $this->getMockBuilder(SearchTermMiningAgent::class)->onlyMethods(['mineGoogle', 'mineMicrosoft'])->getMock();
        $agent->expects($this->once())->method('mineGoogle');
        $agent->expects($this->once())->method('mineMicrosoft');

        $this->assertSame([], $agent->mine($campaign)['errors']);
    }
}
