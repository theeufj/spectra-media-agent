<?php

namespace Tests\Unit\Agents;

use App\Models\Competitor;
use App\Models\Customer;
use App\Services\Agents\CompetitorAnalysisAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CompetitorAnalysisAgentTest extends TestCase
{
    protected CompetitorAnalysisAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->agent = new CompetitorAnalysisAgent($this->geminiMock);
    }

    public function test_analyze_successfully_scrapes_and_analyzes_competitor(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
            'website' => 'https://example.com',
        ]);
        $customer->id = 1;

        $competitor = new Competitor([
            'customer_id' => 1,
            'name' => 'Rival Co',
            'url' => 'https://rival.com',
            'domain' => 'rival.com',
        ]);
        $competitor->id = 1;

        // Partial mock the agent to stub scrapeWebsite (protected, uses Browsershot)
        $agentMock = Mockery::mock(CompetitorAnalysisAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('scrapeWebsite')
            ->once()
            ->with('https://rival.com')
            ->andReturn([
                'content' => '<p>We are the best marketing agency.</p>',
                'title' => 'Rival Co - Marketing Agency',
                'meta_description' => 'Leading marketing agency',
                'headings' => ['About Us', 'Our Services'],
            ]);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'messaging' => ['tone' => 'professional', 'key_claims' => ['best agency']],
                    'value_propositions' => ['Full service marketing', 'Data-driven approach'],
                    'keywords_themes' => ['primary_keywords' => ['marketing', 'agency', 'digital']],
                    'pricing' => ['model' => 'custom'],
                    'counter_strategy' => ['differentiate on AI-powered approach'],
                ]),
            ]);

        // Mock the agent to intercept model persistence
        $agentMock->shouldReceive('persistAnalysis')->andReturnNull();

        Log::spy();

        $result = $agentMock->analyze($competitor, $customer);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertArrayHasKey('analysis', $result);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_analyze_all_skips_recently_analyzed_competitors(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
        ]);
        $customer->id = 1;

        $agentMock = Mockery::mock(CompetitorAnalysisAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock the query for competitors needing analysis to return only the stale one
        $staleCompetitor = new Competitor([
            'customer_id' => 1,
            'name' => 'Stale Rival',
            'url' => 'https://stale-rival.com',
            'domain' => 'stale-rival.com',
            'last_analyzed_at' => now()->subDays(10),
        ]);
        $staleCompetitor->id = 2;

        $agentMock->shouldReceive('getCompetitorsNeedingAnalysis')
            ->once()
            ->andReturn(collect([$staleCompetitor]));

        // Only the stale competitor should be analyzed
        $agentMock->shouldReceive('scrapeWebsite')
            ->once()
            ->andReturn([
                'content' => '<p>Some content</p>',
                'title' => 'Stale Rival',
                'meta_description' => 'desc',
                'headings' => [],
            ]);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'messaging' => [],
                    'value_propositions' => [],
                    'keywords_themes' => ['primary_keywords' => []],
                    'pricing' => [],
                ]),
            ]);

        $agentMock->shouldReceive('persistAnalysis')->andReturnNull();

        $results = $agentMock->analyzeAll($customer);

        $this->assertEquals(1, $results['analyzed']);
        $this->assertEquals(0, $results['failed']);
    }

    public function test_analyze_handles_scraping_failure_gracefully(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 1;

        $competitor = new Competitor([
            'customer_id' => 1,
            'name' => 'Unreachable',
            'url' => 'https://unreachable.com',
            'domain' => 'unreachable.com',
        ]);
        $competitor->id = 3;

        $agentMock = Mockery::mock(CompetitorAnalysisAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('scrapeWebsite')
            ->once()
            ->andReturn(['content' => null, 'title' => null, 'meta_description' => null, 'headings' => []]);

        $result = $agentMock->analyze($competitor, $customer);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to scrape competitor website', $result['error']);
    }

    public function test_analyze_handles_gemini_failure_returns_partial(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 1;

        $competitor = new Competitor([
            'customer_id' => 1,
            'name' => 'Target',
            'url' => 'https://target-rival.com',
            'domain' => 'target-rival.com',
        ]);
        $competitor->id = 4;

        $agentMock = Mockery::mock(CompetitorAnalysisAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('scrapeWebsite')
            ->once()
            ->andReturn([
                'content' => '<p>Target rival content</p>',
                'title' => 'Target Rival',
                'meta_description' => 'Target description',
                'headings' => ['Home'],
            ]);

        // Gemini returns no text
        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn(['text' => null]);

        Log::spy();

        $result = $agentMock->analyze($competitor, $customer);

        $this->assertFalse($result['success']);
        $this->assertEquals('No response from AI model', $result['error']);
    }
}
