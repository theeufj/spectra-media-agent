<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Competitor;
use App\Services\GeminiService;
use App\Services\GoogleSearchService;
use App\Prompts\CompetitorDiscoveryPrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * CompetitorDiscoveryAgent
 * 
 * An agentic service that uses AI with Google Search grounding to discover
 * competitors based on the customer's website content and industry.
 * 
 * Flow:
 * 1. Gathers context from customer's knowledge base (sitemap content)
 * 2. Uses Google Custom Search API to find competitors programmatically
 * 3. Uses Gemini with Google Search grounding for AI-enhanced discovery
 * 4. Validates discovered URLs
 * 5. Stores new competitors in the database
 */
class CompetitorDiscoveryAgent
{
    protected GeminiService $gemini;
    protected GoogleSearchService $searchService;
    protected array $config;

    public function __construct(GeminiService $gemini, ?GoogleSearchService $searchService = null)
    {
        $this->gemini = $gemini;
        $this->searchService = $searchService ?? new GoogleSearchService();
        $this->config = config('competitive_intelligence', [
            'max_competitors' => 10,
            'discovery_model' => 'gemini-2.5-pro',
        ]);
    }

    /**
     * Discover competitors for a customer.
     *
     * @param Customer $customer The customer to find competitors for
     * @return array Results including discovered competitors
     */
    public function discover(Customer $customer): array
    {
        $results = [
            'customer_id' => $customer->id,
            'competitors_found' => [],
            'competitors_saved' => 0,
            'discovery_methods' => [],
            'errors' => [],
        ];

        try {
            // Step 1: Gather knowledge base content
            $knowledgeBaseContent = $this->getKnowledgeBaseSummary($customer);
            
            // Step 2: Get existing competitors to exclude
            $existingCompetitors = $customer->competitors()
                ->pluck('domain')
                ->toArray();

            Log::info('CompetitorDiscoveryAgent: Starting discovery', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
                'existing_competitors' => count($existingCompetitors),
            ]);

            // Step 3: Try Google Custom Search API first (structured results)
            if ($this->searchService->isConfigured()) {
                $searchResults = $this->discoverViaSearchAPI($customer, $existingCompetitors);
                
                foreach ($searchResults as $competitorData) {
                    $saved = $this->saveCompetitor($customer, $competitorData);
                    if ($saved) {
                        $results['competitors_saved']++;
                        $results['competitors_found'][] = $competitorData;
                    }
                }
                
                $results['discovery_methods'][] = 'google_custom_search';
            }

            // Step 4: Use Gemini with Google Search grounding for AI-enhanced discovery
            if (!empty($knowledgeBaseContent)) {
                $aiResults = $this->discoverViaGemini($customer, $knowledgeBaseContent, $existingCompetitors);
                
                foreach ($aiResults as $competitorData) {
                    $saved = $this->saveCompetitor($customer, $competitorData);
                    if ($saved) {
                        $results['competitors_saved']++;
                        $results['competitors_found'][] = $competitorData;
                    }
                }
                
                $results['discovery_methods'][] = 'gemini_grounded_search';
            }

            if (empty($results['competitors_found'])) {
                $results['errors'][] = 'No competitors discovered through any method';
            }

            Log::info('CompetitorDiscoveryAgent: Discovery complete', [
                'customer_id' => $customer->id,
                'found' => count($results['competitors_found']),
                'saved' => $results['competitors_saved'],
                'methods' => $results['discovery_methods'],
            ]);

        } catch (\Exception $e) {
            $results['errors'][] = 'Discovery failed: ' . $e->getMessage();
            Log::error('CompetitorDiscoveryAgent: Exception', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $results;
    }

    /**
     * Discover competitors using Google Custom Search API.
     */
    protected function discoverViaSearchAPI(Customer $customer, array $existingCompetitors): array
    {
        $competitors = [];
        $ourDomain = Competitor::extractDomain($customer->website);

        // Build search query based on business type/industry
        $businessType = $customer->business_type ?? $customer->industry ?? 'business';
        
        $searchResults = $this->searchService->searchCompetitors(
            $businessType,
            $customer->country
        );

        foreach ($searchResults['competitors'] ?? [] as $result) {
            $domain = $result['domain'] ?? '';
            
            // Skip if it's our own domain or already tracked
            if ($domain === $ourDomain || in_array($domain, $existingCompetitors)) {
                continue;
            }

            // Skip common non-competitor sites
            if ($this->isExcludedDomain($domain)) {
                continue;
            }

            $competitors[] = [
                'url' => $result['link'],
                'domain' => $domain,
                'name' => $result['title'] ?? $domain,
                'description' => $result['snippet'] ?? null,
                'competition_type' => 'direct',
                'why_competitor' => "Found via search: {$result['search_query']}",
                'discovery_source' => 'google_custom_search',
            ];
        }

        Log::info('CompetitorDiscoveryAgent: Search API results', [
            'customer_id' => $customer->id,
            'competitors_found' => count($competitors),
        ]);

        return $competitors;
    }

    /**
     * Discover competitors using Gemini with Google Search grounding.
     */
    protected function discoverViaGemini(Customer $customer, string $knowledgeBaseContent, array $existingCompetitors): array
    {
        $competitors = [];
        $ourDomain = Competitor::extractDomain($customer->website);

        // Build the discovery prompt with sitemap context
        $prompt = CompetitorDiscoveryPrompt::generate($customer, $knowledgeBaseContent, $existingCompetitors);

        try {
            // Use Gemini with Google Search grounding enabled
            $response = $this->gemini->generateContent(
                $prompt,
                $this->config['discovery_model'] ?? 'gemini-2.5-pro',
                [
                    'enableGoogleSearch' => true, // Enable Google Search grounding
                    'temperature' => 0.3, // Lower temperature for more focused results
                ]
            );

            $responseText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($responseText)) {
                Log::warning('CompetitorDiscoveryAgent: Empty Gemini response');
                return [];
            }

            // Parse the JSON response
            $discoveredCompetitors = $this->parseResponse($responseText);

            foreach ($discoveredCompetitors as $competitorData) {
                $domain = Competitor::extractDomain($competitorData['url'] ?? '');
                
                // Skip if it's our own domain or already tracked
                if ($domain === $ourDomain || in_array($domain, $existingCompetitors)) {
                    continue;
                }

                // Skip excluded domains
                if ($this->isExcludedDomain($domain)) {
                    continue;
                }

                $competitorData['domain'] = $domain;
                $competitorData['discovery_source'] = 'gemini_grounded_search';
                $competitors[] = $competitorData;
            }

            Log::info('CompetitorDiscoveryAgent: Gemini grounded search results', [
                'customer_id' => $customer->id,
                'competitors_found' => count($competitors),
            ]);

        } catch (\Exception $e) {
            Log::error('CompetitorDiscoveryAgent: Gemini search failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $competitors;
    }

    /**
     * Get a summary of the customer's knowledge base for context.
     */
    protected function getKnowledgeBaseSummary(Customer $customer): string
    {
        // Get the user's knowledge base content
        $user = $customer->users()->first();
        
        if (!$user) {
            return '';
        }

        // Get key pages from knowledge base
        $pages = $user->knowledgeBase()
            ->take(10)
            ->get(['title', 'content', 'url']);

        if ($pages->isEmpty()) {
            return '';
        }

        // Build a summary
        $summary = [];
        foreach ($pages as $page) {
            $content = substr($page->content, 0, 1000); // First 1000 chars
            $summary[] = "Page: {$page->title}\nURL: {$page->url}\nContent: {$content}";
        }

        return implode("\n\n---\n\n", $summary);
    }

    /**
     * Check if a domain should be excluded from competitor discovery.
     */
    protected function isExcludedDomain(string $domain): bool
    {
        $excludedDomains = [
            'wikipedia.org',
            'facebook.com',
            'twitter.com',
            'linkedin.com',
            'instagram.com',
            'youtube.com',
            'pinterest.com',
            'reddit.com',
            'yelp.com',
            'bbb.org',
            'google.com',
            'apple.com',
            'amazon.com',
            'ebay.com',
            'craigslist.org',
            'indeed.com',
            'glassdoor.com',
            'gov',
            'edu',
        ];

        foreach ($excludedDomains as $excluded) {
            if (str_contains($domain, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the AI response JSON.
     */
    protected function parseResponse(string $responseText): array
    {
        // Clean the response
        $cleaned = trim($responseText);
        
        // Remove markdown code blocks if present
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        $data = json_decode(trim($cleaned), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('CompetitorDiscoveryAgent: Failed to parse JSON response', [
                'error' => json_last_error_msg(),
                'response' => substr($responseText, 0, 500),
            ]);
            return [];
        }

        return $data;
    }

    /**
     * Validate and save a competitor.
     */
    protected function saveCompetitor(Customer $customer, array $data): bool
    {
        try {
            $url = $data['url'] ?? null;
            
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                Log::debug('CompetitorDiscoveryAgent: Invalid URL', ['url' => $url]);
                return false;
            }

            $domain = Competitor::extractDomain($url);

            // Check if competitor already exists
            $existing = $customer->competitors()->where('domain', $domain)->first();
            if ($existing) {
                Log::debug('CompetitorDiscoveryAgent: Competitor already exists', ['domain' => $domain]);
                return false;
            }

            // Validate URL is reachable (quick HEAD request)
            if (!$this->isUrlReachable($url)) {
                Log::debug('CompetitorDiscoveryAgent: URL not reachable', ['url' => $url]);
                return false;
            }

            // Create the competitor record
            $customer->competitors()->create([
                'url' => $url,
                'domain' => $domain,
                'name' => $data['name'] ?? $domain,
                'meta_description' => $data['description'] ?? null,
                'discovery_source' => 'google_search',
                'messaging_analysis' => [
                    'competition_type' => $data['competition_type'] ?? 'unknown',
                    'why_competitor' => $data['why_competitor'] ?? null,
                    'estimated_size' => $data['estimated_size'] ?? null,
                ],
            ]);

            return true;

        } catch (\Exception $e) {
            Log::warning('CompetitorDiscoveryAgent: Failed to save competitor', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return false;
        }
    }

    /**
     * Quick check if URL is reachable.
     */
    protected function isUrlReachable(string $url): bool
    {
        try {
            $response = Http::timeout(5)->head($url);
            return $response->successful() || $response->status() < 500;
        } catch (\Exception $e) {
            // Try GET as fallback (some servers don't support HEAD)
            try {
                $response = Http::timeout(5)->get($url);
                return $response->successful() || $response->status() < 500;
            } catch (\Exception $e) {
                return false;
            }
        }
    }
}
