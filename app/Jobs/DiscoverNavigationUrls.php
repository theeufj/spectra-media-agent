<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class DiscoverNavigationUrls implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

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
            $html = app(\App\Services\Crawling\WebsiteRenderer::class)->html($websiteUrl);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('DiscoverNavigationUrls: Renderer failed, trying safe HTTP fallback', [
                'error' => $e->getMessage(),
            ]);
            try {
                $response = app(\App\Services\Crawling\PublicWebsiteFetcher::class)->get($websiteUrl);
                $html = $response->successful() ? $response->body() : '';
            } catch (\Throwable $e2) {
                report($e2);
                Log::error('DiscoverNavigationUrls: All fetch methods failed', [
                    'error' => $e2->getMessage(),
                ]);

                $this->concludeWithoutDiscovery('We couldn\'t reach your website — it may have been down, or it may be blocking automated visitors.');

                return;
            }
        }

        if (empty($html)) {
            Log::warning('DiscoverNavigationUrls: Empty HTML from homepage');

            $this->concludeWithoutDiscovery('Your website loaded but returned an empty page, so there was nothing we could read.');

            return;
        }

        $discoveredUrls = $this->extractNavigationLinks($html, $websiteUrl);

        // A bot-protection service (Cloudflare, Signal Sciences, ...) doesn't
        // fail the fetch — it serves a shell document with no readable text
        // and no navigation. Without this check that shell sails through as
        // "sitemap has full coverage" and the customer eventually gets a
        // misleading "couldn't extract enough about your brand" email.
        if (empty($discoveredUrls) && self::looksBlocked($html)) {
            $this->concludeWithoutDiscovery(
                'Your website appears to be blocking automated visitors — usually a firewall '
                .'or bot-protection service sitting in front of the site. If your web provider '
                .'can allow our scanner through, reply to this email and we\'ll rerun the scan.'
            );

            return;
        }

        if (empty($discoveredUrls) && mb_strlen(self::readableText($html)) < 200) {
            $this->concludeWithoutDiscovery(
                'Your website loaded, but our scanner could not read enough page content. '
                .'Sites that load their content with JavaScript need browser rendering. '
                .'You can upload a text document about your business to continue, or contact us to rerun the scan.'
            );

            return;
        }

        // Also include already-crawled CustomerPage URLs in the comparison
        $existingPageUrls = CustomerPage::where('customer_id', $this->customer->id)
            ->pluck('url')
            ->toArray();

        $allKnownUrls = array_unique(array_merge(
            array_map(fn ($url) => $this->normalizeUrl($url), $this->alreadyCrawledUrls),
            array_map(fn ($url) => $this->normalizeUrl($url), $existingPageUrls),
        ));

        // Find URLs in the navigation that weren't in the sitemap
        $missingUrls = [];
        foreach ($discoveredUrls as $url) {
            $normalized = $this->normalizeUrl($url);
            if (! in_array($normalized, $allKnownUrls)) {
                $missingUrls[] = $url;
            }
        }

        if (empty($missingUrls)) {
            Log::info('DiscoverNavigationUrls: No missing URLs found. Sitemap has full coverage.', [
                'customer_id' => $this->customer->id,
                'nav_urls_found' => count($discoveredUrls),
            ]);
            ExtractBrandGuidelines::dispatch($this->customer);

            return;
        }

        Log::warning('DiscoverNavigationUrls: Sitemap coverage gap detected', [
            'customer_id' => $this->customer->id,
            'missing_urls' => $missingUrls,
            'sitemap_urls' => count($this->alreadyCrawledUrls),
            'nav_urls_discovered' => count($discoveredUrls),
            'missing_count' => count($missingUrls),
        ]);

        $jobs = array_map(fn ($url) => new CrawlPage($this->user, $url, $this->customer->id, [
            'source' => 'navigation_discovery',
            'sitemap_gap' => true,
        ]), $missingUrls);

        $customer = $this->customer;

        Bus::batch($jobs)
            ->name("Discover Navigation: {$websiteUrl}")
            ->then(function (Batch $batch) use ($customer) {
                Log::info('DiscoverNavigationUrls batch completed. Dispatching brand guideline extraction.', [
                    'customer_id' => $customer->id,
                    'pages_crawled' => $batch->totalJobs,
                ]);
                ExtractBrandGuidelines::dispatch($customer);
            })
            ->allowFailures()
            ->dispatch();

        Log::info('DiscoverNavigationUrls: Dispatched batch for missing navigation URLs', [
            'customer_id' => $this->customer->id,
            'count' => count($missingUrls),
        ]);
    }

    /**
     * The homepage could not be read. This job sits in the middle of the
     * onboarding chain, so simply returning here used to sever it in both of
     * its roles: as the gap-filler after a successful sitemap crawl (where
     * pages already exist and brand extraction should proceed anyway), and as
     * the no-sitemap fallback (where nothing was crawled and the user is
     * still watching "we're scanning your website now").
     */
    private function concludeWithoutDiscovery(string $reason): void
    {
        $hasPages = CustomerPage::where('customer_id', $this->customer->id)->exists();

        if ($hasPages) {
            Log::info('DiscoverNavigationUrls: homepage unreadable but pages exist — continuing to brand extraction', [
                'customer_id' => $this->customer->id,
            ]);
            ExtractBrandGuidelines::dispatch($this->customer);

            return;
        }

        Log::warning('DiscoverNavigationUrls: scan ended with no pages — notifying users', [
            'customer_id' => $this->customer->id,
        ]);

        foreach ($this->customer->users as $user) {
            $user->notify(new \App\Notifications\SiteScanFailed($this->customer, $reason));
        }
    }

    /**
     * Sparse HTML alone is not evidence of blocking: an unrendered React or
     * Inertia page has the same shape. Require an actual challenge marker.
     */
    public static function looksBlocked(string $html): bool
    {
        if (mb_strlen(self::readableText($html)) >= 2000) {
            return false;
        }

        return (bool) preg_match(
            '#/cdn-cgi/challenge-platform/|checking your browser before accessing|enable javascript and cookies to continue|request blocked|local_rate_limited#i',
            $html,
        );
    }

    private static function readableText(string $html): string
    {
        $crawler = new Crawler($html);
        $crawler->filter('script, style, noscript, template, svg, head')->each(function (Crawler $node) {
            foreach ($node as $element) {
                $element->parentNode?->removeChild($element);
            }
        });

        return trim(preg_replace('/\s+/u', ' ', $crawler->text('')) ?? '');
    }

    private function extractNavigationLinks(string $html, string $websiteUrl): array
    {
        $parsedBase = parse_url($websiteUrl);
        $baseHost = $parsedBase['host'] ?? '';
        $baseScheme = $parsedBase['scheme'] ?? 'https';
        $baseUrl = $baseScheme.'://'.$baseHost;

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
            } catch (\Throwable $e) {
                report($e);
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
            return 'https:'.strtok($href, '#');
        }

        // Relative URL
        return rtrim($baseUrl, '/').'/'.ltrim(strtok($href, '#'), '/');
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

        // An unexpected crash is a dead-end just like an unreadable homepage:
        // continue the chain if pages exist, tell the user if they don't.
        try {
            $this->concludeWithoutDiscovery('Something went wrong on our side while reading your website.');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
