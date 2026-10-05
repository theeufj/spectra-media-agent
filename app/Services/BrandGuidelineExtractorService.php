<?php

namespace App\Services;

use App\Exceptions\BrandExtractionFailed;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Prompts\BrandGuidelineExtractionPrompt;
use App\Services\Brands\BrandProfileReview;
use App\Services\Onboarding\PlaceholderSiteDetector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrandGuidelineExtractorService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Check if customer can extract brand guidelines (plan-based limit).
     *
     * Free: 1 guideline per customer
     * Starter: up to 3
     * Growth / Agency: unlimited
     */
    protected function canExtractGuidelines(Customer $customer): bool
    {
        if ($customer->brandGuideline()->exists()) {
            return true;
        }

        // Was users()->first()->resolveCurrentPlan(), and false when nobody
        // was attached — brand extraction runs during onboarding, which is
        // exactly when that is true.
        $slug = $customer->resolvePlan()->slug;

        // Growth / Agency — unlimited
        if (in_array($slug, ['growth', 'agency'], true)) {
            return true;
        }

        $existing = BrandGuideline::where('customer_id', $customer->id)->count();

        // Starter — up to 3
        if ($slug === 'starter') {
            return $existing < 3;
        }

        // Free — 1
        return $existing < 1;
    }

    /**
     * Extract brand guidelines from customer's website and knowledge base
     */
    /**
     * Join content chunks under a hard budget, keeping the caller's priority
     * order and capping each chunk so one giant page can't crowd out the
     * rest.
     *
     * The extractor used to concatenate everything unbounded: a 405-page
     * store put 1.25M characters into a single prompt and extraction failed
     * silently, so a customer with the RICHEST content got NO brand
     * guidelines — while an 8-page site extracted at quality 96.
     *
     * @param  list<string>  $chunks
     */
    public static function budgetedContent(array $chunks, int $perChunk = 8000, int $total = 120000): string
    {
        $kept = [];
        $used = 0;

        foreach ($chunks as $chunk) {
            if ($used >= $total) {
                break;
            }
            $piece = mb_substr($chunk, 0, min($perChunk, $total - $used));
            $kept[] = $piece;
            $used += mb_strlen($piece);
        }

        return implode("\n\n---PAGE BREAK---\n\n", $kept);
    }

    /**
     * The page as a person sees it, not as it arrives on the wire.
     *
     * The demo read text with a plain HTTP GET and a regex over <title>,
     * <meta description> and <h1>. For anything rendered client-side that is
     * almost nothing: yourfirststore.com returns 52 characters of visible text
     * to a fetch and 3,732 to a browser, and even its <title> differs, because
     * the real one is set after hydration. A React, Vue or Next storefront —
     * which is most of them now — handed the ad-copy prompt a couple of
     * sentences of boilerplate and got boilerplate back.
     *
     * Chromium is already running on this server for the screenshot in the same
     * request. This reads the text out of the same render rather than
     * pretending the page is static.
     *
     * Returns null on any failure; the caller keeps whatever the plain fetch
     * gave it.
     */
    public function renderedText(string $websiteUrl, int $limit = 20000): ?string
    {
        try {
            $html = app(\App\Services\Crawling\WebsiteRenderer::class)->html($websiteUrl);

            $text = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html);
            // A space per tag, not strip_tags: adjacent elements have no
            // whitespace between them in the DOM, so stripping alone welds
            // "yourfirststore" to "Sign in" and the model reads one word.
            $text = preg_replace('/<[^>]+>/', ' ', (string) $text);
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));

            return $text === '' ? null : mb_substr($text, 0, $limit);
        } catch (\Throwable $e) {
            // A site that will not render is the caller's problem to report, not
            // a reason to fail the request.
            report($e);
            Log::warning('renderedText failed for '.$websiteUrl.': '.$e->getMessage());

            return null;
        }
    }

    /** A content revision, independent of queue/index timestamps. */
    public static function sourceFingerprint(Customer $customer): string
    {
        $sources = KnowledgeBase::where('customer_id', $customer->id)->orderBy('id')->get();
        $evidence = $sources->whereNull('excluded_at')->filter(fn ($source) => trim((string) $source->content) !== '')
            ->map(fn ($source) => ['id' => $source->id, 'url' => $source->url, 'title' => $source->title ?: $source->original_filename, 'type' => $source->source_type, 'content' => hash('sha256', (string) $source->content)])->values()->all();
        $pages = \App\Models\CustomerPage::where('customer_id', $customer->id)->whereNotIn('url', $sources->pluck('url')->filter()->all())->orderBy('id')->get();
        foreach ($pages as $page) {
            if (trim((string) $page->content) !== '' && (! $customer->website || parse_url($page->url, PHP_URL_HOST) === parse_url($customer->website, PHP_URL_HOST))) {
                $evidence[] = ['page' => $page->id, 'url' => $page->url, 'title' => $page->title, 'content' => hash('sha256', (string) $page->content)];
            }
        }

        return hash('sha256', json_encode([$customer->website, $evidence], JSON_THROW_ON_ERROR));
    }

    public function extractGuidelines(Customer $customer): ?BrandGuideline
    {
        try {
            Log::info("Starting brand guideline extraction for customer {$customer->id}");

            // Check subscription limits
            if (! $this->canExtractGuidelines($customer)) {
                Log::info('Brand guideline extraction skipped - limit reached for free users', [
                    'customer_id' => $customer->id,
                ]);

                return null;
            }

            // Included uploads and business briefs are first-class evidence. URL
            // mirrors must not duplicate a CustomerPage or resurrect an excluded source.
            $sourceFingerprint = self::sourceFingerprint($customer);
            $allSources = KnowledgeBase::where('customer_id', $customer->id)->get();
            $included = $allSources->whereNull('excluded_at')->filter(fn ($source) => trim((string) $source->content) !== '')
                ->sortBy(fn ($source) => $source->source_type === 'url' ? 1 : 0);
            $entries = [];
            foreach ($included as $source) {
                $label = $source->title ?: $source->original_filename ?: $source->url ?: 'Business information';
                $entries[] = ['content' => "--- SOURCE: {$label} | URL: {$source->url} ---\n".$source->content,
                    'source' => ['id' => $source->id, 'label' => $label, 'url' => $source->url, 'type' => $source->source_type, 'version' => $source->source_version, 'content_hash' => $source->content_hash ?: hash('sha256', (string) $source->content), 'updated_at' => $source->updated_at?->toIso8601String()]];
            }
            $knownUrls = $allSources->pluck('url')->filter()->all();
            $pages = \App\Models\CustomerPage::where('customer_id', $customer->id)->whereNotIn('url', $knownUrls)
                ->orderByRaw("CASE WHEN page_type IN ('service', 'money', 'product') THEN 1 WHEN page_type IN ('landing', 'about') THEN 2 ELSE 3 END")->get()
                ->filter(fn ($page) => ! $customer->website || parse_url($page->url, PHP_URL_HOST) === parse_url($customer->website, PHP_URL_HOST));
            foreach ($pages as $page) {
                $entries[] = ['content' => "--- PAGE TYPE: {$page->page_type} | URL: {$page->url} ---\n".$page->content,
                    'source' => ['id' => null, 'label' => $page->title ?: $page->url, 'url' => $page->url, 'type' => 'url', 'updated_at' => $page->updated_at?->toIso8601String()]];
            }
            $chunks = [];
            $sourceSnapshot = [];
            $used = 0;
            foreach ($entries as $entry) {
                if ($used >= 120000) {
                    break;
                }
                $piece = mb_substr($entry['content'], 0, min(8000, 120000 - $used));
                $chunks[] = $piece;
                $sourceSnapshot[] = $entry['source'];
                $used += mb_strlen($piece);
            }
            $websiteContent = implode("\n\n---SOURCE BREAK---\n\n", $chunks);

            if (empty($websiteContent)) {
                Log::warning("No knowledge base content found for customer {$customer->id}");

                return null;
            }

            /*
               Is this the customer's business, or the page standing where it
               should be? The quality score cannot answer that — it measures how
               cleanly the page parsed, and a parked domain parses beautifully.
               One scored 94 while describing the domain broker sitting on the
               address instead of the signup's business.

               Extraction still runs. The warning travels with the result so the
               review screen can lead with the doubt rather than presenting
               someone else's brand as fact.
            */
            $extractionWarning = app(PlaceholderSiteDetector::class)
                ->warningFor($websiteContent, $customer->website);

            if ($extractionWarning) {
                Log::info('Brand extraction ran against content that may not be the customer\'s', [
                    'customer_id' => $customer->id,
                    'website' => $customer->website,
                    'warning' => $extractionWarning,
                ]);
            }

            // Step 2: Scrape and analyze homepage for visual elements
            $visualAnalysis = $customer->website ? $this->analyzeVisualStyle($customer->website) : [];

            // Step 3: Build extraction prompt
            $prompt = (new BrandGuidelineExtractionPrompt(
                $websiteContent,
                $visualAnalysis,
                $customer->industry ?? 'general'
            ))->getPrompt();

            Log::info('Calling Gemini for brand guideline extraction', [
                'customer_id' => $customer->id,
                'content_length' => strlen($websiteContent),
            ]);

            // Step 4: Call Gemini with extended thinking for deep analysis
            $response = $this->geminiService->generateContent(config('ai.models.default'), $prompt);

            if (! $response || ! isset($response['text'])) {
                Log::error('Failed to generate brand guidelines from Gemini', [
                    'customer_id' => $customer->id,
                ]);

                // Thrown, not returned: a model that did not answer this time
                // is the definition of worth retrying, and only a throw reaches
                // the job's retry budget.
                throw BrandExtractionFailed::because('the model returned no text');
            }

            // Step 5: Parse and validate response
            $cleanedJson = $this->cleanJsonResponse($response['text']);
            $guidelines = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse brand guidelines JSON', [
                    'customer_id' => $customer->id,
                    'error' => json_last_error_msg(),
                    'response_preview' => substr($response['text'], 0, 500),
                ]);

                throw BrandExtractionFailed::because('the model returned malformed JSON');
            }

            // Step 6: Validate required fields
            if (! $this->validateGuidelines($guidelines)) {
                Log::error('Brand guidelines missing required fields', [
                    'customer_id' => $customer->id,
                    'guidelines' => $guidelines,
                ]);

                throw BrandExtractionFailed::because('the model omitted required fields');
            }

            $review = app(BrandProfileReview::class);
            $profile = array_replace(array_fill_keys(BrandProfileReview::FIELDS, []), \Illuminate\Support\Arr::only($guidelines, BrandProfileReview::FIELDS));
            $metadata = [
                'extraction_quality_score' => $guidelines['extraction_quality_score'] ?? 50,
                'extraction_warning' => $extractionWarning,
                'extracted_at' => now(),
                'source_snapshot' => $sourceSnapshot,
                'source_fingerprint' => $sourceFingerprint,
            ];
            $existing = BrandGuideline::where('customer_id', $customer->id)->first();
            $brandGuideline = $existing
                ? $review->propose($existing, $profile, $metadata)
                : BrandGuideline::create($profile + $metadata + ['customer_id' => $customer->id, 'user_verified' => false]);

            Log::info('Successfully extracted brand guidelines', [
                'customer_id' => $customer->id,
                'brand_guideline_id' => $brandGuideline->id,
                'quality_score' => $brandGuideline->extraction_quality_score,
            ]);

            return $brandGuideline;

        } catch (\Throwable $e) {
            report($e);
            Log::error('Error extracting brand guidelines', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            /*
             * Rethrown rather than swallowed.
             *
             * This caught everything and returned null, so a timeout, a 429 or
             * a TypeError all arrived at the job as "this website cannot be
             * read" — final, unretried, and mailed to the customer as their
             * fault. The job is the right place to decide how many times to
             * try; it cannot decide anything about an exception it never sees.
             */
            throw $e instanceof BrandExtractionFailed ? $e : BrandExtractionFailed::because($e->getMessage());
        }
    }

    /**
     * Analyze visual style from homepage HTML
     */
    public function analyzeVisualStyle(string $websiteUrl): array
    {
        try {
            Log::info("Analyzing visual style for: {$websiteUrl}");

            // Implement Robots.txt check before ethical scraping
            try {
                $parsedUrl = parse_url($websiteUrl);
                $baseUrl = ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '');
                $robotsUrl = $baseUrl.'/robots.txt';

                $robotsTxtContent = \Illuminate\Support\Facades\Cache::remember("robots.txt.{$baseUrl}", 3600, function () use ($robotsUrl) {
                    try {
                        $response = app(\App\Services\Crawling\PublicWebsiteFetcher::class)->get($robotsUrl);

                        return $response->successful() ? $response->body() : '';
                    } catch (\Throwable $e) {
                        report($e);

                        return '';
                    }
                });

                if (! empty($robotsTxtContent)) {
                    $robots = new \Spatie\Robots\RobotsTxt($robotsTxtContent);
                    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
                    if (! $robots->allows($websiteUrl, $userAgent)) {
                        Log::warning('Robots.txt disallowed scraping for visual style analysis', ['url' => $websiteUrl]);

                        return $this->getDefaultVisualAnalysis();
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                Log::notice('Could not parse robots.txt, proceeding cautiously', ['error' => $e->getMessage()]);
            }

            // Try Browsershot with Screenshot for Vision AI
            try {
                $screenshot = app(\App\Services\Crawling\WebsiteRenderer::class)->screenshot($websiteUrl);

                Log::info('Screenshot captured, sending to Gemini Vision AI');

                // Call Gemini Vision
                $prompt = "Analyze this website screenshot. Extract the visual brand identity. Return a JSON object with these keys: 'primary_colors' (array of hex codes), 'fonts' (array of font descriptions or names), 'image_style' (string description), 'layout_style' (string description).";

                $response = $this->geminiService->generateContent(
                    config('ai.models.default'),
                    $prompt,
                    ['responseMimeType' => 'application/json'],
                    null,
                    false,
                    false,
                    3,
                    $screenshot,
                    'image/png'
                );

                if ($response && isset($response['text'])) {
                    $analysis = json_decode($this->cleanJsonResponse($response['text']), true);
                    if ($analysis) {
                        Log::info('Visual analysis completed via Vision AI');

                        return $analysis;
                    }
                }

            } catch (\Throwable $e) {
                report($e);
                Log::warning('Vision AI analysis failed, falling back to HTML scraping', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Fallback to HTML scraping if Vision AI fails
            try {
                $html = app(\App\Services\Crawling\WebsiteRenderer::class)->html($websiteUrl);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Browsershot HTML fetch failed, falling back to HTTP', [
                    'error' => $e->getMessage(),
                ]);
                // Fallback to simple HTTP request
                $response = app(\App\Services\Crawling\PublicWebsiteFetcher::class)->get($websiteUrl);
                $html = $response->successful() ? $response->body() : '';
            }

            if (empty($html)) {
                Log::warning('Failed to fetch HTML for visual analysis');

                return $this->getDefaultVisualAnalysis();
            }

            return [
                'primary_colors' => $this->extractColors($html),
                'fonts' => $this->extractFonts($html),
                'image_style' => $this->detectImageStyle($html),
                'layout_style' => $this->detectLayoutStyle($html),
            ];

        } catch (\Throwable $e) {
            report($e);
            Log::error('Error analyzing visual style: '.$e->getMessage());

            return $this->getDefaultVisualAnalysis();
        }
    }

    /**
     * Extract color palette from HTML/CSS
     */
    private function extractColors(string $html): array
    {
        $colors = [];

        // Extract hex colors from inline styles and style tags
        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $html, $matches);

        if (! empty($matches[0])) {
            // Normalize 3-digit hex to 6-digit
            $hexColors = array_map(function ($color) {
                $color = strtoupper(ltrim($color, '#'));
                if (strlen($color) === 3) {
                    $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
                }

                return '#'.$color;
            }, $matches[0]);

            // Count occurrences and get most common colors
            $colorCounts = array_count_values($hexColors);
            arsort($colorCounts);

            // Filter out near-white and near-black (usually backgrounds)
            $filteredColors = array_filter(array_keys($colorCounts), function ($color) {
                // Skip #FFFFFF, #000000, and very close variants
                return ! in_array($color, ['#FFFFFF', '#000000', '#FAFAFA', '#F5F5F5', '#111111']);
            });

            $colors = array_slice($filteredColors, 0, 6); // Top 6 colors
        }

        return ! empty($colors) ? $colors : ['#0066CC', '#333333']; // Default fallback
    }

    /**
     * Extract font families from HTML/CSS
     */
    private function extractFonts(string $html): array
    {
        $fonts = [];

        // Look for font-family declarations
        preg_match_all('/font-family:\s*([^;}"]+)/i', $html, $matches);

        if (! empty($matches[1])) {
            foreach ($matches[1] as $fontDeclaration) {
                // Split by comma and clean up
                $fontList = explode(',', $fontDeclaration);
                foreach ($fontList as $font) {
                    $font = trim($font, " '\"");
                    // Skip generic font families
                    if (! in_array(strtolower($font), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'])) {
                        $fonts[] = $font;
                    }
                }
            }

            // Get unique fonts
            $fonts = array_unique($fonts);
            $fonts = array_slice($fonts, 0, 5); // Top 5 fonts
        }

        return ! empty($fonts) ? $fonts : ['Arial', 'Helvetica']; // Default fallback
    }

    /**
     * Detect image style (photography, illustrations, etc.)
     */
    private function detectImageStyle(string $html): string
    {
        // Look for image file extensions and SVG usage
        $jpgCount = substr_count(strtolower($html), '.jpg') + substr_count(strtolower($html), '.jpeg');
        $pngCount = substr_count(strtolower($html), '.png');
        $svgCount = substr_count(strtolower($html), '.svg') + substr_count(strtolower($html), '<svg');
        $gifCount = substr_count(strtolower($html), '.gif');

        // Determine dominant image type
        if ($svgCount > ($jpgCount + $pngCount)) {
            return 'illustrations and icons';
        } elseif ($jpgCount > $pngCount * 2) {
            return 'photography-heavy';
        } elseif ($gifCount > 5) {
            return 'animated and dynamic';
        } else {
            return 'mixed photography and graphics';
        }
    }

    /**
     * Detect layout style
     */
    private function detectLayoutStyle(string $html): string
    {
        // Simple heuristic based on common patterns
        if (stripos($html, 'grid') !== false || stripos($html, 'display: grid') !== false) {
            return 'grid-based layout';
        } elseif (stripos($html, 'flex') !== false || stripos($html, 'display: flex') !== false) {
            return 'flexible, modern layout';
        } else {
            return 'traditional layout';
        }
    }

    /**
     * Get default visual analysis when scraping fails
     */
    private function getDefaultVisualAnalysis(): array
    {
        return [
            'primary_colors' => ['#0066CC', '#333333'],
            'fonts' => ['Arial', 'Helvetica'],
            'image_style' => 'mixed content',
            'layout_style' => 'modern',
        ];
    }

    /**
     * Clean JSON response from AI (remove markdown fences, etc.)
     */
    private function cleanJsonResponse(string $text): string
    {
        // Remove markdown code fences
        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $text);

        // Trim whitespace
        $cleaned = trim($cleaned);

        return $cleaned;
    }

    /**
     * Validate that guidelines have all required fields
     */
    private function validateGuidelines(array $guidelines): bool
    {
        $requiredFields = [
            'brand_voice',
            'tone_attributes',
            'color_palette',
            'typography',
            'visual_style',
            'messaging_themes',
            'unique_selling_propositions',
            'target_audience',
            'brand_personality',
        ];

        foreach ($requiredFields as $field) {
            if (! isset($guidelines[$field]) || empty($guidelines[$field])) {
                Log::warning("Missing required field in brand guidelines: {$field}");

                return false;
            }
        }

        return true;
    }
}
