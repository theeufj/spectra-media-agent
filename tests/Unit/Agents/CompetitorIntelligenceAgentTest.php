<?php

namespace Tests\Unit\Agents;

use App\Models\Competitor;
use App\Models\Customer;
use App\Services\Agents\CompetitorAnalysisAgent;
use App\Services\Agents\CompetitorDiscoveryAgent;
use App\Services\Agents\CompetitorIntelligenceAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CompetitorIntelligenceAgentTest extends TestCase
{
    protected CompetitorIntelligenceAgent $agent;
    protected GeminiService $geminiMock;
    protected CompetitorDiscoveryAgent $discoveryMock;
    protected CompetitorAnalysisAgent $analysisMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->discoveryMock = Mockery::mock(CompetitorDiscoveryAgent::class);
        $this->analysisMock = Mockery::mock(CompetitorAnalysisAgent::class);

        $this->agent = new CompetitorIntelligenceAgent(
            $this->geminiMock,
            $this->discoveryMock,
            $this->analysisMock
        );
    }

    public function test_run_full_analysis_orchestrates_discovery_and_analysis(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
            'website' => 'https://example.com',
        ]);
        $customer->id = 1;

        $this->discoveryMock->shouldReceive('discover')
            ->once()
            ->with($customer)
            ->andReturn([
                'customer_id' => $customer->id,
                'competitors_found' => [['domain' => 'rival.com']],
                'competitors_saved' => 1,
                'discovery_methods' => ['google_custom_search'],
                'errors' => [],
            ]);

        $this->analysisMock->shouldReceive('analyzeAll')
            ->once()
            ->with($customer)
            ->andReturn([
                'customer_id' => $customer->id,
                'analyzed' => 1,
                'failed' => 0,
                'errors' => [],
            ]);

        // Gemini for counter-strategy (no competitors with messaging_analysis yet)
        // Since no analyzed competitors exist in DB, generateCounterStrategy returns no_data

        Log::spy();

        $results = $this->agent->runFullAnalysis($customer);

        $this->assertNotNull($results['discovery']);
        $this->assertNotNull($results['analysis']);
        $this->assertEquals(1, $results['discovery']['competitors_saved']);
        $this->assertEquals(1, $results['analysis']['analyzed']);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_generate_counter_strategy_calls_gemini_with_correct_structure(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'website' => 'https://example.com',
            'business_type' => 'SaaS',
        ]);
        $customer->id = 2;

        // Create an analyzed competitor with messaging_analysis (in-memory)
        $competitor = new Competitor([
            'customer_id' => 2,
            'name' => 'Top Rival',
            'url' => 'https://top-rival.com',
            'domain' => 'top-rival.com',
            'messaging_analysis' => ['tone' => 'aggressive', 'key_claims' => ['#1 solution']],
            'value_propositions' => ['Enterprise grade', 'AI-powered'],
            'keywords_detected' => ['saas', 'enterprise'],
            'pricing_info' => ['model' => 'subscription'],
            'impression_share' => 45.50,
            'last_analyzed_at' => now(),
        ]);
        $competitor->id = 1;

        // Mock the agent to return the in-memory competitor instead of querying DB
        $agentMock = Mockery::mock(CompetitorIntelligenceAgent::class, [
            $this->geminiMock,
            $this->discoveryMock,
            $this->analysisMock,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('getAnalyzedCompetitors')
            ->once()
            ->andReturn(collect([$competitor]));

        // Mock persistStrategy to prevent DB write
        $agentMock->shouldReceive('persistStrategy')->andReturnNull();

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->withArgs(function ($model, $prompt, $options, $systemInstruction, $thinking) {
                return str_contains($prompt, 'Top Rival') || str_contains($prompt, 'COMPETITIVE COUNTER-STRATEGY');
            })
            ->andReturn([
                'text' => json_encode([
                    'positioning' => 'Differentiate on personalized service',
                    'messaging_recommendations' => ['Emphasize AI-powered approach'],
                    'ad_copy_angles' => ['Unlike enterprise-only tools, we serve growing businesses'],
                ]),
            ]);

        $result = $agentMock->generateCounterStrategy($customer);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('strategy', $result);
    }

    public function test_generate_counter_strategy_returns_no_data_when_no_competitors(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 3;

        // Mock getAnalyzedCompetitors to return empty collection
        $agentMock = Mockery::mock(CompetitorIntelligenceAgent::class, [
            $this->geminiMock,
            $this->discoveryMock,
            $this->analysisMock,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('getAnalyzedCompetitors')
            ->once()
            ->andReturn(collect([]));

        $result = $agentMock->generateCounterStrategy($customer);

        $this->assertEquals('no_data', $result['status']);
        $this->assertStringContainsString('No competitor analysis data', $result['message']);
    }

    public function test_run_full_analysis_handles_sub_agent_exceptions(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
        ]);
        $customer->id = 4;

        $this->discoveryMock->shouldReceive('discover')
            ->once()
            ->andThrow(new \Exception('Discovery service unavailable'));

        $this->analysisMock->shouldReceive('analyzeAll')
            ->once()
            ->andThrow(new \Exception('Analysis service unavailable'));

        Log::spy();

        $results = $this->agent->runFullAnalysis($customer);

        $this->assertNull($results['discovery']);
        $this->assertNull($results['analysis']);
        $this->assertCount(2, array_filter($results['errors'], fn($e) => str_contains($e, 'failed')));
    }
}
