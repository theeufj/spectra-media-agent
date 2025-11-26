<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GoogleSearchService
 * 
 * Provides programmatic access to Google Custom Search API for competitor discovery
 * and market research. This complements the Gemini Google Search grounding by
 * providing structured search results.
 */
class GoogleSearchService
{
    protected string $apiKey;
    protected string $searchEngineId;
    protected string $baseUrl = 'https://www.googleapis.com/customsearch/v1';

    public function __construct()
    {
        $this->apiKey = config('services.google.search_api_key');
        $this->searchEngineId = config('services.google.search_engine_id');
    }

    /**
     * Perform a Google Custom Search.
     *
     * @param string $query The search query
     * @param int $num Number of results (1-10)
     * @param int $start Starting index for pagination
     * @return array Search results
     */
    public function search(string $query, int $num = 10, int $start = 1): array
    {
        if (empty($this->apiKey) || empty($this->searchEngineId)) {
            Log::warning('GoogleSearchService: API key or Search Engine ID not configured');
            return ['error' => 'Search API not configured', 'items' => []];
        }

        try {
            $response = Http::get($this->baseUrl, [
                'key' => $this->apiKey,
                'cx' => $this->searchEngineId,
                'q' => $query,
                'num' => min($num, 10), // Max 10 per request
                'start' => $start,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('GoogleSearchService: Search completed', [
                    'query' => $query,
                    'total_results' => $data['searchInformation']['totalResults'] ?? 0,
                    'returned' => count($data['items'] ?? []),
                ]);

                return [
                    'total_results' => $data['searchInformation']['totalResults'] ?? 0,
                    'search_time' => $data['searchInformation']['searchTime'] ?? 0,
                    'items' => $this->formatResults($data['items'] ?? []),
                ];
            }

            Log::error('GoogleSearchService: Search failed', [
                'query' => $query,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return ['error' => 'Search request failed', 'items' => []];

        } catch (\Exception $e) {
            Log::error('GoogleSearchService: Exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage(), 'items' => []];
        }
    }

    /**
     * Search for competitors in a specific industry/niche.
     *
     * @param string $businessType The type of business
     * @param string $location Optional location filter
     * @return array Competitor search results
     */
    public function searchCompetitors(string $businessType, ?string $location = null): array
    {
        $queries = [
            "best {$businessType} companies",
            "top {$businessType} providers",
            "{$businessType} alternatives",
            "leading {$businessType} services",
        ];

        if ($location) {
            $queries[] = "{$businessType} in {$location}";
            $queries[] = "best {$businessType} {$location}";
        }

        $allResults = [];
        $seenDomains = [];

        foreach ($queries as $query) {
            $results = $this->search($query, 5);
            
            foreach ($results['items'] ?? [] as $item) {
                $domain = $this->extractDomain($item['link']);
                
                // Deduplicate by domain
                if (!isset($seenDomains[$domain])) {
                    $seenDomains[$domain] = true;
                    $item['domain'] = $domain;
                    $item['search_query'] = $query;
                    $allResults[] = $item;
                }
            }

            // Avoid rate limiting
            usleep(200000); // 200ms delay
        }

        return [
            'competitors' => $allResults,
            'queries_used' => $queries,
            'unique_domains' => count($seenDomains),
        ];
    }

    /**
     * Search for industry news and trends.
     */
    public function searchIndustryNews(string $industry, int $num = 10): array
    {
        $query = "{$industry} industry news trends 2025";
        return $this->search($query, $num);
    }

    /**
     * Search for specific company information.
     */
    public function searchCompany(string $companyName): array
    {
        $queries = [
            "{$companyName}",
            "{$companyName} reviews",
            "{$companyName} pricing",
        ];

        $results = [];
        foreach ($queries as $query) {
            $searchResults = $this->search($query, 3);
            $results[$query] = $searchResults['items'] ?? [];
            usleep(200000);
        }

        return $results;
    }

    /**
     * Format search results into a cleaner structure.
     */
    protected function formatResults(array $items): array
    {
        return array_map(function ($item) {
            return [
                'title' => $item['title'] ?? '',
                'link' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'display_link' => $item['displayLink'] ?? '',
                'domain' => $this->extractDomain($item['link'] ?? ''),
                'pagemap' => [
                    'description' => $item['pagemap']['metatags'][0]['og:description'] ?? null,
                    'site_name' => $item['pagemap']['metatags'][0]['og:site_name'] ?? null,
                ],
            ];
        }, $items);
    }

    /**
     * Extract domain from URL.
     */
    protected function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Check if the service is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->searchEngineId);
    }
}
