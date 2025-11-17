<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\GTM\GTMDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * ScrapeCustomerWebsite Job
 * 
 * Scrapes a customer's website and detects GTM installation.
 * 
 * This job is typically dispatched when:
 * 1. A new customer is created with a website URL
 * 2. A customer's website URL is updated
 * 3. Manual re-scan is requested via GTMSetupController::rescan()
 * 
 * @example
 * // Dispatch from CustomerController when customer is created/updated
 * if ($customer->website) {
 *     dispatch(new ScrapeCustomerWebsite($customer));
 * }
 */
class ScrapeCustomerWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Customer
     */
    protected $customer;

    /**
     * Maximum time to wait for response (in seconds)
     */
    protected int $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param Customer $customer
     */
    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    /**
     * Execute the job.
     */
    public function handle(GTMDetectionService $gtmDetectionService): void
    {
        try {
            Log::info('Starting website scrape for customer', [
                'customer_id' => $this->customer->id,
                'website' => $this->customer->website,
            ]);

            if (!$this->customer->website) {
                Log::warning('Customer has no website URL', [
                    'customer_id' => $this->customer->id,
                ]);
                return;
            }

            // Fetch the HTML content of the customer's website
            $htmlContent = $this->fetchWebsiteContent($this->customer->website);

            if (!$htmlContent) {
                Log::warning('Failed to fetch website content', [
                    'customer_id' => $this->customer->id,
                    'website' => $this->customer->website,
                ]);
                return;
            }

            // Detect GTM container ID
            $gtmMetadata = $gtmDetectionService->getDetectionMetadata($htmlContent);

            // Update customer with GTM detection results
            $this->customer->update([
                'gtm_detected' => $gtmMetadata['detected'],
                'gtm_container_id' => $gtmMetadata['container_id'],
                'gtm_detected_at' => $gtmMetadata['detected_at'],
            ]);

            Log::info('Website scrape completed with GTM detection', [
                'customer_id' => $this->customer->id,
                'gtm_detected' => $gtmMetadata['detected'],
                'gtm_container_id' => $gtmMetadata['container_id'],
                'script_count' => $gtmMetadata['script_count'],
            ]);

            // If GTM detected, customer can follow Path A
            // If not detected, customer will need to follow Path B (create new container)
            if ($gtmMetadata['detected']) {
                Log::info('GTM detected - Customer eligible for Path A (existing GTM)', [
                    'customer_id' => $this->customer->id,
                    'container_id' => $gtmMetadata['container_id'],
                ]);
            } else {
                Log::info('GTM not detected - Customer will use Path B (new GTM setup)', [
                    'customer_id' => $this->customer->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error during website scrape and GTM detection', [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Fetch HTML content from the customer's website.
     *
     * @param string $url The website URL to scrape
     * @return string|null The HTML content or null if fetch fails
     */
    protected function fetchWebsiteContent(string $url): ?string
    {
        try {
            // Ensure URL has protocol
            $url = $this->ensureProtocol($url);

            // Try using Browsershot for JavaScript-heavy sites
            try {
                $htmlContent = Browsershot::url($url)
                    ->timeout($this->timeout)
                    ->bodyHtml();

                return $htmlContent;
            } catch (\Exception $browserShotException) {
                Log::debug('Browsershot failed, falling back to HTTP', [
                    'url' => $url,
                    'error' => $browserShotException->getMessage(),
                ]);

                // Fallback to simple HTTP request
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                    ->timeout($this->timeout)
                    ->get($url);

                if ($response->successful()) {
                    return $response->body();
                }
            }
        } catch (\Exception $e) {
            Log::error('Error fetching website content', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Ensure URL has a protocol.
     *
     * @param string $url The URL to check
     * @return string URL with protocol
     */
    private function ensureProtocol(string $url): string
    {
        if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
            return 'https://' . $url;
        }

        return $url;
    }
}
