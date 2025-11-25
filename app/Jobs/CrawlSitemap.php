<?php

namespace App\Jobs;

// importing the relvant classes
use App\Mail\SitemapCrawlCompleted;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

            // Check for Gzip compression
            if (str_ends_with($this->sitemapUrl, '.gz') || (substr($content, 0, 2) === "\x1f\x8b")) {
                Log::info("CrawlSitemap: Detected Gzip compression. Decompressing...");
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
                
                // Register namespaces
                $namespaces = $xml->getNamespaces(true);

                // Collect all CrawlPage jobs
                $jobs = [];
                foreach ($xml->url as $url) {
                    $loc = (string)$url->loc;
                    $metadata = [];

                    // Extract Video Metadata
                    if (isset($namespaces['video'])) {
                        $video = $url->children($namespaces['video']);
                        if (isset($video->video)) {
                            $metadata['video'] = [
                                'title' => (string)$video->video->title,
                                'description' => (string)$video->video->description,
                                'thumbnail_loc' => (string)$video->video->thumbnail_loc,
                            ];
                        }
                    }

                    // Extract News Metadata
                    if (isset($namespaces['news'])) {
                        $news = $url->children($namespaces['news']);
                        if (isset($news->news)) {
                            $metadata['news'] = [
                                'publication' => (string)$news->news->publication->name,
                                'publication_date' => (string)$news->news->publication_date,
                                'title' => (string)$news->news->title,
                            ];
                        }
                    }

                    // Extract Image Metadata
                    if (isset($namespaces['image'])) {
                        $image = $url->children($namespaces['image']);
                        if (isset($image->image)) {
                            $metadata['image'] = [
                                'loc' => (string)$image->image->loc,
                                'caption' => (string)$image->image->caption,
                            ];
                        }
                    }

                    Log::info("CrawlSitemap: Adding CrawlPage job for URL: {$loc}");
                    $jobs[] = new CrawlPage($this->user, $loc, $this->customerId, $metadata);
                }
                
                // Dispatch as a batch with completion callback
                if (!empty($jobs)) {
                    $customer = Customer::find($this->customerId);
                    $sitemapUrl = $this->sitemapUrl;
                    $user = $this->user;
                    
                    $batch = Bus::batch($jobs)
                        ->name("Crawl Sitemap: {$this->sitemapUrl}")
                        ->then(function (Batch $batch) use ($customer, $sitemapUrl, $user) {
                            if ($customer) {
                                Log::info("CrawlSitemap batch completed. Dispatching brand extraction.", [
                                    'customer_id' => $customer->id,
                                    'batch_id' => $batch->id,
                                    'total_jobs' => $batch->totalJobs,
                                    'processed_jobs' => $batch->processedJobs(),
                                ]);
                                
                                // Dispatch brand guideline extraction now that knowledge base is populated
                                ExtractBrandGuidelines::dispatch($customer);
                                
                                // Send completion email to user
                                Mail::to($user->email)->send(
                                    new SitemapCrawlCompleted(
                                        $sitemapUrl,
                                        $batch->totalJobs,
                                        $user->name
                                    )
                                );
                                
                                Log::info("CrawlSitemap completion email sent to {$user->email}");
                            }
                        })
                        ->catch(function (Batch $batch, \Throwable $e) {
                            Log::error("CrawlSitemap batch failed.", [
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
            Log::error("CrawlSitemap: Error processing sitemap {$this->sitemapUrl}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
