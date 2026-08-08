<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\FacebookAds\InsightService;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CampaignOptimizationAgentTest extends TestCase
{
    // GeminiService::recordCost() writes an ai_costs row on every call, so
    // these tests were committing cost rows that leaked into the suite and
    // broke AiCostControllerTest's totals.
    use DatabaseTransactions;

    protected CampaignOptimizationAgent $agent;

    /** @var list<mixed> */
    protected array $deps = [];

    protected GeminiService $geminiMock;

    protected $getPerformanceMock;

    /** @return list<mixed> */
    private function agentDependencies(): array
    {
        return $this->deps;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The agent's collaborators were extracted into Optimization\* classes;
        // this was still passing the raw GetCampaignPerformance service as arg #2.
        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->getPerformanceMock = Mockery::mock(\App\Services\Agents\Optimization\MetricsFetcher::class);
        $this->getPerformanceMock->shouldIgnoreMissing();

        // Use the real scorer: it is pure scoring logic with no I/O, and stubbing
        // it out left the agent with unscored recommendations ("Undefined array
        // key score"). Exercising it is also more of the path than a mock covers.
        $scorer = new \App\Services\Agents\Optimization\RecommendationScorer;
        $applier = Mockery::mock(\App\Services\Agents\Optimization\RecommendationApplier::class)->shouldIgnoreMissing();

        $this->deps = [$this->geminiMock, $this->getPerformanceMock, $scorer, $applier];
        $this->agent = new CampaignOptimizationAgent(...$this->deps);
    }

    public function test_successful_analysis_with_google_campaign(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111222333',
            'name' => 'Test Google Campaign',
            'goals' => 'Increase conversions',
            'total_budget' => 5000,
            'daily_budget' => 150,
            'primary_kpi' => '4x ROAS',
            'product_focus' => 'Enterprise SaaS',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $performanceData = [
            'impressions' => 50000,
            'clicks' => 2500,
            'cost_micros' => 3500000000, // $3,500
            'conversions' => 75,
            'ctr' => 0.05,
            'average_cpc' => 1400000,
            'cost_per_conversion' => 46666667,
        ];

        // MetricsFetcher replaced the invokable GetCampaignPerformance; stubbing
        // __invoke() meant the agent saw no metrics and bailed with null.
        $this->getPerformanceMock->shouldReceive('fetchCurrent')->andReturn($performanceData);
        $this->getPerformanceMock->shouldReceive('fetchHistorical')->andReturn($performanceData);
        $this->getPerformanceMock->shouldReceive('platform')->andReturn('google');

        $recommendationsJson = json_encode([
            'recommendations' => [
                [
                    'type' => 'BUDGET',
                    'title' => 'Increase daily budget',
                    'description' => 'Campaign is performing well, increase budget to capture more conversions.',
                    'impact' => 'HIGH',
                    'action' => 'Increase daily budget from $150 to $200',
                ],
                [
                    'type' => 'KEYWORDS',
                    'title' => 'Add negative keywords',
                    'description' => 'Several irrelevant search terms are consuming budget.',
                    'impact' => 'MEDIUM',
                    'action' => 'Add 15 negative keywords',
                ],
            ],
        ]);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn(['text' => $recommendationsJson]);

        Log::spy();

        $result = $this->agent->analyze($campaign);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertNotEmpty($result['recommendations']);

        // Verify caching
        $cached = Cache::get("optimization:campaign:{$campaign->id}");
        $this->assertNotNull($cached);
    }

    public function test_successful_analysis_with_facebook_campaign(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => null]);
        $customer->id = 2;

        $campaign = new Campaign([
            'customer_id' => 2,
            'google_ads_campaign_id' => null,
            'facebook_ads_campaign_id' => '987654321',
            'name' => 'Test Facebook Campaign',
        ]);
        $campaign->id = 2;
        $campaign->setRelation('customer', $customer);

        // Mock InsightService via partial mock on the agent
        $agentMock = Mockery::mock(CampaignOptimizationAgent::class, $this->agentDependencies())
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // getFacebookMetrics/getFacebookHistoricalMetrics moved into MetricsFetcher,
        // so mocking them on the agent silently did nothing and the agent saw no
        // metrics. Drive the collaborator instead.
        $this->getPerformanceMock->shouldReceive('fetchCurrent')->andReturn([
            'impressions' => 30000,
            'clicks' => 1200,
            'cost_micros' => 2000000000,
            'conversions' => 40,
            'ctr' => 0.04,
            'average_cpc' => 1666667,
            'cost_per_conversion' => 50000000,
            'frequency' => 2.5,
            'reach' => 12000,
        ]);
        $this->getPerformanceMock->shouldReceive('fetchHistorical')->andReturn(null);
        $this->getPerformanceMock->shouldReceive('platform')->andReturn('facebook');

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'recommendations' => [
                        [
                            'type' => 'ADS',
                            'title' => 'Refresh creatives',
                            'description' => 'Frequency is approaching fatigue threshold.',
                            'impact' => 'HIGH',
                            'action' => 'Create 3 new ad variations',
                        ],
                    ],
                ]),
            ]);

        $result = $agentMock->analyze($campaign);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function test_returns_null_when_no_performance_data(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 3;

        $campaign = new Campaign([
            'customer_id' => 3,
            'google_ads_campaign_id' => '111222333',
        ]);
        $campaign->id = 3;
        $campaign->setRelation('customer', $customer);

        // GetCampaignPerformance returns null (no data)
        $this->getPerformanceMock->shouldReceive('fetchCurrent')->andReturn(null);
        $this->getPerformanceMock->shouldReceive('fetchHistorical')->andReturn(null);
        $this->getPerformanceMock->shouldReceive('platform')->andReturn('google');

        Log::spy();

        $result = $this->agent->analyze($campaign);

        $this->assertNull($result);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_caches_recommendations(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 4;

        $campaign = new Campaign([
            'customer_id' => 4,
            'google_ads_campaign_id' => '111222333',
        ]);
        $campaign->id = 4;
        $campaign->setRelation('customer', $customer);

        $performanceData = [
            'impressions' => 10000,
            'clicks' => 500,
            'cost_micros' => 1000000000,
            'conversions' => 20,
            'ctr' => 0.05,
            'average_cpc' => 2000000,
            'cost_per_conversion' => 50000000,
        ];

        $this->getPerformanceMock->shouldReceive('fetchCurrent')->andReturn($performanceData);
        $this->getPerformanceMock->shouldReceive('fetchHistorical')->andReturn($performanceData);
        $this->getPerformanceMock->shouldReceive('platform')->andReturn('google');

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'recommendations' => [
                        ['type' => 'BUDGET', 'title' => 'Test rec', 'description' => 'Test', 'impact' => 'LOW', 'action' => 'Do something'],
                    ],
                ]),
            ]);

        Cache::flush();

        $result = $this->agent->analyze($campaign);

        $this->assertNotNull($result);

        // Verify getCachedRecommendations returns the cached data
        $cached = $this->agent->getCachedRecommendations($campaign->id);
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('recommendations', $cached);
    }
}
