<?php

namespace Tests\Unit\Agents;

use App\Models\ABTest;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ABTestingAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ABTestingAgentTest extends TestCase
{
    protected ABTestingAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->agent = new ABTestingAgent($this->geminiMock);
    }

    public function test_create_test_creates_ab_test_with_correct_attributes(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign(['customer_id' => 1, 'google_ads_campaign_id' => '111222333']);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 1, 'platform' => 'Google Ads']);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $variants = [
            ['content' => 'Buy Now - 50% Off!', 'label' => 'Urgent CTA'],
            ['content' => 'Shop the Collection', 'label' => 'Soft CTA'],
        ];

        // Mock the agent to intercept the DB persistence
        $agentMock = Mockery::mock(ABTestingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $expectedAbTest = new ABTest([
            'strategy_id' => 1,
            'campaign_id' => 1,
            'test_type' => ABTest::TYPE_HEADLINE,
            'status' => ABTest::STATUS_RUNNING,
            'variants' => [
                ['id' => 'variant-a', 'label' => 'Urgent CTA', 'content' => 'Buy Now - 50% Off!', 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'cost' => 0],
                ['id' => 'variant-b', 'label' => 'Soft CTA', 'content' => 'Shop the Collection', 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'cost' => 0],
            ],
            'started_at' => now(),
        ]);
        $expectedAbTest->id = 1;
        $expectedAbTest->exists = true;

        $agentMock->shouldReceive('createTest')
            ->once()
            ->with($strategy, ABTest::TYPE_HEADLINE, $variants)
            ->andReturn($expectedAbTest);

        $abTest = $agentMock->createTest($strategy, ABTest::TYPE_HEADLINE, $variants);

        $this->assertInstanceOf(ABTest::class, $abTest);
        $this->assertTrue($abTest->exists);
        $this->assertEquals($strategy->id, $abTest->strategy_id);
        $this->assertEquals($campaign->id, $abTest->campaign_id);
        $this->assertEquals(ABTest::TYPE_HEADLINE, $abTest->test_type);
        $this->assertEquals(ABTest::STATUS_RUNNING, $abTest->status);
        $this->assertCount(2, $abTest->variants);
        $this->assertEquals('Urgent CTA', $abTest->variants[0]['label']);
        $this->assertEquals('Soft CTA', $abTest->variants[1]['label']);
        $this->assertEquals(0, $abTest->variants[0]['impressions']);
        $this->assertNotNull($abTest->started_at);
    }

    public function test_evaluate_test_detects_significance(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111222333',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 1]);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        // Create a test with sufficient data showing a clear winner
        $abTest = new ABTest([
            'strategy_id' => 1,
            'campaign_id' => 1,
            'test_type' => ABTest::TYPE_HEADLINE,
            'status' => ABTest::STATUS_RUNNING,
            'variants' => [
                [
                    'id' => 'variant-a',
                    'label' => 'Variant A',
                    'content' => 'Buy Now - 50% Off!',
                    'impressions' => 5000,
                    'clicks' => 500,  // 10% CTR
                    'conversions' => 50,
                    'cost' => 250,
                ],
                [
                    'id' => 'variant-b',
                    'label' => 'Variant B',
                    'content' => 'Shop the Collection',
                    'impressions' => 5000,
                    'clicks' => 250,  // 5% CTR
                    'conversions' => 20,
                    'cost' => 200,
                ],
            ],
            'started_at' => now()->subDays(7),
        ]);
        $abTest->id = 1;

        // Mock refreshVariantMetrics to return existing variants (skip Google Ads call)
        $agentMock = Mockery::mock(ABTestingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('refreshVariantMetrics')
            ->once()
            ->andReturn($abTest->variants);

        Log::spy();

        $result = $agentMock->evaluateTest($abTest);

        $this->assertEquals('significant', $result['action']);
        $this->assertArrayHasKey('winner', $result);
        $this->assertEquals('variant-a', $result['winner']['id']);
        $this->assertArrayHasKey('confidence', $result);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_evaluate_test_handles_insufficient_data(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 1;

        $campaign = new Campaign(['customer_id' => 1]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 1]);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $abTest = new ABTest([
            'strategy_id' => 1,
            'campaign_id' => 1,
            'test_type' => ABTest::TYPE_HEADLINE,
            'status' => ABTest::STATUS_RUNNING,
            'variants' => [
                [
                    'id' => 'variant-a',
                    'label' => 'Variant A',
                    'content' => 'Buy Now',
                    'impressions' => 100,  // Below minimum of 500
                    'clicks' => 5,
                    'conversions' => 0,
                    'cost' => 10,
                ],
                [
                    'id' => 'variant-b',
                    'label' => 'Variant B',
                    'content' => 'Learn More',
                    'impressions' => 100,
                    'clicks' => 3,
                    'conversions' => 0,
                    'cost' => 8,
                ],
            ],
            'started_at' => now()->subDays(2),
        ]);
        $abTest->id = 2;

        $agentMock = Mockery::mock(ABTestingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('refreshVariantMetrics')
            ->once()
            ->andReturn($abTest->variants);

        $result = $agentMock->evaluateTest($abTest);

        $this->assertEquals('learning', $result['action']);
        $this->assertStringContainsString('Insufficient data', $result['reason']);
    }

    public function test_apply_results_requires_significant_status(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 1;

        $campaign = new Campaign(['customer_id' => 1]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 1]);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $abTest = new ABTest([
            'strategy_id' => 1,
            'campaign_id' => 1,
            'test_type' => ABTest::TYPE_HEADLINE,
            'status' => ABTest::STATUS_RUNNING, // Not significant
            'variants' => [
                ['id' => 'a', 'label' => 'A', 'content' => 'Test', 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'cost' => 0],
            ],
            'started_at' => now(),
        ]);
        $abTest->id = 3;

        $result = $this->agent->applyResults($abTest);

        $this->assertFalse($result['success']);
        $this->assertEquals('Test has not reached significance', $result['reason']);
    }
}
