<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\LinkedInAds\PerformanceService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchLinkedInAdsPerformanceData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 20, 30, 40, 50];

    protected Campaign $campaign;

    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
    }

    public function handle(): void
    {
        if (empty($this->campaign->linkedin_campaign_id)) {
            Log::warning("Campaign {$this->campaign->id} does not have a LinkedIn Campaign ID. Skipping.");
            return;
        }

        $lock = Cache::lock('fetch-linkedin-performance-'.$this->campaign->linkedin_campaign_id, 600);
        $circuitBreaker = new CircuitBreakerService('LinkedInAdsAPI');
        $customer = $this->campaign->customer;

        if (!$customer || !$customer->linkedin_ads_account_id) {
            Log::warning("Campaign {$this->campaign->id}: No LinkedIn Ads credentials. Skipping.");
            return;
        }

        if ($lock->get() && $circuitBreaker->isAvailable()) {
            try {
                Log::info("Starting FetchLinkedInAdsPerformanceData for campaign ID: {$this->campaign->id}");

                $performanceService = new PerformanceService($customer);
                $rowsStored = $performanceService->syncPerformance($this->campaign, 3);

                $circuitBreaker->recordSuccess();
                Log::info("Successfully synced LinkedIn Ads performance data for campaign ID: {$this->campaign->id}", [
                    'rows_stored' => $rowsStored,
                ]);

                // Generate recommendations from stored data
                $strategy = $this->campaign->strategies()->latest()->first();
                if ($strategy && $rowsStored > 0) {
                    $performanceData = LinkedInAdsPerformanceData::where('campaign_id', $this->campaign->id)
                        ->where('date', '>=', now()->subDays(3)->toDateString())
                        ->get()
                        ->toArray();

                    if (!empty($performanceData)) {
                        $recommendationService = new RecommendationGenerationService();
                        $recommendations = ($recommendationService)($performanceData, [
                            'campaignId' => $this->campaign->linkedin_campaign_id,
                            'dailyBudget' => $strategy->budget,
                            'platform' => 'linkedin',
                        ]);

                        foreach ($recommendations as $rec) {
                            Recommendation::create([
                                'campaign_id' => $this->campaign->id,
                                'type' => $rec['type'],
                                'target_entity' => $rec['target_entity'],
                                'parameters' => $rec['parameters'],
                                'rationale' => $rec['rationale'],
                                'status' => 'pending',
                                'platform' => 'linkedin',
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $circuitBreaker->recordFailure();
                Log::error("Error in FetchLinkedInAdsPerformanceData for campaign {$this->campaign->id}: " . $e->getMessage());
                $this->release(60);
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not acquire lock or circuit breaker open for LinkedIn campaign {$this->campaign->id}");
            $this->release(60);
        }
    }
}
