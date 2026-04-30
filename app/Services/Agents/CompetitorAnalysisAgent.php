<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Competitor;
use App\Services\GeminiService;
use App\Services\FirecrawlService;
use App\Prompts\CompetitorAnalysisPrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * CompetitorAnalysisAgent
 * 
 * Scrapes competitor websites and uses AI to extract:
 * - Messaging and positioning
 * - Value propositions
 * - Pricing signals
 * - Counter-strategy recommendations
 */
class CompetitorAnalysisAgent
{
    protected GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Analyze all competitors that need analysis for a customer.
     */
    public function analyzeAll(Customer $customer): array
    {
        $results = [
            'customer_id' => $customer->id,
            'analyzed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $competitors = $this->getCompetitorsNeedingAnalysis($customer);

        foreach ($competitors as $competitor) {
            $analysisResult = $this->analyze($competitor, $customer);
            
            if ($analysisResult['success']) {
                $results['analyzed']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'competitor' => $competitor->domain,
                    'error' => $analysisResult['error'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get competitors that need analysis (older than given days or never analyzed).
     */
    protected function getCompetitorsNeedingAnalysis(Customer $customer, int $days = 7)
    {
        return $customer->competitors()
            ->needsAnalysis($days)
            ->get();
    }

    /**
     * Analyze a single competitor.
     */
    public function analyze(Competitor $competitor, Customer $customer): array
    {
        $result = [
            'success' => false,
            'competitor_id' => $competitor->id,
            'error' => null,
        ];

        try {
            Log::info('CompetitorAnalysisAgent: Analyzing competitor', [
                'competitor_id' => $competitor->id,
                'url' => $competitor->url,
            ]);

            // Step 1: Scrape competitor website
            $scrapedContent = $this->scrapeWebsite($competitor->url);
            
            if (empty($scrapedContent['content'])) {
                $result['error'] = 'Failed to scrape competitor website';
                return $result;
            }

            // Update raw content (guard against non-string Firecrawl metadata)
            $this->persistAnalysis($competitor, [
                'raw_content' => is_string($scrapedContent['content']) ? $scrapedContent['content'] : json_encode($scrapedContent['content']),
                'title' => is_string($scrapedContent['title'] ?? null) ? $scrapedContent['title'] : ($competitor->title ?? null),
                'meta_description' => is_string($scrapedContent['meta_description'] ?? null) ? $scrapedContent['meta_description'] : ($competitor->meta_description ?? null),
                'headings' => is_array($scrapedContent['headings'] ?? null) ? $scrapedContent['headings'] : [],
            ]);

            // Step 2: Get our business context
            $ourContext = $this->getBusinessContext($customer);

            // Step 3: Build analysis prompt
            $prompt = CompetitorAnalysisPrompt::build(
                $competitor->url,
                $this->truncateContent($scrapedContent['content'], 8000),
                $ourContext
            );

            $systemInstruction = CompetitorAnalysisPrompt::getSystemInstruction();

            // Step 4: Call AI for analysis
            $response = $this->gemini->generateContent(
                config('ai.models.default'),
                $prompt,
                ['responseMimeType' => 'application/json'],
                $systemInstruction,
                true  // Enable thinking for deep analysis
            );

            if (!$response || !isset($response['text'])) {
                $result['error'] = 'No response from AI model';
                return $result;
            }

            // Step 5: Parse and save analysis
            $analysis = $this->parseAnalysis($response['text']);
            
            if (empty($analysis)) {
                $result['error'] = 'Failed to parse AI analysis';
                return $result;
            }

            // Step 6: Update competitor record
            $updateData = [
                'messaging_analysis' => array_merge(
                    $competitor->messaging_analysis ?? [],
                    $analysis['messaging'] ?? []
                ),
                'value_propositions' => $analysis['value_propositions'] ?? [],
                'keywords_detected' => $this->extractAllKeywords($analysis),
                'pricing_info' => $analysis['pricing'] ?? [],
                'last_analyzed_at' => now(),
            ];

            // Store counter-strategy as a separate attribute
            if (isset($analysis['counter_strategy'])) {
                $updateData['messaging_analysis'] = array_merge(
                    $updateData['messaging_analysis'],
                    ['counter_strategy' => $analysis['counter_strategy']]
                );
            }

            $this->persistAnalysis($competitor, $updateData);

            $result['success'] = true;
            $result['analysis'] = $analysis;

            Log::info('CompetitorAnalysisAgent: Analysis complete', [
                'competitor_id' => $competitor->id,
                'keywords_found' => count($analysis['keywords_themes']['primary_keywords'] ?? []),
            ]);

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('CompetitorAnalysisAgent: Exception', [
                'competitor_id' => $competitor->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Persist analysis data to a competitor record.
     */
    protected function persistAnalysis(Competitor $competitor, array $data): void
    {
        $competitor->update($data);
    }

    /**
     * Scrape a competitor website.
     * Uses Firecrawl API (returns clean markdown) with HTTP fallback.
     */
    protected function scrapeWebsite(string $url): array
    {
        $result = [
            'content' => null,
            'title' => null,
            'meta_description' => null,
            'headings' => [],
        ];

        try {
            // Try Firecrawl first (returns clean markdown, handles JS rendering)
            $firecrawl = new FirecrawlService();

            if ($firecrawl->isConfigured()) {
                $scraped = $firecrawl->scrape($url);

                if ($scraped['success']) {
                    $result['content'] = $scraped['markdown'];
                    $result['title'] = $scraped['title'];
                    $result['meta_description'] = $scraped['meta_description'];
                    return $result;
                }

                Log::debug('CompetitorAnalysisAgent: Firecrawl failed, falling back to HTTP', [
                    'url' => $url,
                ]);
            }

            // Fallback to simple HTTP + DOM parsing
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; SpectraBot/1.0)',
            ])->timeout(15)->get($url);

            if (!$response->successful()) {
                return $result;
            }

            $html = $response->body();
            $crawler = new Crawler($html, $url);

            $result['title'] = $crawler->filter('title')->first()->text('');
            $result['meta_description'] = $crawler->filter('meta[name="description"]')->first()->attr('content', '');

            $headings = [];
            $crawler->filter('h1, h2, h3')->each(function (Crawler $node) use (&$headings) {
                $tag = $node->nodeName();
                $text = trim($node->text());
                if (!empty($text)) {
                    $headings[$tag][] = $text;
                }
            });
            $result['headings'] = $headings;

            $crawler->filter('header, nav, footer, script, style, noscript, .header, .nav, .footer, #header, #nav, #footer')
                ->each(function (Crawler $node) {
                    foreach ($node as $n) {
                        if ($n->parentNode) {
                            $n->parentNode->removeChild($n);
                        }
                    }
                });

            $textContent = $crawler->filter('body')->text('');
            $result['content'] = preg_replace('/\s+/', ' ', $textContent);

        } catch (\Exception $e) {
            Log::warning('CompetitorAnalysisAgent: Scrape failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get business context for competitive comparison.
     */
    protected function getBusinessContext(Customer $customer): string
    {
        $context = "Business: {$customer->name}\n";
        $context .= "Website: {$customer->website}\n";
        
        if ($customer->business_type) {
            $context .= "Type: {$customer->business_type}\n";
        }
        
        if ($customer->description) {
            $context .= "Description: {$customer->description}\n";
        }

        // Add brand guideline highlights if available
        $brandGuideline = $customer->brandGuideline;
        if ($brandGuideline) {
            if ($brandGuideline->unique_selling_propositions) {
                $usps = is_array($brandGuideline->unique_selling_propositions) 
                    ? implode(', ', $brandGuideline->unique_selling_propositions)
                    : $brandGuideline->unique_selling_propositions;
                $context .= "USPs: {$usps}\n";
            }
            
            if ($brandGuideline->target_audience) {
                $audience = is_array($brandGuideline->target_audience)
                    ? implode(', ', $brandGuideline->target_audience)
                    : $brandGuideline->target_audience;
                $context .= "Target Audience: {$audience}\n";
            }
        }

        return $context;
    }

    /**
     * Extract keywords from all relevant fields in the AI analysis.
     */
    protected function extractAllKeywords(array $analysis): array
    {
        $keywords = [];

        // Primary source: keywords_themes.primary_keywords
        foreach ($analysis['keywords_themes']['primary_keywords'] ?? [] as $kw) {
            $keywords[] = $kw;
        }

        // Secondary source: counter_strategy.keywords_to_target
        foreach ($analysis['counter_strategy']['keywords_to_target'] ?? [] as $kw) {
            $keywords[] = $kw;
        }

        // Tertiary source: keywords_themes.pain_points_addressed
        foreach ($analysis['keywords_themes']['pain_points_addressed'] ?? [] as $kw) {
            $keywords[] = $kw;
        }

        // Deduplicate case-insensitively, keep first occurrence
        $seen = [];
        $unique = [];
        foreach ($keywords as $kw) {
            $lower = strtolower(trim($kw));
            if ($lower && !isset($seen[$lower])) {
                $seen[$lower] = true;
                $unique[] = trim($kw);
            }
        }

        return $unique;
    }

    /**
     * Parse the AI analysis response.
     */
    protected function parseAnalysis(string $responseText): array
    {
        $cleaned = trim($responseText);
        
        // Remove markdown code blocks
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
            Log::warning('CompetitorAnalysisAgent: Failed to parse JSON', [
                'error' => json_last_error_msg(),
            ]);
            return [];
        }

        return $data;
    }

    /**
     * Truncate content to fit in context window.
     */
    protected function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }
        
        return substr($content, 0, $maxLength) . '... [truncated]';
    }
}
