<?php

namespace App\Jobs;

// importing the relvant classes
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Sitemap;

class CrawlSitemap implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The user instance.
     * In Go, this would be a field on our Job struct, e.g., `User *models.User`.
     * @var \App\Models\User
     */
    public $user;

    /**
     * The URL of the sitemap to crawl.
     * @var string
     */
    public $sitemapUrl;
    public $customerId;

    /**
     * Create a new job instance.
     * This is the constructor, equivalent to `NewCrawlSitemapJob(user, url)` in Go.
     *
     * @param User $user
     * @param string $sitemapUrl
     * @param int|null $customerId
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
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ])->get($this->sitemapUrl);

            if ($response->failed()) {
                Log::error("CrawlSitemap: Failed to fetch sitemap: {$this->sitemapUrl}. Status: " . $response->status());
                return;
            }

            Log::info("CrawlSitemap: Successfully fetched sitemap with status " . $response->status());
            $content = $response->body();

            if (empty($content)) {
                Log::warning("CrawlSitemap: Sitemap content is empty for URL: {$this->sitemapUrl}");
                return;
            }

            // Use PHP's built-in SimpleXMLElement for robust XML parsing.
            $xml = new \SimpleXMLElement($content);

            // Check if it's a sitemap index file
            if (isset($xml->sitemap)) {
                Log::info("CrawlSitemap: Detected sitemap index.");
                Log::info("CrawlSitemap: Found " . count($xml->sitemap) . " sitemaps in index.");
                foreach ($xml->sitemap as $sitemap) {
                    $url = (string)$sitemap->loc;
                    Log::info("CrawlSitemap: Dispatching new CrawlSitemap job for: {$url}");
                    self::dispatch($this->user, $url, $this->customerId);
                }
            }
            // Check if it's a regular sitemap file
            elseif (isset($xml->url)) {
                Log::info("CrawlSitemap: Detected regular sitemap.");
                Log::info("CrawlSitemap: Found " . count($xml->url) . " URLs in sitemap.");
                foreach ($xml->url as $url) {
                    $loc = (string)$url->loc;
                    Log::info("CrawlSitemap: Dispatching CrawlPage job for URL: {$loc}");
                    CrawlPage::dispatch($this->user, $loc, $this->customerId);
                }
            } else {
                Log::warning("CrawlSitemap: Could not find <sitemap> or <url> tags in the sitemap: {$this->sitemapUrl}");
            }

            Log::info("CrawlSitemap: Finished processing job for URL: {$this->sitemapUrl}");

        } catch (\Exception $e) {
            Log::error("CrawlSitemap: Error processing sitemap {$this->sitemapUrl}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
