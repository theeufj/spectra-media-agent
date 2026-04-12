<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\HarvestedAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class AssetHarvestingService
{
    protected GeminiService $gemini;

    /** Minimum width to consider an image worth harvesting */
    protected int $minWidth = 400;

    /** Minimum height */
    protected int $minHeight = 300;

    /** Max images to harvest per customer (kept low to limit Gemini Vision classification costs) */
    protected int $maxPerCustomer = 15;

    /** Skip common icon/decoration patterns */
    protected array $skipPatterns = [
        '/favicon/i', '/icon/i', '/logo.*small/i', '/sprite/i', '/pixel/i',
        '/tracking/i', '/badge/i', '/emoji/i', '/arrow/i', '/loader/i',
        '/spinner/i', '/placeholder/i', '/1x1/i', '/spacer/i',
    ];

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Extract image URLs from a customer's crawled pages.
     * Returns an array of ['url' => ..., 'page_url' => ..., 'page_id' => ...].
     */
    public function extractImageUrls(Customer $customer): array
    {
        $pages = CustomerPage::where('customer_id', $customer->id)->get();

        if ($pages->isEmpty()) {
            Log::info('AssetHarvestingService: No crawled pages found', ['customer_id' => $customer->id]);
            return [];
        }

        $imageUrls = [];
        $seenUrls = [];

        foreach ($pages as $page) {
            $urls = $this->extractFromPage($page);

            foreach ($urls as $url) {
                $normalized = $this->normalizeUrl($url, $page->url);
                if (!$normalized || isset($seenUrls[$normalized])) {
                    continue;
                }

                if ($this->shouldSkip($normalized)) {
                    continue;
                }

                $seenUrls[$normalized] = true;
                $imageUrls[] = [
                    'url' => $normalized,
                    'page_url' => $page->url,
                    'page_id' => $page->id,
                ];

                if (count($imageUrls) >= $this->maxPerCustomer) {
                    break 2;
                }
            }
        }

        Log::info('AssetHarvestingService: Extracted image URLs', [
            'customer_id' => $customer->id,
            'count' => count($imageUrls),
            'pages_scanned' => $pages->count(),
        ]);

        return $imageUrls;
    }

    /**
     * Extract image URLs from a single page's HTML content.
     */
    protected function extractFromPage(CustomerPage $page): array
    {
        $urls = [];

        // Try to re-fetch the page HTML for image extraction
        try {
            $html = Browsershot::url($page->url)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->timeout(30)
                ->waitUntilNetworkIdle()
                ->bodyHtml();
        } catch (\Exception $e) {
            // Fallback to HTTP fetch
            try {
                $response = Http::timeout(15)->get($page->url);
                $html = $response->successful() ? $response->body() : null;
            } catch (\Exception $e2) {
                Log::warning('AssetHarvestingService: Failed to fetch page', [
                    'url' => $page->url,
                    'error' => $e2->getMessage(),
                ]);
                return [];
            }
        }

        if (!$html) {
            return [];
        }

        $crawler = new Crawler($html, $page->url);

        // 1. <img> tags
        $crawler->filter('img')->each(function (Crawler $node) use (&$urls) {
            $src = $node->attr('src');
            $srcset = $node->attr('srcset');

            if ($src) {
                $urls[] = $src;
            }

            // Pick the largest from srcset
            if ($srcset) {
                $largest = $this->parseLargestFromSrcset($srcset);
                if ($largest) {
                    $urls[] = $largest;
                }
            }
        });

        // 2. <picture> source tags
        $crawler->filter('picture source')->each(function (Crawler $node) use (&$urls) {
            $srcset = $node->attr('srcset');
            if ($srcset) {
                $largest = $this->parseLargestFromSrcset($srcset);
                if ($largest) {
                    $urls[] = $largest;
                }
            }
        });

        // 3. OpenGraph images
        $crawler->filter('meta[property="og:image"]')->each(function (Crawler $node) use (&$urls) {
            $content = $node->attr('content');
            if ($content) {
                $urls[] = $content;
            }
        });

        // 4. Schema.org Product images from JSON-LD
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$urls) {
            try {
                $data = json_decode($node->text(), true);
                if (!$data) {
                    return;
                }
                $this->extractSchemaImages($data, $urls);
            } catch (\Exception $e) {
                // Skip malformed JSON-LD
            }
        });

        return $urls;
    }

    /**
     * Recursively extract image URLs from Schema.org JSON-LD data.
     */
    protected function extractSchemaImages(array $data, array &$urls): void
    {
        if (isset($data['image'])) {
            $images = is_array($data['image']) ? $data['image'] : [$data['image']];
            foreach ($images as $img) {
                if (is_string($img)) {
                    $urls[] = $img;
                } elseif (is_array($img) && isset($img['url'])) {
                    $urls[] = $img['url'];
                }
            }
        }

        // Check @graph for arrays of items
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                if (is_array($item)) {
                    $this->extractSchemaImages($item, $urls);
                }
            }
        }
    }

    /**
     * Pick the largest URL from a srcset attribute.
     */
    protected function parseLargestFromSrcset(string $srcset): ?string
    {
        $candidates = explode(',', $srcset);
        $best = null;
        $bestWidth = 0;

        foreach ($candidates as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if (count($parts) < 1) {
                continue;
            }

            $url = $parts[0];
            $descriptor = $parts[1] ?? '0w';
            $width = (int) str_replace('w', '', $descriptor);

            if ($width > $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }

        return $best;
    }

    /**
     * Download an image and return its binary data + metadata.
     * Returns null if the image doesn't meet quality thresholds.
     */
    public function downloadAndValidate(string $url): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SiteToSpend/1.0)'])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type');

            // Must be an image
            if (!$contentType || !str_starts_with($contentType, 'image/')) {
                return null;
            }

            // Skip SVGs (not usable as ad images)
            if (str_contains($contentType, 'svg')) {
                return null;
            }

            // Check dimensions using GD
            $imageInfo = @getimagesizefromstring($body);
            if (!$imageInfo) {
                return null;
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width < $this->minWidth || $height < $this->minHeight) {
                return null;
            }

            // Skip tiny file sizes (likely placeholders)
            if (strlen($body) < 5000) {
                return null;
            }

            return [
                'data' => $body,
                'mime_type' => $contentType,
                'width' => $width,
                'height' => $height,
                'file_size' => strlen($body),
            ];
        } catch (\Exception $e) {
            Log::debug('AssetHarvestingService: Download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Classify an image using Gemini Vision.
     */
    public function classifyImage(string $imageBase64, string $mimeType): ?array
    {
        $prompt = <<<PROMPT
Classify this image for use in digital advertising. Respond in JSON only.

{
  "classification": "product|lifestyle|team|logo|decorative|junk",
  "confidence": 0.0-1.0,
  "description": "Brief description of what the image shows",
  "ad_suitable": true|false,
  "suggested_platforms": ["google_display", "facebook_feed", "instagram_stories", "linkedin"],
  "dominant_colors": ["#hex1", "#hex2"],
  "has_text_overlay": true|false,
  "has_transparent_background": true|false,
  "recommended_crops": {
    "landscape_1200x628": true|false,
    "square_1080x1080": true|false,
    "vertical_1080x1920": true|false
  }
}

Classification guide:
- "product": Clear product shot (item on plain/studio background, product packaging, product in use)
- "lifestyle": People using products, lifestyle scenes, aspirational imagery
- "team": Team photos, headshots, office/workspace imagery
- "logo": Brand logos, wordmarks, icon-only graphics
- "decorative": Abstract patterns, backgrounds, textures, decorative elements
- "junk": Screenshots, compressed thumbnails, watermarked stock photos, UI elements, charts
PROMPT;

        $response = $this->gemini->generateContent(
            'gemini-3-flash-preview',
            $prompt,
            ['responseMimeType' => 'application/json'],
            'You are an expert image classifier for digital advertising. Be strict about quality.',
            false,
            false,
            2,
            $imageBase64,
            $mimeType
        );

        if (!$response || !isset($response['text'])) {
            return null;
        }

        return $this->parseJson($response['text']);
    }

    /**
     * Remove background from a product image using Gemini image editing.
     */
    public function removeBackground(string $imageBase64, string $mimeType): ?array
    {
        $prompt = 'Remove the background from this product image completely. Make the background pure white (#FFFFFF). Keep the product perfectly intact with clean edges. Do not crop or resize the product.';

        return $this->gemini->refineImage($prompt, [
            ['mime_type' => $mimeType, 'data' => $imageBase64],
        ]);
    }

    /**
     * Generate a platform-specific variant using Gemini generative fill.
     */
    public function generateVariant(string $imageBase64, string $mimeType, string $targetFormat, array $brandColors = []): ?array
    {
        $dimensions = [
            'landscape' => ['width' => 1200, 'height' => 628, 'ratio' => '1.91:1'],
            'square' => ['width' => 1080, 'height' => 1080, 'ratio' => '1:1'],
            'vertical' => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
        ];

        $dim = $dimensions[$targetFormat] ?? $dimensions['landscape'];
        $colorContext = !empty($brandColors) ? 'Use these brand colors for any generated background: ' . implode(', ', $brandColors) . '.' : '';

        $prompt = "Resize this image to {$dim['ratio']} aspect ratio ({$dim['width']}x{$dim['height']} pixels) for a digital ad. "
            . "Extend the canvas using generative fill to create a natural-looking background. "
            . "Keep the main subject centered and fully visible. Do not crop the subject. "
            . "{$colorContext} "
            . "The result should look professional and suitable for paid advertising.";

        return $this->gemini->refineImage($prompt, [
            ['mime_type' => $mimeType, 'data' => $imageBase64],
        ]);
    }

    protected function normalizeUrl(string $url, string $pageUrl): ?string
    {
        $url = trim($url);

        if (empty($url) || str_starts_with($url, 'data:')) {
            return null;
        }

        // Handle protocol-relative URLs
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        // Handle relative URLs
        if (!str_starts_with($url, 'http')) {
            $parsed = parse_url($pageUrl);
            $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            $url = str_starts_with($url, '/') ? $base . $url : $base . '/' . $url;
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    protected function shouldSkip(string $url): bool
    {
        foreach ($this->skipPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        return false;
    }

    protected function parseJson(string $text): array
    {
        $cleaned = trim($text);
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }
        return json_decode(trim($cleaned), true) ?? [];
    }
}
