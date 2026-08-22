<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Sitemap\Sitemap;

// DiscoverNavigationUrls is dispatched in the batch callback to find nav links missing from sitemap

class CrawlSitemap implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The user instance.
     * In Go, this would be a field on our Job struct, e.g., `User *models.User`.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * The URL of the sitemap to crawl.
     *
     * @var string
     */
    public $sitemapUrl;

    public $customerId;

    /**
     * Create a new job instance.
     * This is the constructor, equivalent to `NewCrawlSitemapJob(user, url)` in Go.
     */
    public function __construct(User $user, string $sitemapUrl, ?int $customerId = null)
    {
        $this->user = $user;
        $this->sitemapUrl = $sitemapUrl;
        $this->customerId = $customerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting CrawlSitemap job for URL: {$this->sitemapUrl}");

        try {
            $response = Http::timeout(30)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            ])->get($this->sitemapUrl);

            if ($response->failed()) {
                Log::error("CrawlSitemap: Failed to fetch sitemap: {$this->sitemapUrl}. Status: ".$response->status());

                return;
            }

            Log::info('CrawlSitemap: Successfully fetched sitemap with status '.$response->status());
            $content = $response->body();

            // Check for Gzip compression
            if (str_ends_with($this->sitemapUrl, '.gz') || (substr($content, 0, 2) === "\x1f\x8b")) {
                Log::info('CrawlSitemap: Detected Gzip compression. Decompressing...');
                $decoded = @gzdecode($content);
                if ($decoded === false) {
                    Log::error("CrawlSitemap: Failed to decompress Gzip content for URL: {$this->sitemapUrl}");

                    return;
                }
                $content = $decoded;
            }

            if (empty($content)) {
                Log::warning("CrawlSitemap: Sitemap content is empty for URL: {$this->sitemapUrl}");

                return;
            }

            // Validate that we actually received XML content, not HTML
            $trimmedContent = trim($content);
            if (! str_starts_with($trimmedContent, '<?xml') && ! str_starts_with($trimmedContent, '<urlset') && ! str_starts_with($trimmedContent, '<sitemapindex')) {
                // Check if it looks like HTML
                if (str_contains(strtolower($trimmedContent), '<!doctype html') || str_contains(strtolower($trimmedContent), '<html')) {
                    Log::error("CrawlSitemap: Received HTML instead of XML for URL: {$this->sitemapUrl}. The site may be returning a login page or error page.");

                    return;
                }
                Log::warning("CrawlSitemap: Content doesn't appear to be valid XML for URL: {$this->sitemapUrl}");
            }

            // Check Content-Type header
            $contentType = $response->header('Content-Type') ?? '';
            if (! empty($contentType) && ! str_contains($contentType, 'xml') && ! str_contains($contentType, 'text/plain')) {
                Log::warning("CrawlSitemap: Unexpected Content-Type '{$contentType}' for URL: {$this->sitemapUrl}");
            }

            // Use PHP's built-in SimpleXMLElement for robust XML parsing.
            // Suppress errors and handle them gracefully
            libxml_use_internal_errors(true);
            try {
                $xml = new \SimpleXMLElement($content);
            } catch (\Exception $xmlException) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $errorMessages = array_map(fn ($e) => trim($e->message), $errors);
                Log::error("CrawlSitemap: Failed to parse XML for URL: {$this->sitemapUrl}", [
                    'xml_errors' => $errorMessages,
                    'content_preview' => substr($trimmedContent, 0, 500),
                ]);

                return;
            }
            libxml_use_internal_errors(false);

            // A large store's sitemap index fans out into thousands of product
            // pages — one clothing site produced 15,326 queued jobs, each a
            // headless browser render and a paid embedding call, and at a
            // polite crawl rate that is close to a day of work.
            //
            // A campaign brief does not improve for having read the nine
            // hundredth dress. Stop once enough of the site is understood.
            if ($this->customerId && $this->budgetRemaining() <= 0) {
                Log::info('CrawlSitemap: page budget spent, not descending further', [
                    'customer_id' => $this->customerId,
                    'sitemap' => $this->sitemapUrl,
                ]);

                return;
            }

            // Check if it's a sitemap index file
            if (isset($xml->sitemap)) {
                Log::info('CrawlSitemap: Detected sitemap index.');
                Log::info('CrawlSitemap: Found '.count($xml->sitemap).' sitemaps in index.');
                foreach ($xml->sitemap as $sitemap) {
                    $url = (string) $sitemap->loc;
                    Log::info("CrawlSitemap: Dispatching new CrawlSitemap job for: {$url}");
                    self::dispatch($this->user, $url, $this->customerId);
                }
            }
            // Check if it's a regular sitemap file
            elseif (isset($xml->url)) {
                Log::info('CrawlSitemap: Detected regular sitemap.');
                Log::info('CrawlSitemap: Found '.count($xml->url).' URLs in sitemap.');

                // Register namespaces
                $namespaces = $xml->getNamespaces(true);

                // Collect all CrawlPage jobs
                $jobs = [];

                // URLs to skip (auth pages, admin pages, etc.)
                $skipPatterns = [
                    '/login',
                    '/register',
                    '/password',
                    '/logout',
                    '/admin',
                    '/auth/',
                    '/verify-email',
                    '/forgot-password',
                    '/reset-password',
                ];

                // Query shapes that re-serve a page we already crawled. A
                // storefront exposes every product once per colour and size —
                // ?variant= alone can multiply one product into dozens of
                // identical pages, each costing a render and an embedding.
                $duplicateParams = ['?variant=', '&variant=', '?page=', '&page=', '?sort_by=', '&sort_by=', '?filter'];

                foreach ($xml->url as $url) {
                    $loc = (string) $url->loc;

                    // Skip auth and admin pages
                    $shouldSkip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (str_contains(strtolower($loc), $pattern)) {
                            Log::info("CrawlSitemap: Skipping auth/admin page: {$loc}");
                            $shouldSkip = true;
                            break;
                        }
                    }

                    foreach ($duplicateParams as $param) {
                        if (! $shouldSkip && str_contains(strtolower($loc), $param)) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        continue;
                    }

                    $metadata = [];

                    // Extract Video Metadata
                    if (isset($namespaces['video'])) {
                        $video = $url->children($namespaces['video']);
                        if (isset($video->video)) {
                            $metadata['video'] = [
                                'title' => (string) $video->video->title,
                                'description' => (string) $video->video->description,
                                'thumbnail_loc' => (string) $video->video->thumbnail_loc,
                            ];
                        }
                    }

                    // Extract News Metadata
                    if (isset($namespaces['news'])) {
                        $news = $url->children($namespaces['news']);
                        if (isset($news->news)) {
                            $metadata['news'] = [
                                'publication' => (string) $news->news->publication->name,
                                'publication_date' => (string) $news->news->publication_date,
                                'title' => (string) $news->news->title,
                            ];
                        }
                    }

                    // Extract Image Metadata
                    if (isset($namespaces['image'])) {
                        $image = $url->children($namespaces['image']);
                        if (isset($image->image)) {
                            $metadata['image'] = [
                                'loc' => (string) $image->image->loc,
                                'caption' => (string) $image->image->caption,
                            ];
                        }
                    }

                    Log::info("CrawlSitemap: Adding CrawlPage job for URL: {$loc}");
                    $jobs[] = new CrawlPage($this->user, $loc, $this->customerId, $metadata);
                }

                // Take only as much of the budget as is still unclaimed. One
                // Shopify product sitemap holds a thousand URLs; without this a
                // single file spends a 400-page budget more than twice over
                // before any other sitemap gets a look in.
                if ($this->customerId && ! empty($jobs)) {
                    $jobs = $this->prioritise($jobs);
                    $granted = $this->claimBudget(count($jobs));

                    if ($granted < count($jobs)) {
                        Log::info('CrawlSitemap: trimming to remaining page budget', [
                            'customer_id' => $this->customerId,
                            'sitemap' => $this->sitemapUrl,
                            'found' => count($jobs),
                            'crawling' => $granted,
                        ]);

                        $jobs = array_slice($jobs, 0, $granted);
                    }
                }

                // Dispatch as a batch with completion callback
                if (! empty($jobs)) {
                    $customer = Customer::find($this->customerId);
                    $user = $this->user;
                    $sitemapUrls = array_map(fn ($job) => $job->url, $jobs);

                    $batch = Bus::batch($jobs)
                        ->name("Crawl Sitemap: {$this->sitemapUrl}")
                        ->then(function (Batch $batch) use ($customer, $user, $sitemapUrls) {
                            if ($customer) {
                                Log::info('CrawlSitemap batch completed. Discovering navigation URLs.', [
                                    'customer_id' => $customer->id,
                                    'batch_id' => $batch->id,
                                    'total_jobs' => $batch->totalJobs,
                                    'processed_jobs' => $batch->processedJobs(),
                                ]);

                                // DiscoverNavigationUrls will dispatch ExtractBrandGuidelines
                                // (and send the completion email) once all pages are crawled.
                                DiscoverNavigationUrls::dispatch($customer, $user, $sitemapUrls);
                            }
                        })
                        ->catch(function (Batch $batch, \Throwable $e) {
                            Log::error('CrawlSitemap batch failed.', [
                                'batch_id' => $batch->id,
                                'error' => $e->getMessage(),
                            ]);
                        })
                        ->allowFailures()
                        ->dispatch();

                    Log::info("CrawlSitemap: Dispatched batch of {$batch->totalJobs} jobs.", [
                        'batch_id' => $batch->id,
                    ]);
                }
            } else {
                Log::warning("CrawlSitemap: Could not find <sitemap> or <url> tags in the sitemap: {$this->sitemapUrl}");
            }

            Log::info("CrawlSitemap: Finished processing job for URL: {$this->sitemapUrl}");

        } catch (\Exception $e) {
            Log::error("CrawlSitemap: Error processing sitemap {$this->sitemapUrl}: ".$e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CrawlSitemap failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Spend the crawl budget on the pages that describe the business.
     *
     * Not every page is worth the same. A storefront's "about", "shipping" and
     * collection pages say what the company is, who it serves and what it
     * stocks. Its fifteen thousand product pages say the same three sentences
     * with a different noun — informative for the first twenty, noise after
     * that, and each one costs a headless render and a paid embedding.
     *
     * Start2finish is the case in point: 15,326 product URLs against about
     * thirty pages that actually describe the business. Left unordered the
     * budget fills with dresses and the "about" page never gets crawled.
     *
     * Product pages are therefore capped at a share of the budget rather than
     * excluded — some are needed to know what is actually sold — and
     * everything else is taken first.
     *
     * @param  list<CrawlPage>  $jobs
     * @return list<CrawlPage>
     */
    private function prioritise(array $jobs): array
    {
        $describes = [];
        $products = [];

        foreach ($jobs as $job) {
            $path = strtolower((string) parse_url($job->url, PHP_URL_PATH));

            if (str_contains($path, '/products/')) {
                $products[] = $job;
            } else {
                $describes[] = $job;
            }
        }

        if ($products === []) {
            return $describes;
        }

        $productShare = max(1, (int) round(
            (int) config('crawl.max_pages_per_site', 400) * (float) config('crawl.product_page_share', 0.25)
        ));

        if (count($products) > $productShare) {
            Log::info('CrawlSitemap: capping product pages in favour of pages that describe the business', [
                'customer_id' => $this->customerId,
                'products_found' => count($products),
                'products_taken' => $productShare,
                'other_pages' => count($describes),
            ]);

            $products = array_slice($products, 0, $productShare);
        }

        // Descriptive pages first, so if the budget runs out mid-list it is the
        // products that are dropped.
        return [...$describes, ...$products];
    }

    /**
     * How many more pages this customer's crawl may dispatch.
     *
     * COUNTED ON DISPATCH, NOT ON STORED PAGES. A sitemap index fans out into
     * one CrawlSitemap job per sub-sitemap and they run concurrently, so a
     * budget measured against stored pages is read by all of them before any
     * page has been crawled — every one sees the budget as untouched and
     * dispatches its full thousand. That is how one store queued 15,326 jobs.
     *
     * Cache::increment is atomic, so the reservation below cannot be
     * double-spent no matter how many sub-sitemaps run at once. The counter
     * expires so a later re-crawl starts with a fresh budget rather than
     * inheriting a spent one.
     */
    private function budgetRemaining(): int
    {
        $budget = (int) config('crawl.max_pages_per_site', 400);

        if ($budget <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $budget - (int) Cache::get($this->budgetKey(), 0));
    }

    /**
     * Claim part of the budget. Returns how much was actually granted, which
     * may be less than asked for and may be zero.
     */
    private function claimBudget(int $wanted): int
    {
        if ((int) config('crawl.max_pages_per_site', 400) <= 0) {
            return $wanted;
        }

        $key = $this->budgetKey();

        Cache::add($key, 0, now()->addHours(6));

        $granted = min($wanted, $this->budgetRemaining());

        if ($granted > 0) {
            Cache::increment($key, $granted);
        }

        return $granted;
    }

    private function budgetKey(): string
    {
        return "crawl:budget:{$this->customerId}";
    }
}
