<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Competitor;
use App\Services\GeminiService;
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

        $competitors = $customer->competitors()
            ->needsAnalysis(7) // Older than 7 days or never analyzed
            ->get();

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

            // Update raw content
            $competitor->update([
                'raw_content' => $scrapedContent['content'],
                'title' => $scrapedContent['title'] ?? $competitor->title,
                'meta_description' => $scrapedContent['meta_description'] ?? $competitor->meta_description,
                'headings' => $scrapedContent['headings'] ?? [],
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
                'gemini-2.5-pro',
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
            $competitor->update([
                'messaging_analysis' => array_merge(
                    $competitor->messaging_analysis ?? [],
                    $analysis['messaging'] ?? []
                ),
                'value_propositions' => $analysis['value_propositions'] ?? [],
                'keywords_detected' => $analysis['keywords_themes']['primary_keywords'] ?? [],
                'pricing_info' => $analysis['pricing'] ?? [],
                'last_analyzed_at' => now(),
            ]);

            // Store counter-strategy as a separate attribute
            if (isset($analysis['counter_strategy'])) {
                $competitor->update([
                    'messaging_analysis' => array_merge(
                        $competitor->messaging_analysis ?? [],
                        ['counter_strategy' => $analysis['counter_strategy']]
                    ),
                ]);
            }

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
     * Scrape a competitor website.
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
            // Try Browsershot first for JavaScript-heavy sites
            try {
                $html = Browsershot::url($url)
                    ->timeout(30)
                    ->waitUntilNetworkIdle()
                    ->bodyHtml();
            } catch (\Exception $e) {
                // Fallback to simple HTTP
                Log::debug('CompetitorAnalysisAgent: Browsershot failed, using HTTP', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SpectraBot/1.0)',
                ])->timeout(15)->get($url);
                
                if (!$response->successful()) {
                    return $result;
                }
                
                $html = $response->body();
            }

            $crawler = new Crawler($html, $url);

            // Extract title
            $result['title'] = $crawler->filter('title')->first()->text('');

            // Extract meta description
            $result['meta_description'] = $crawler->filter('meta[name="description"]')->first()->attr('content', '');

            // Extract headings
            $headings = [];
            $crawler->filter('h1, h2, h3')->each(function (Crawler $node) use (&$headings) {
                $tag = $node->nodeName();
                $text = trim($node->text());
                if (!empty($text)) {
                    $headings[$tag][] = $text;
                }
            });
            $result['headings'] = $headings;

            // Remove navigation, header, footer for cleaner content
            $crawler->filter('header, nav, footer, script, style, noscript, .header, .nav, .footer, #header, #nav, #footer')
                ->each(function (Crawler $node) {
                    foreach ($node as $n) {
                        if ($n->parentNode) {
                            $n->parentNode->removeChild($n);
                        }
                    }
                });

            // Extract main content
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
                $context .= "Target Audience: {$brandGuideline->target_audience}\n";
            }
        }

        return $context;
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
