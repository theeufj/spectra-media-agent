<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class DiscoverNavigationUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public Customer $customer;
    public User $user;
    public array $alreadyCrawledUrls;

    public function __construct(Customer $customer, User $user, array $alreadyCrawledUrls = [])
    {
        $this->customer = $customer;
        $this->user = $user;
        $this->alreadyCrawledUrls = $alreadyCrawledUrls;
    }

    public function handle(): void
    {
        $websiteUrl = $this->customer->website;

        if (empty($websiteUrl)) {
            Log::warning('DiscoverNavigationUrls: No website URL for customer', [
                'customer_id' => $this->customer->id,
            ]);
            return;
        }

        Log::info('DiscoverNavigationUrls: Starting navigation discovery', [
            'customer_id' => $this->customer->id,
            'website' => $websiteUrl,
            'already_crawled_count' => count($this->alreadyCrawledUrls),
        ]);

        try {
            $html = Browsershot::url($websiteUrl)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->bodyHtml();
        } catch (\Exception $e) {
            Log::warning('DiscoverNavigationUrls: Browsershot failed, trying HTTP fallback', [
                'error' => $e->getMessage(),
            ]);
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->get($websiteUrl);
                $html = $response->successful() ? $response->body() : '';
            } catch (\Exception $e2) {
                Log::error('DiscoverNavigationUrls: All fetch methods failed', [
                    'error' => $e2->getMessage(),
                ]);
                return;
            }
        }

        if (empty($html)) {
            Log::warning('DiscoverNavigationUrls: Empty HTML from homepage');
            return;
        }

        $discoveredUrls = $this->extractNavigationLinks($html, $websiteUrl);

        // Also include already-crawled CustomerPage URLs in the comparison
        $existingPageUrls = CustomerPage::where('customer_id', $this->customer->id)
            ->pluck('url')
            ->toArray();

        $allKnownUrls = array_unique(array_merge(
            array_map(fn($url) => $this->normalizeUrl($url), $this->alreadyCrawledUrls),
            array_map(fn($url) => $this->normalizeUrl($url), $existingPageUrls),
        ));

        // Find URLs in the navigation that weren't in the sitemap
        $missingUrls = [];
        foreach ($discoveredUrls as $url) {
            $normalized = $this->normalizeUrl($url);
            if (!in_array($normalized, $allKnownUrls)) {
                $missingUrls[] = $url;
            }
        }

        if (empty($missingUrls)) {
            Log::info('DiscoverNavigationUrls: No missing URLs found. Sitemap has full coverage.', [
                'customer_id' => $this->customer->id,
                'nav_urls_found' => count($discoveredUrls),
            ]);
            return;
        }

        Log::warning('DiscoverNavigationUrls: Sitemap coverage gap detected', [
            'customer_id' => $this->customer->id,
            'missing_urls' => $missingUrls,
            'sitemap_urls' => count($this->alreadyCrawledUrls),
            'nav_urls_discovered' => count($discoveredUrls),
            'missing_count' => count($missingUrls),
        ]);

        // Dispatch CrawlPage jobs for missing URLs
        foreach ($missingUrls as $url) {
            Log::info("DiscoverNavigationUrls: Dispatching CrawlPage for missing URL: {$url}");
            CrawlPage::dispatch($this->user, $url, $this->customer->id, [
                'source' => 'navigation_discovery',
                'sitemap_gap' => true,
            ]);
        }

        Log::info('DiscoverNavigationUrls: Dispatched crawl jobs for missing navigation URLs', [
            'customer_id' => $this->customer->id,
            'count' => count($missingUrls),
        ]);
    }

    private function extractNavigationLinks(string $html, string $websiteUrl): array
    {
        $parsedBase = parse_url($websiteUrl);
        $baseHost = $parsedBase['host'] ?? '';
        $baseScheme = $parsedBase['scheme'] ?? 'https';
        $baseUrl = $baseScheme . '://' . $baseHost;

        $crawler = new Crawler($html, $websiteUrl);
        $urls = [];

        // Extract links from nav, header, footer and main content area
        $selectors = [
            'nav a[href]',
            'header a[href]',
            'footer a[href]',
            '[role="navigation"] a[href]',
            '.nav a[href]',
            '.menu a[href]',
            '.navigation a[href]',
            'main a[href]',
            '#content a[href]',
            '.content a[href]',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$urls, $baseHost, $baseUrl) {
                    $href = $node->attr('href');
                    if (empty($href)) {
                        return;
                    }

                    $resolved = $this->resolveUrl($href, $baseUrl);
                    if ($resolved && $this->isInternalUrl($resolved, $baseHost) && $this->isCrawlableUrl($resolved)) {
                        $urls[] = $resolved;
                    }
                });
            } catch (\Exception $e) {
                // Selector may not match — that's fine
            }
        }

        return array_unique($urls);
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Skip anchors, javascript, mailto, tel
        if (str_starts_with($href, '#') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'tel:')) {
            return null;
        }

        // Absolute URL
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return strtok($href, '#'); // Strip fragment
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return 'https:' . strtok($href, '#');
        }

        // Relative URL
        return rtrim($baseUrl, '/') . '/' . ltrim(strtok($href, '#'), '/');
    }

    private function isInternalUrl(string $url, string $baseHost): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Match with and without www
        $normalizedBase = preg_replace('/^www\./', '', strtolower($baseHost));
        $normalizedHost = preg_replace('/^www\./', '', strtolower($host));

        return $normalizedBase === $normalizedHost;
    }

    private function isCrawlableUrl(string $url): bool
    {
        $skipPatterns = [
            '/login', '/register', '/password', '/logout', '/admin',
            '/auth/', '/verify-email', '/forgot-password', '/reset-password',
            '/cart', '/checkout', '/account', '/wp-admin', '/wp-login',
        ];

        $skipExtensions = ['.pdf', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.css', '.js', '.zip', '.mp4', '.mp3'];

        $lowerUrl = strtolower($url);

        foreach ($skipPatterns as $pattern) {
            if (str_contains($lowerUrl, $pattern)) {
                return false;
            }
        }

        foreach ($skipExtensions as $ext) {
            if (str_ends_with($lowerUrl, $ext)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('/^https?:\/\/(www\.)?/', '', $url);
        $url = rtrim($url, '/');
        return $url;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DiscoverNavigationUrls failed', [
            'customer_id' => $this->customer->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
