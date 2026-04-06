<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\MicrosoftAds\PerformanceService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchMicrosoftAdsPerformanceData implements ShouldQueue
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
        if (empty($this->campaign->microsoft_ads_campaign_id)) {
            Log::warning("Campaign {$this->campaign->id} does not have a Microsoft Ads Campaign ID. Skipping.");
            return;
        }

        $lock = Cache::lock('fetch-microsoft-performance-'.$this->campaign->microsoft_ads_campaign_id, 600);
        $circuitBreaker = new CircuitBreakerService('MicrosoftAdsAPI');
        $customer = $this->campaign->customer;

        if (!$customer || !config('microsoftads.refresh_token')) {
            Log::warning("Campaign {$this->campaign->id}: No Microsoft Ads management credentials configured. Skipping.");
            return;
        }

        if ($lock->get() && $circuitBreaker->isAvailable()) {
            try {
                Log::info("Starting FetchMicrosoftAdsPerformanceData for campaign ID: {$this->campaign->id}");

                $performanceService = new PerformanceService($customer);
                $result = $performanceService->syncPerformance($this->campaign, 3);

                if (is_array($result) && isset($result['error'])) {
                    $circuitBreaker->recordFailure();
                    Log::warning("Microsoft Ads performance sync returned error", [
                        'campaign_id' => $this->campaign->id,
                        'error' => $result['error'],
                    ]);
                    $this->release(60);
                    return;
                }

                $circuitBreaker->recordSuccess();
                Log::info("Successfully synced Microsoft Ads performance data for campaign ID: {$this->campaign->id}");

                // Generate recommendations from stored data
                $strategy = $this->campaign->strategies()->latest()->first();
                if ($strategy) {
                    $performanceData = MicrosoftAdsPerformanceData::where('campaign_id', $this->campaign->id)
                        ->where('date', '>=', now()->subDays(3)->toDateString())
                        ->get()
                        ->toArray();

                    if (!empty($performanceData)) {
                        $recommendationService = new RecommendationGenerationService();
                        $recommendations = ($recommendationService)($performanceData, [
                            'campaignId' => $this->campaign->microsoft_ads_campaign_id,
                            'dailyBudget' => $strategy->budget,
                            'platform' => 'microsoft',
                        ]);

                        foreach ($recommendations as $rec) {
                            Recommendation::create([
                                'campaign_id' => $this->campaign->id,
                                'type' => $rec['type'],
                                'target_entity' => $rec['target_entity'],
                                'parameters' => $rec['parameters'],
                                'rationale' => $rec['rationale'],
                                'status' => 'pending',
                                'platform' => 'microsoft',
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $circuitBreaker->recordFailure();
                Log::error("Error in FetchMicrosoftAdsPerformanceData for campaign {$this->campaign->id}: " . $e->getMessage());
                $this->release(60);
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not acquire lock or circuit breaker open for Microsoft campaign {$this->campaign->id}");
            $this->release(60);
        }
    }
}
