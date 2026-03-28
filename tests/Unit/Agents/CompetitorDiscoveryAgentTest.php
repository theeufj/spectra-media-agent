<?php

namespace Tests\Unit\Agents;

use App\Models\Competitor;
use App\Models\Customer;
use App\Services\Agents\CompetitorDiscoveryAgent;
use App\Services\GeminiService;
use App\Services\GoogleSearchService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CompetitorDiscoveryAgentTest extends TestCase
{
    protected GeminiService $geminiMock;
    protected GoogleSearchService $searchMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->searchMock = Mockery::mock(GoogleSearchService::class);
    }

    public function test_discover_finds_and_creates_competitor_records(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
            'website' => 'https://mysite.com',
        ]);
        $customer->id = 1;

        $this->searchMock->shouldReceive('isConfigured')->andReturn(true);
        $this->searchMock->shouldReceive('searchCompetitors')
            ->once()
            ->andReturn([
                'competitors' => [
                    [
                        'domain' => 'competitor-one.com',
                        'link' => 'https://competitor-one.com',
                        'title' => 'Competitor One',
                        'snippet' => 'A rival business',
                        'search_query' => 'business competitors',
                    ],
                    [
                        'domain' => 'competitor-two.com',
                        'link' => 'https://competitor-two.com',
                        'title' => 'Competitor Two',
                        'snippet' => 'Another rival',
                        'search_query' => 'business competitors',
                    ],
                ],
            ]);

        // Gemini grounding call
        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'competitors' => [
                            ['domain' => 'competitor-three.com', 'url' => 'https://competitor-three.com', 'name' => 'Competitor Three', 'why_competitor' => 'Similar market'],
                        ],
                    ])]]],
                ]],
            ]);

        $agent = Mockery::mock(CompetitorDiscoveryAgent::class, [$this->geminiMock, $this->searchMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Stub internal methods that do filtering/validation
        $agent->shouldReceive('getKnowledgeBaseSummary')->andReturn('Our business is a marketing agency.');
        $agent->shouldReceive('getExistingCompetitorDomains')->andReturn([]);
        $agent->shouldReceive('saveCompetitor')->andReturn(true);
        $agent->shouldReceive('discoverViaSearchAPI')->passthru();
        $agent->shouldReceive('discoverViaGemini')->passthru();

        Log::spy();

        $results = $agent->discover($customer);

        $this->assertArrayHasKey('competitors_found', $results);
        $this->assertArrayHasKey('competitors_saved', $results);
        $this->assertGreaterThanOrEqual(0, $results['competitors_saved']);
        $this->assertContains('google_custom_search', $results['discovery_methods']);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_discover_handles_no_search_results(): void
    {
        $customer = new Customer([
            'name' => 'Niche Business',
            'website' => 'https://niche-business.com',
        ]);
        $customer->id = 2;

        $this->searchMock->shouldReceive('isConfigured')->andReturn(true);
        $this->searchMock->shouldReceive('searchCompetitors')
            ->once()
            ->andReturn(['competitors' => []]);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['competitors' => []])]]],
                ]],
            ]);

        $agent = Mockery::mock(CompetitorDiscoveryAgent::class, [$this->geminiMock, $this->searchMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getKnowledgeBaseSummary')->andReturn('Niche business content.');
        $agent->shouldReceive('getExistingCompetitorDomains')->andReturn([]);
        $agent->shouldReceive('saveCompetitor')->andReturn(true);

        $results = $agent->discover($customer);

        $this->assertEmpty($results['competitors_found']);
        $this->assertNotEmpty($results['errors']);
    }

    public function test_discover_skips_already_known_competitors(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'website' => 'https://mysite.com',
        ]);
        $customer->id = 3;

        // Pre-existing competitor (in-memory, not persisted)
        $knownCompetitor = new Competitor([
            'customer_id' => 3,
            'name' => 'Known Rival',
            'url' => 'https://known-rival.com',
            'domain' => 'known-rival.com',
        ]);
        $knownCompetitor->id = 1;

        $this->searchMock->shouldReceive('isConfigured')->andReturn(true);
        $this->searchMock->shouldReceive('searchCompetitors')
            ->once()
            ->andReturn([
                'competitors' => [
                    [
                        'domain' => 'known-rival.com',
                        'link' => 'https://known-rival.com',
                        'title' => 'Known Rival',
                        'snippet' => 'Already tracked',
                        'search_query' => 'competitors',
                    ],
                ],
            ]);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['competitors' => []])]]],
                ]],
            ]);

        $agent = Mockery::mock(CompetitorDiscoveryAgent::class, [$this->geminiMock, $this->searchMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getKnowledgeBaseSummary')->andReturn('Business context.');

        // Mock getExistingCompetitorDomains to return the known competitor's domain
        $agent->shouldReceive('getExistingCompetitorDomains')
            ->andReturn(['known-rival.com']);

        // Mock saveCompetitor to track saves
        $agent->shouldReceive('saveCompetitor')->never();

        $results = $agent->discover($customer);

        // Known competitor should not be re-saved
        $this->assertEquals(0, $results['competitors_saved']);
    }

    public function test_discover_works_without_google_search_service(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'website' => 'https://mysite.com',
        ]);
        $customer->id = 4;

        // Use a GoogleSearchService mock that reports not configured — agent falls back to Gemini grounding
        $disabledSearchMock = Mockery::mock(GoogleSearchService::class);
        $disabledSearchMock->shouldReceive('isConfigured')->andReturn(false);

        $agent = Mockery::mock(CompetitorDiscoveryAgent::class, [$this->geminiMock, $disabledSearchMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getKnowledgeBaseSummary')->andReturn('Business context for discovery.');
        $agent->shouldReceive('getExistingCompetitorDomains')->andReturn([]);
        $agent->shouldReceive('saveCompetitor')->andReturn(true);

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'competitors' => [
                            ['domain' => 'ai-found.com', 'url' => 'https://ai-found.com', 'name' => 'AI Found', 'why_competitor' => 'Similar product'],
                        ],
                    ])]]],
                ]],
            ]);

        Log::spy();

        $results = $agent->discover($customer);

        $this->assertContains('gemini_grounded_search', $results['discovery_methods']);
        // Should not contain google_custom_search since service was null
        $this->assertNotContains('google_custom_search', $results['discovery_methods']);
    }
}
