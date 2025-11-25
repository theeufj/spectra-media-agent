<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LandingPageAudit;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Landing Page CRO Audit Service
 * 
 * Automatically audits landing pages for conversion optimization issues:
 * - Page Speed (Core Web Vitals)
 * - CTA Detection (Above the fold)
 * - Message Match Analysis (using AI)
 */
class LandingPageCROAuditService
{
    protected GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Perform a comprehensive CRO audit on a landing page.
     */
    public function auditPage(Customer $customer, string $url, string $html, ?array $crawledData = []): LandingPageAudit
    {
        Log::info("Starting CRO audit for page", [
            'customer_id' => $customer->id,
            'url' => $url,
        ]);

        $startTime = microtime(true);

        try {
            $crawler = new Crawler($html, $url);

            // 1. Page Speed Analysis
            $loadTimeMs = isset($crawledData['load_time_ms']) ? $crawledData['load_time_ms'] : null;
            $pageSpeed = $this->analyzePageSpeed($html, $loadTimeMs);

            // 2. CTA Detection
            $ctaAnalysis = $this->detectCTAs($crawler);

            // 3. Message Match Analysis (AI-powered)
            $messageAnalysis = $this->analyzeMessageMatch($crawler, $customer);

            // 4. Compile Issues and Recommendations
            $issues = $this->compileIssues($pageSpeed, $ctaAnalysis, $messageAnalysis);
            $recommendations = $this->generateRecommendations($issues);

            // 5. Calculate Overall Score
            $overallScore = $this->calculateOverallScore($pageSpeed, $ctaAnalysis, $messageAnalysis);

            // 6. Save Audit
            $audit = LandingPageAudit::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'url' => $url,
                ],
                [
                    'load_time_ms' => $pageSpeed['load_time_ms'],
                    'page_size_kb' => $pageSpeed['page_size_kb'],
                    'dom_elements' => $pageSpeed['dom_elements'],
                    'core_web_vitals' => $pageSpeed['core_web_vitals'],
                    'has_above_fold_cta' => $ctaAnalysis['has_above_fold_cta'],
                    'cta_buttons' => $ctaAnalysis['cta_buttons'],
                    'cta_count' => $ctaAnalysis['cta_count'],
                    'primary_cta' => $ctaAnalysis['primary_cta'],
                    'message_match_score' => $messageAnalysis['score'],
                    'message_analysis' => $messageAnalysis['analysis'],
                    'keywords_found' => $messageAnalysis['keywords'],
                    'issues' => $issues,
                    'recommendations' => $recommendations,
                    'overall_score' => $overallScore,
                    'audited_at' => now(),
                ]
            );

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            Log::info("CRO audit completed", [
                'customer_id' => $customer->id,
                'url' => $url,
                'overall_score' => $overallScore,
                'duration_ms' => $duration,
            ]);

            return $audit;

        } catch (\Exception $e) {
            Log::error("CRO audit failed", [
                'customer_id' => $customer->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Analyze page speed and performance metrics.
     */
    protected function analyzePageSpeed(string $html, ?int $loadTimeMs): array
    {
        $crawler = new Crawler($html);

        // Calculate page size (approximate from HTML length)
        $pageSizeKb = round(strlen($html) / 1024, 2);

        // Count DOM elements
        $domElements = $crawler->filter('*')->count();

        // Estimate Core Web Vitals (simplified)
        $coreWebVitals = [
            'lcp' => $this->estimateLCP($html),
            'fid' => $this->estimateFID($html),
            'cls' => $this->estimateCLS($html),
        ];

        return [
            'load_time_ms' => $loadTimeMs,
            'page_size_kb' => $pageSizeKb,
            'dom_elements' => $domElements,
            'core_web_vitals' => $coreWebVitals,
        ];
    }

    /**
     * Estimate Largest Contentful Paint (LCP).
     */
    protected function estimateLCP(string $html): string
    {
        $crawler = new Crawler($html);
        $imageCount = $crawler->filter('img')->count();
        $videoCount = $crawler->filter('video')->count();

        // Simple heuristic
        if ($imageCount > 10 || $videoCount > 2) {
            return 'needs-improvement'; // > 2.5s
        }

        return 'good'; // < 2.5s
    }

    /**
     * Estimate First Input Delay (FID).
     */
    protected function estimateFID(string $html): string
    {
        $scriptCount = substr_count($html, '<script');

        if ($scriptCount > 20) {
            return 'needs-improvement';
        }

        return 'good';
    }

    /**
     * Estimate Cumulative Layout Shift (CLS).
     */
    protected function estimateCLS(string $html): string
    {
        $crawler = new Crawler($html);
        $imagesWithoutDimensions = 0;

        $crawler->filter('img')->each(function (Crawler $node) use (&$imagesWithoutDimensions) {
            if (!$node->attr('width') || !$node->attr('height')) {
                $imagesWithoutDimensions++;
            }
        });

        if ($imagesWithoutDimensions > 5) {
            return 'needs-improvement';
        }

        return 'good';
    }

    /**
     * Detect and analyze Call-to-Action buttons.
     */
    protected function detectCTAs(Crawler $crawler): array
    {
        $ctaButtons = [];
        $hasAboveFoldCTA = false;

        // Look for common CTA patterns
        $ctaSelectors = [
            'button',
            'a.button',
            'a.btn',
            'input[type="submit"]',
            '[role="button"]',
            '.cta',
            '.call-to-action',
        ];

        foreach ($ctaSelectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$ctaButtons) {
                    $text = trim($node->text());
                    if (!empty($text)) {
                        $ctaButtons[] = [
                            'text' => $text,
                            'type' => $node->nodeName(),
                            'classes' => $node->attr('class'),
                        ];
                    }
                });
            } catch (\Exception $e) {
                // Continue if selector fails
            }
        }

        // Check for above-the-fold CTA (first 3 CTAs are likely above fold)
        $hasAboveFoldCTA = count($ctaButtons) > 0;

        // Identify primary CTA (usually the first button or most prominent)
        $primaryCTA = !empty($ctaButtons) ? $ctaButtons[0]['text'] : null;

        return [
            'cta_buttons' => $ctaButtons,
            'cta_count' => count($ctaButtons),
            'has_above_fold_cta' => $hasAboveFoldCTA,
            'primary_cta' => $primaryCTA,
        ];
    }

    /**
     * Analyze message match using AI.
     */
    protected function analyzeMessageMatch(Crawler $crawler, Customer $customer): array
    {
        try {
            // Extract page headline and key messaging
            $h1 = $crawler->filter('h1')->first()->text('No H1 found');
            $metaDescription = $crawler->filter('meta[name="description"]')->first()->attr('content', '');
            
            // Get first paragraph of body content
            $bodyText = $crawler->filter('body')->first()->text('');
            $firstParagraph = substr($bodyText, 0, 500);

            // Prepare prompt for Gemini
            $prompt = "Analyze this landing page content for message clarity and conversion optimization:\n\n" .
                      "Headline (H1): {$h1}\n" .
                      "Meta Description: {$metaDescription}\n" .
                      "First Content: {$firstParagraph}\n\n" .
                      "Provide:\n" .
                      "1. A message match score (0-100) based on clarity, relevance, and persuasiveness\n" .
                      "2. Key issues with the messaging\n" .
                      "3. Top 5 keywords/phrases detected\n\n" .
                      "Format as JSON with keys: score, analysis, keywords";

            $response = $this->geminiService->generateContent('gemini-2.0-flash-lite', $prompt);
            $text = $response['text'] ?? '';

            // Try to parse JSON response
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/', $text, $jsonMatch)) {
                $data = json_decode($jsonMatch[0], true);
                return [
                    'score' => $data['score'] ?? 50,
                    'analysis' => $data['analysis'] ?? 'Analysis unavailable',
                    'keywords' => $data['keywords'] ?? [],
                ];
            }

            // Fallback if AI response is not JSON
            return [
                'score' => 50,
                'analysis' => $text,
                'keywords' => $this->extractKeywords($h1 . ' ' . $metaDescription),
            ];

        } catch (\Exception $e) {
            Log::warning("Message match analysis failed", ['error' => $e->getMessage()]);
            
            return [
                'score' => 50,
                'analysis' => 'AI analysis unavailable',
                'keywords' => [],
            ];
        }
    }

    /**
     * Extract keywords from text (fallback method).
     */
    protected function extractKeywords(string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for'];
        
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        });

        return array_slice(array_unique($keywords), 0, 5);
    }

    /**
     * Compile issues based on audit results.
     */
    protected function compileIssues(array $pageSpeed, array $ctaAnalysis, array $messageAnalysis): array
    {
        $issues = [];

        // Page Speed Issues
        if (($pageSpeed['page_size_kb'] ?? 0) > 1000) {
            $issues[] = [
                'category' => 'performance',
                'severity' => 'high',
                'title' => 'Large Page Size',
                'description' => "Page size is {$pageSpeed['page_size_kb']}KB. Recommend optimizing images and removing unused code.",
            ];
        }

        if (($pageSpeed['dom_elements'] ?? 0) > 1500) {
            $issues[] = [
                'category' => 'performance',
                'severity' => 'medium',
                'title' => 'Too Many DOM Elements',
                'description' => "Page has {$pageSpeed['dom_elements']} DOM elements. This can slow down rendering.",
            ];
        }

        // CTA Issues
        if (!$ctaAnalysis['has_above_fold_cta']) {
            $issues[] = [
                'category' => 'cta',
                'severity' => 'critical',
                'title' => 'No Above-the-Fold CTA',
                'description' => 'No clear call-to-action button detected above the fold. Add a prominent CTA button.',
            ];
        }

        if ($ctaAnalysis['cta_count'] < 1) {
            $issues[] = [
                'category' => 'cta',
                'severity' => 'critical',
                'title' => 'Missing Call-to-Action',
                'description' => 'No CTA buttons found on the page. Every landing page needs a clear next step.',
            ];
        }

        if ($ctaAnalysis['cta_count'] > 5) {
            $issues[] = [
                'category' => 'cta',
                'severity' => 'medium',
                'title' => 'Too Many CTAs',
                'description' => "Found {$ctaAnalysis['cta_count']} CTAs. This can cause decision paralysis. Focus on 1-2 primary actions.",
            ];
        }

        // Message Match Issues
        if (($messageAnalysis['score'] ?? 50) < 60) {
            $issues[] = [
                'category' => 'messaging',
                'severity' => 'high',
                'title' => 'Weak Message Match',
                'description' => 'The page messaging lacks clarity or persuasiveness. Score: ' . ($messageAnalysis['score'] ?? 'N/A'),
            ];
        }

        return $issues;
    }

    /**
     * Generate actionable recommendations.
     */
    protected function generateRecommendations(array $issues): array
    {
        $recommendations = [];

        foreach ($issues as $issue) {
            $recommendation = [
                'issue_category' => $issue['category'],
                'priority' => $issue['severity'],
                'title' => 'Fix: ' . $issue['title'],
            ];

            switch ($issue['category']) {
                case 'performance':
                    $recommendation['action'] = 'Optimize images with compression, enable lazy loading, and minify CSS/JS files.';
                    break;
                case 'cta':
                    $recommendation['action'] = 'Add a prominent, action-oriented button above the fold (e.g., "Get Started Free", "Buy Now").';
                    break;
                case 'messaging':
                    $recommendation['action'] = 'Clarify your value proposition in the H1. Focus on benefits, not features. Test different headlines.';
                    break;
            }

            $recommendations[] = $recommendation;
        }

        return $recommendations;
    }

    /**
     * Calculate overall CRO health score (0-100).
     */
    protected function calculateOverallScore(array $pageSpeed, array $ctaAnalysis, array $messageAnalysis): int
    {
        $score = 100;

        // Performance scoring (-30 max)
        if (($pageSpeed['page_size_kb'] ?? 0) > 1000) {
            $score -= 15;
        }
        if (($pageSpeed['dom_elements'] ?? 0) > 1500) {
            $score -= 10;
        }
        if (($pageSpeed['core_web_vitals']['lcp'] ?? 'good') !== 'good') {
            $score -= 5;
        }

        // CTA scoring (-40 max)
        if (!$ctaAnalysis['has_above_fold_cta']) {
            $score -= 25;
        }
        if ($ctaAnalysis['cta_count'] < 1) {
            $score -= 30;
        } elseif ($ctaAnalysis['cta_count'] > 5) {
            $score -= 10;
        }

        // Message match scoring (-30 max)
        $messageScore = $messageAnalysis['score'] ?? 50;
        if ($messageScore < 60) {
            $score -= 30;
        } elseif ($messageScore < 75) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }
}
