<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GeminiService;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V26\Services\KeywordForecastMetrics;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AffordableKeywordDiscoveryTest extends TestCase
{
    public function test_a_late_lower_cost_idea_is_reviewed_against_the_offer_before_selection(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $prompts = [];
        $gemini->expects($this->exactly(2))->method('generateContent')->willReturnCallback(function ($model, $prompt) use (&$prompts) {
            $prompts[] = $prompt;
            if (count($prompts) === 1) {
                return ['text' => '["actual paid offer"]'];
            }
            preg_match('/Candidates: (\[.*?\])/', $prompt, $matches);
            $candidates = json_decode($matches[1], true);
            $accepted = array_values(array_filter($candidates, fn ($candidate) => $candidate['keyword'] === 'different supported buying task'));
            $this->assertCount(1, $accepted, 'An affordable later result must survive the semantic-review quota.');

            return ['text' => json_encode(['reviews' => [['id' => $accepted[0]['id'], 'relevant' => true,
                'commercial_intent' => true, 'reason' => 'A buyer pays for the same supported service.']]])];
        });
        $ideas = array_map(fn ($i) => ['keyword' => 'expensive category '.$i, 'avg_monthly_searches' => 1000,
            'average_cpc_micros' => 20_000_000, 'competition_index' => 80], range(1, 299));
        $ideas[] = ['keyword' => 'different supported buying task', 'avg_monthly_searches' => 100,
            'average_cpc_micros' => 500_000, 'competition_index' => 10];
        $service = new AffordableDiscoveryFixture($gemini, $ideas);

        $result = $service->researchAffordableAlternatives('1234567890', 'Service business', 'B2B', 'https://example.com/setup',
            geoTargets: ['geoTargetConstants/2840'], maxKeywords: 15,
            businessContext: ['offer' => 'The paid one-time setup service', 'daily_budget_micros' => 50_000_000,
                'max_cpc_bid_micros' => 10_000_000, 'failed_candidates' => [['text' => 'expensive category', 'forecast_clicks' => 0.1]]]);

        $this->assertSame(['different supported buying task'], array_column($result['cost_candidates'], 'text'));
        $this->assertSame(500_000, $result['cost_candidates'][0]['cost_evidence']['amount_micros']);
        $this->assertSame([], $result['negative_keywords']);
        $this->assertSame('keyword_only', $result['discovery']['seed_mode']);
        $this->assertGreaterThanOrEqual(300, $service->request['limit']);
        $this->assertNull($service->request['url'], 'The broader homepage must not contaminate a targeted alternative theme.');
        $this->assertSame(['geoTargetConstants/2840'], $service->request['geos']);
        $this->assertStringContainsString('10000000', $prompts[0]);
        $this->assertStringContainsString('failed_candidates', $prompts[0]);
        $this->assertStringContainsString('never consumers seeking that vertical', $prompts[0]);
        $this->assertStringContainsString('https:\/\/example.com\/setup', $prompts[1]);
        $this->assertNull($service->profile(), 'The alternative pass cannot change subsequent ordinary discovery.');
    }

    public function test_unknown_or_zero_costs_do_not_become_free_affordable_candidates(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willReturn(['text' => '["actual paid offer"]']);
        $service = new AffordableDiscoveryFixture($gemini, [['keyword' => 'actual paid offer', 'avg_monthly_searches' => 100,
            'average_cpc_micros' => 0, 'low_top_of_page_bid_micros' => null, 'high_top_of_page_bid_micros' => 0]]);

        $result = $service->researchAffordableAlternatives('1234567890', 'Service business');

        $this->assertSame([], $result['cost_candidates']);
        $this->assertTrue($result['discovery']['planner_metrics_available']);
        $this->assertSame(100, $result['keywords'][0]['avg_monthly_searches']);
    }

    public function test_an_unavailable_alternative_seed_pass_cannot_leave_the_service_in_a_different_profile(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willThrowException(new \RuntimeException('Unavailable'));
        $service = new AffordableDiscoveryFixture($gemini, []);
        $this->expectException(\RuntimeException::class);
        try {
            $service->researchAffordableAlternatives('1234567890', 'Service business');
        } finally {
            $this->assertNull($service->profile());
        }
    }

    public function test_real_v26_forecast_accepts_the_candidate_manual_bid_in_the_complete_portfolio(): void
    {
        Cache::flush();
        $customer = new Customer(['name' => 'Paid service', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 90123;
        $campaign = new Campaign(['product_focus' => 'Paid setup service']);
        $campaign->id = 90123;
        $campaign->setRelation('customer', $customer);
        $research = new class extends KeywordResearchService
        {
            public function __construct() {}

            public function research(string $customerId, string $businessName, ?string $industry = null,
                ?string $landingPageUrl = null, ?string $language = 'languageConstants/1000', array $geoTargets = [],
                int $maxKeywords = 20, array $userSeedKeywords = [], array $businessContext = []): array
            {
                return ['keywords' => [['text' => 'another paid task', 'match_type' => 'EXACT',
                    'selection_reason' => 'The same paid setup offer.']]];
            }
        };
        $forecast = new class extends GenerateKeywordForecast
        {
            public array $requests = [];

            public function __construct() {}

            protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
            {
                $this->requests[] = $request;
                $count = count($request->getCampaign()->getAdGroups()[0]->getKeywords());

                return new GenerateKeywordForecastMetricsResponse(['campaign_forecast_metrics' => new KeywordForecastMetrics([
                    'clicks' => $count > 1 ? 40 : 5, 'cost_micros' => 1_000_000, 'average_cpc_micros' => 25_000,
                ])]);
            }
        };
        $planner = new class($research, $forecast) extends GoogleSearchReachPlanner
        {
            public function __construct(private KeywordResearchService $research, private GenerateKeywordForecast $forecast) {}

            protected function researchProvider(Customer $customer): KeywordResearchService
            {
                return $this->research;
            }

            protected function forecastProvider(Customer $customer): GenerateKeywordForecast
            {
                return $this->forecast;
            }

            protected function historical(Customer $customer, array $texts, array $context): array
            {
                return ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => array_map(fn ($text) => [
                    'text' => $text, 'avg_monthly_searches' => 100, 'low_top_of_page_bid_micros' => 1_000_000,
                ], $texts)];
            }
        };
        $snapshot = ['bidding_strategy' => 3, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'cpc_bid_ceiling_micros' => 1_000_000, 'daily_budget_micros' => 10_000_000,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => [],
            'negative_keywords' => [], 'ad_schedule' => [], 'search_partners_enabled' => false,
            'excluded_geo_target_constants' => [], 'device_bid_modifiers' => [], 'unsupported_targeting' => [],
            'keywords' => [['text' => 'original paid task', 'match_type' => 'EXACT', 'status' => 'ENABLED',
                'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/42',
                'ad_group_cpc_bid_micros' => 1_000_000, 'cpc_bid_micros' => 1_000_000]]];

        $result = $planner->assess($campaign, $snapshot, true);

        $this->assertTrue($result['auto_repair_safe']);
        $this->assertSame([], $result['combined_forecast']['caveats']);
        $this->assertSame([1_000_000, 1_000_000], array_column($result['combined_forecast']['context']['keywords'], 'cpc_bid_micros'));
        $this->assertCount(3, $forecast->requests);
    }
}

class AffordableDiscoveryFixture extends KeywordResearchService
{
    public array $request = [];

    public function __construct(GeminiService $gemini, private array $ideas)
    {
        $this->customer = new Customer(['name' => 'Service business', 'website' => 'https://example.com']);
        $this->gemini = $gemini;
    }

    public function profile(): ?string
    {
        return $this->discoveryProfile;
    }

    protected function expandWithKeywordPlanner(string $customerId, array $seedKeywords, ?string $url, ?string $language, array $geoTargets, int $maxResults): array
    {
        $this->request = ['limit' => $maxResults, 'url' => $url, 'geos' => $geoTargets];

        return $this->ideas;
    }
}
