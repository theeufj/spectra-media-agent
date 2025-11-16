<?php

namespace App\Jobs;

use App\Models\User;
use Goutte\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrawlCompetitorWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $url;
    protected $customerId;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, string $url, int $customerId)
    {
        $this->user = $user;
        $this->url = $url;
        $this->customerId = $customerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting to crawl competitor website: {$this->url} for customer {$this->customerId}");
            $sitemapUrl = $this->findSitemapUrl();

            if ($sitemapUrl) {
                Log::info("Found sitemap for competitor: {$sitemapUrl}");
                CrawlSitemap::dispatch($this->user, $sitemapUrl, $this->customerId);
            } else {
                Log::warning("No sitemap found for competitor: {$this->url}. Crawling single page as fallback.");
                CrawlPage::dispatch($this->user, $this->url, $this->customerId);
            }
        } catch (\Exception $e) {
            Log::error("Error crawling competitor website {$this->url}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Find the sitemap URL from robots.txt or common locations.
     */
    private function findSitemapUrl(): ?string
    {
        // 1. Check robots.txt
        try {
            $robotsUrl = rtrim($this->url, '/') . '/robots.txt';
            $response = Http::get($robotsUrl);

            if ($response->successful()) {
                preg_match('/Sitemap: (.*)/i', $response->body(), $matches);
                if (isset($matches[1])) {
                    return trim($matches[1]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Could not fetch or parse robots.txt for {$this->url}: " . $e->getMessage());
        }

        // 2. Fallback to common location
        try {
            $sitemapUrl = rtrim($this->url, '/') . '/sitemap.xml';
            $response = Http::head($sitemapUrl);

            if ($response->successful()) {
                return $sitemapUrl;
            }
        } catch (\Exception $e) {
            Log::warning("Could not find sitemap at common location for {$this->url}: " . $e->getMessage());
        }

        return null;
    }
}
