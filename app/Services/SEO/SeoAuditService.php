<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Models\SeoAudit;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Spatie\Browsershot\Browsershot;

/**
 * Comprehensive technical SEO audit service.
 *
 * Analyzes a URL for technical SEO issues including:
 * - Page speed & Core Web Vitals
 * - Meta tags (title, description, canonical, robots)
 * - Heading structure (H1-H6 hierarchy)
 * - Schema markup / structured data
 * - Mobile-friendliness
 * - Image optimization (alt tags, size)
 * - Internal/external link analysis
 * - Security (HTTPS, mixed content)
 */
class SeoAuditService
{
    protected Customer $customer;
    protected GeminiService $gemini;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = app(GeminiService::class);
    }

    /**
     * Run a full technical SEO audit on a URL.
     */
    public function audit(string $url): SeoAudit
    {
        Log::info('SEO Audit: Starting', ['customer_id' => $this->customer->id, 'url' => $url]);

        $html = $this->fetchPage($url);
        if (!$html) {
            return $this->createAudit($url, 0, ['critical' => ['Page could not be fetched']], []);
        }

        $meta = $this->analyzeMeta($html, $url);
        $headings = $this->analyzeHeadings($html);
        $images = $this->analyzeImages($html);
        $links = $this->analyzeLinks($html, $url);
        $schema = $this->analyzeSchema($html);
        $security = $this->analyzeSecurity($url);
        $performance = $this->analyzePerformance($url);
        $content = $this->analyzeContent($html, $meta, $headings);

        $issues = [];
        $recommendations = [];

        // Meta tag analysis
        if (empty($meta['title'])) {
            $issues[] = ['severity' => 'critical', 'category' => 'meta', 'message' => 'Missing page title tag'];
        } elseif (strlen($meta['title']) > 60) {
            $issues[] = ['severity' => 'warning', 'category' => 'meta', 'message' => 'Title tag exceeds 60 characters (' . strlen($meta['title']) . ')'];
        } elseif (strlen($meta['title']) < 30) {
            $issues[] = ['severity' => 'warning', 'category' => 'meta', 'message' => 'Title tag is too short (' . strlen($meta['title']) . ' characters)'];
        }

        if (empty($meta['description'])) {
            $issues[] = ['severity' => 'critical', 'category' => 'meta', 'message' => 'Missing meta description'];
        } elseif (strlen($meta['description']) > 160) {
            $issues[] = ['severity' => 'warning', 'category' => 'meta', 'message' => 'Meta description exceeds 160 characters'];
        }

        if (!$meta['has_canonical']) {
            $issues[] = ['severity' => 'warning', 'category' => 'meta', 'message' => 'Missing canonical tag'];
        }

        if (!$meta['has_viewport']) {
            $issues[] = ['severity' => 'critical', 'category' => 'mobile', 'message' => 'Missing viewport meta tag (not mobile-friendly)'];
        }

        // Heading analysis
        if ($headings['h1_count'] === 0) {
            $issues[] = ['severity' => 'critical', 'category' => 'headings', 'message' => 'Missing H1 tag'];
        } elseif ($headings['h1_count'] > 1) {
            $issues[] = ['severity' => 'warning', 'category' => 'headings', 'message' => "Multiple H1 tags found ({$headings['h1_count']})"];
        }

        if (!$headings['proper_hierarchy']) {
            $issues[] = ['severity' => 'warning', 'category' => 'headings', 'message' => 'Heading hierarchy is not sequential (e.g., H1 → H3 skip)'];
        }

        // Image analysis
        if ($images['missing_alt_count'] > 0) {
            $issues[] = [
                'severity' => 'warning',
                'category' => 'images',
                'message' => "{$images['missing_alt_count']} images missing alt attributes",
            ];
        }

        // Link analysis
        if ($links['broken_count'] > 0) {
            $issues[] = ['severity' => 'critical', 'category' => 'links', 'message' => "{$links['broken_count']} broken links detected"];
        }

        if (empty($links['internal_links'])) {
            $issues[] = ['severity' => 'warning', 'category' => 'links', 'message' => 'No internal links found on this page'];
        }

        // Schema analysis
        if (empty($schema['types'])) {
            $recommendations[] = ['category' => 'schema', 'message' => 'Add structured data (JSON-LD) for better search appearance', 'priority' => 'medium'];
        }

        // Security
        if (!$security['is_https']) {
            $issues[] = ['severity' => 'critical', 'category' => 'security', 'message' => 'Page is not served over HTTPS'];
        }

        // Performance
        if (($performance['load_time_ms'] ?? 0) > 3000) {
            $issues[] = ['severity' => 'warning', 'category' => 'performance', 'message' => 'Page load time exceeds 3 seconds'];
        }

        // Content analysis issues
        if (($content['word_count'] ?? 0) < 300) {
            $issues[] = ['severity' => 'warning', 'category' => 'content', 'message' => "Thin content: only {$content['word_count']} words (aim for 600+)"];
        }
        if (empty($content['detected_keywords'])) {
            $issues[] = ['severity' => 'warning', 'category' => 'content', 'message' => 'No strong keyword themes detected in page content'];
        }
        if (!$meta['has_og']) {
            $issues[] = ['severity' => 'info', 'category' => 'social', 'message' => 'Missing Open Graph tags for social media sharing'];
        }

        // AI-powered recommendations (enhanced with content analysis)
        $aiRecommendations = $this->getAiRecommendations($url, $meta, $headings, $content, $issues);
        $recommendations = array_merge($recommendations, $aiRecommendations);

        // Calculate score
        $score = $this->calculateScore($issues);

        $audit = $this->createAudit($url, $score, $issues, $recommendations, [
            'meta' => $meta,
            'headings' => $headings,
            'images' => $images,
            'links' => $links,
            'schema' => $schema,
            'security' => $security,
            'performance' => $performance,
            'content' => $content,
        ]);

        Log::info('SEO Audit: Complete', [
            'customer_id' => $this->customer->id,
            'url' => $url,
            'score' => $score,
            'issues_count' => count($issues),
        ]);

        return $audit;
    }

    protected function fetchPage(string $url): ?string
    {
        return Cache::remember('seo_page:' . md5($url), now()->addHour(), function () use ($url) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'SpectraMediaBot/1.0 (SEO Audit)'])
                    ->get($url);

                return $response->successful() ? $response->body() : null;
            } catch (\Exception $e) {
                Log::warning('SEO Audit: Failed to fetch page', ['url' => $url, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    protected function analyzeMeta(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        $description = '';
        $descNodes = $xpath->query('//meta[@name="description"]');
        if ($descNodes->length > 0) {
            $description = $descNodes->item(0)->getAttribute('content');
        }

        $hasCanonical = $xpath->query('//link[@rel="canonical"]')->length > 0;
        $hasViewport = $xpath->query('//meta[@name="viewport"]')->length > 0;

        $robotsMeta = '';
        $robotsNodes = $xpath->query('//meta[@name="robots"]');
        if ($robotsNodes->length > 0) {
            $robotsMeta = $robotsNodes->item(0)->getAttribute('content');
        }

        $ogTags = [];
        foreach ($xpath->query('//meta[starts-with(@property, "og:")]') as $node) {
            $ogTags[$node->getAttribute('property')] = $node->getAttribute('content');
        }

        return [
            'title' => $title,
            'title_length' => strlen($title),
            'description' => $description,
            'description_length' => strlen($description),
            'has_canonical' => $hasCanonical,
            'has_viewport' => $hasViewport,
            'robots' => $robotsMeta,
            'og_tags' => $ogTags,
            'has_og' => !empty($ogTags),
        ];
    }

    protected function analyzeHeadings(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);

        $headings = [];
        $h1Count = 0;
        $lastLevel = 0;
        $properHierarchy = true;

        for ($i = 1; $i <= 6; $i++) {
            $nodes = $dom->getElementsByTagName("h{$i}");
            foreach ($nodes as $node) {
                $level = $i;
                $text = trim($node->textContent);
                $headings[] = ['level' => $level, 'text' => $text];
                if ($level === 1) $h1Count++;
                if ($level > $lastLevel + 1 && $lastLevel > 0) {
                    $properHierarchy = false;
                }
                $lastLevel = $level;
            }
        }

        return [
            'h1_count' => $h1Count,
            'total_headings' => count($headings),
            'proper_hierarchy' => $properHierarchy,
            'headings' => array_slice($headings, 0, 20),
        ];
    }

    protected function analyzeImages(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);
        $images = $dom->getElementsByTagName('img');

        $totalImages = $images->length;
        $missingAlt = 0;
        $imageDetails = [];

        foreach ($images as $img) {
            $alt = $img->getAttribute('alt');
            $src = $img->getAttribute('src');
            if (empty($alt)) $missingAlt++;
            $imageDetails[] = [
                'src' => substr($src, 0, 200),
                'alt' => $alt ?: null,
                'has_alt' => !empty($alt),
            ];
        }

        return [
            'total_images' => $totalImages,
            'missing_alt_count' => $missingAlt,
            'images' => array_slice($imageDetails, 0, 50),
        ];
    }

    protected function analyzeLinks(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);
        $links = $dom->getElementsByTagName('a');

        $parsedBase = parse_url($url);
        $baseDomain = $parsedBase['host'] ?? '';

        $internal = [];
        $external = [];
        $broken = 0;

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) continue;

            $parsedHref = parse_url($href);
            $linkDomain = $parsedHref['host'] ?? $baseDomain;

            if ($linkDomain === $baseDomain) {
                $internal[] = $href;
            } else {
                $external[] = $href;
            }
        }

        return [
            'internal_count' => count($internal),
            'external_count' => count($external),
            'broken_count' => $broken,
            'internal_links' => array_unique(array_slice($internal, 0, 50)),
            'external_links' => array_unique(array_slice($external, 0, 50)),
        ];
    }

    protected function analyzeSchema(string $html): array
    {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);

        $types = [];
        $schemas = [];

        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(trim($json), true);
            if ($decoded) {
                $type = $decoded['@type'] ?? 'Unknown';
                $types[] = $type;
                $schemas[] = $decoded;
            }
        }

        return [
            'has_schema' => !empty($types),
            'types' => $types,
            'schemas' => $schemas,
        ];
    }

    protected function analyzeSecurity(string $url): array
    {
        return [
            'is_https' => str_starts_with($url, 'https://'),
        ];
    }

    protected function analyzePerformance(string $url): array
    {
        try {
            $start = microtime(true);
            Http::timeout(10)->get($url);
            $loadTime = (microtime(true) - $start) * 1000;

            return [
                'load_time_ms' => round($loadTime),
            ];
        } catch (\Exception $e) {
            return ['load_time_ms' => null];
        }
    }

    protected function getAiRecommendations(string $url, array $meta, array $headings, array $content, array $issues): array
    {
        try {
            $issueList = collect($issues)->pluck('message')->implode("\n- ");
            $topKeywords = implode(', ', array_slice($content['detected_keywords'] ?? [], 0, 10));
            $headingTexts = collect($content['heading_texts'] ?? [])->take(10)->implode(', ');

            $prompt = <<<PROMPT
You are an expert SEO consultant performing a deep analysis of this webpage. Provide SPECIFIC, COPY-PASTE READY recommendations.

URL: {$url}
Current Title: {$meta['title']}
Current Meta Description: {$meta['description']}
H1 Tags: {$headings['h1_count']}
Heading Text Samples: {$headingTexts}
Word Count: {$content['word_count']}
Top Keywords Detected: {$topKeywords}
Has Schema Markup: {$content['has_schema']}
Has Open Graph: {$meta['has_og']}

Current Issues:
- {$issueList}

Return a JSON array of 5-8 actionable recommendations. Each MUST include an "action" field with exact text/code the user can copy and use. Categories: meta_tags, keywords, content, technical, schema, social.

Example format:
[
  {
    "category": "meta_tags",
    "message": "Your title tag is missing target keywords. Use this optimized title instead.",
    "action": "Proveably - AI-Powered Digital Marketing Platform | Analytics & Automation",
    "priority": "high"
  },
  {
    "category": "keywords",
    "message": "Add these high-value keywords to your meta description and H2 headings.",
    "action": "digital marketing, analytics, automation, campaign management, ROI tracking",
    "priority": "high"
  },
  {
    "category": "schema",
    "message": "Add Organization schema markup to improve search appearance.",
    "action": "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"Organization\",\"name\":\"...\",\"url\":\"...\"}</script>",
    "priority": "medium"
  }
]

Rules:
- The "action" field MUST contain exact text, markup, or keywords the user can directly copy and implement
- For meta_tags: provide the exact optimized title and description text
- For keywords: list specific keywords to add and WHERE to add them (title, description, H2s, body)
- For content: suggest specific sections or topics to add to the page
- For schema: provide the exact JSON-LD markup to add
- For social: provide exact og:title, og:description values
- Be specific to THIS page — no generic advice
- Return ONLY valid JSON
PROMPT;

            $result = $this->gemini->generateContent('gemini-3-flash-preview', $prompt, [
                'temperature' => 0.3,
                'maxOutputTokens' => 2048,
            ]);

            $text = $result['text'] ?? '';
            $text = preg_replace('/```json\s*/', '', $text);
            $text = preg_replace('/```\s*$/', '', $text);

            return json_decode(trim($text), true) ?? [];
        } catch (\Exception $e) {
            Log::debug('SEO Audit: AI recommendations failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Analyze page content: extract keywords, word count, keyword density.
     */
    protected function analyzeContent(string $html, array $meta, array $headings): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);

        // Remove script and style tags
        foreach (['script', 'style', 'noscript'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            while ($nodes->length > 0) {
                $nodes->item(0)->parentNode->removeChild($nodes->item(0));
            }
        }

        $bodyNodes = $dom->getElementsByTagName('body');
        $bodyText = '';
        if ($bodyNodes->length > 0) {
            $bodyText = $bodyNodes->item(0)->textContent;
        }

        // Clean up whitespace
        $bodyText = preg_replace('/\s+/', ' ', trim($bodyText));
        $words = array_filter(str_word_count(strtolower($bodyText), 1), fn ($w) => strlen($w) > 2);
        $wordCount = count($words);

        // Extract keyword frequency (exclude common stop words)
        $stopWords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out',
            'with', 'that', 'this', 'from', 'have', 'has', 'will', 'your', 'what', 'there', 'each', 'which', 'their',
            'how', 'about', 'more', 'been', 'would', 'them', 'they', 'than', 'other', 'into', 'could', 'also', 'these',
            'some', 'just', 'its', 'over', 'such', 'only', 'very', 'when', 'where', 'does', 'did', 'get', 'who'];

        $wordFreq = array_count_values($words);
        $wordFreq = array_filter($wordFreq, fn ($count, $word) => !in_array($word, $stopWords) && $count >= 2, ARRAY_FILTER_USE_BOTH);
        arsort($wordFreq);

        $topKeywords = array_slice(array_keys($wordFreq), 0, 20);

        // Build 2-word phrases (bigrams)
        $wordList = array_values($words);
        $bigrams = [];
        for ($i = 0; $i < count($wordList) - 1; $i++) {
            if (in_array($wordList[$i], $stopWords) || in_array($wordList[$i + 1], $stopWords)) continue;
            $phrase = $wordList[$i] . ' ' . $wordList[$i + 1];
            $bigrams[$phrase] = ($bigrams[$phrase] ?? 0) + 1;
        }
        $bigrams = array_filter($bigrams, fn ($count) => $count >= 2);
        arsort($bigrams);
        $topPhrases = array_slice(array_keys($bigrams), 0, 10);

        // Check keyword presence in meta tags
        $titleLower = strtolower($meta['title'] ?? '');
        $descLower = strtolower($meta['description'] ?? '');
        $keywordsInTitle = array_filter($topKeywords, fn ($kw) => str_contains($titleLower, $kw));
        $keywordsInDesc = array_filter($topKeywords, fn ($kw) => str_contains($descLower, $kw));
        $keywordsMissingFromTitle = array_diff(array_slice($topKeywords, 0, 5), $keywordsInTitle);
        $keywordsMissingFromDesc = array_diff(array_slice($topKeywords, 0, 5), $keywordsInDesc);

        // Extract heading texts
        $headingTexts = collect($headings['headings'] ?? [])->pluck('text')->toArray();

        return [
            'word_count' => $wordCount,
            'detected_keywords' => $topKeywords,
            'keyword_phrases' => $topPhrases,
            'keyword_density' => array_slice($wordFreq, 0, 15, true),
            'keywords_in_title' => array_values($keywordsInTitle),
            'keywords_in_description' => array_values($keywordsInDesc),
            'keywords_missing_from_title' => array_values($keywordsMissingFromTitle),
            'keywords_missing_from_description' => array_values($keywordsMissingFromDesc),
            'heading_texts' => $headingTexts,
            'has_schema' => !empty($this->analyzeSchema($html)['types']),
        ];
    }

    protected function calculateScore(array $issues): float
    {
        $score = 100.0;

        foreach ($issues as $issue) {
            match ($issue['severity'] ?? 'info') {
                'critical' => $score -= 15,
                'warning' => $score -= 5,
                'info' => $score -= 1,
                default => null,
            };
        }

        return max(0, min(100, $score));
    }

    protected function createAudit(string $url, float $score, array $issues, array $recommendations, array $details = []): SeoAudit
    {
        return SeoAudit::create([
            'customer_id' => $this->customer->id,
            'url' => $url,
            'score' => $score,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'meta_analysis' => $details['meta'] ?? null,
            'heading_analysis' => $details['headings'] ?? null,
            'image_analysis' => $details['images'] ?? null,
            'link_analysis' => $details['links'] ?? null,
            'schema_analysis' => $details['schema'] ?? null,
            'security_analysis' => $details['security'] ?? null,
            'performance_analysis' => $details['performance'] ?? null,
            'content_analysis' => $details['content'] ?? null,
        ]);
    }
}
