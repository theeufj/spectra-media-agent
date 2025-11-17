<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\FacebookAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\FacebookAds\InsightService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchFacebookAdsPerformanceData implements ShouldQueue
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
        if (empty($this->campaign->facebook_ads_campaign_id)) {
            Log::warning("Campaign {$this->campaign->id} does not have a Facebook Ads Campaign ID. Skipping performance fetch.");
            return;
        }

        $lock = Cache::lock('fetch-facebook-performance-data-'.$this->campaign->facebook_ads_campaign_id, 600);
        $circuitBreaker = new CircuitBreakerService('FacebookAdsAPI');
        $customer = $this->campaign->user->customer;

        if (!$customer || empty($customer->facebook_ads_access_token)) {
            Log::warning("Customer {$customer->id} does not have Facebook Ads credentials. Skipping performance fetch.");
            return;
        }

        if ($lock->get() && $circuitBreaker->isAvailable()) {
            try {
                Log::info("Starting FetchFacebookAdsPerformanceData job for campaign ID: {$this->campaign->id}");

                $insightService = new InsightService($customer);
                $dateStart = now()->subDays(3)->format('Y-m-d');
                $dateEnd = now()->format('Y-m-d');

                $insights = $insightService->getCampaignInsights(
                    $this->campaign->facebook_ads_campaign_id,
                    $dateStart,
                    $dateEnd
                );

                if ($insights === null) {
                    $circuitBreaker->recordFailure();
                    $this->fail(new \Exception("Failed to fetch Facebook Ads insights"));
                    return;
                }

                $circuitBreaker->recordSuccess();

                $performanceData = [];
                foreach ($insights as $insight) {
                    $date = $insight['date_start'] ?? $insight['date_stop'] ?? null;
                    
                    if (!$date) {
                        continue;
                    }

                    // Parse conversions from actions
                    $conversions = $insightService->parseAction($insight['actions'] ?? null, 'purchase');

                    $data = [
                        'campaign_id' => $this->campaign->id,
                        'facebook_campaign_id' => $this->campaign->facebook_ads_campaign_id,
                        'date' => $date,
                        'impressions' => (int) ($insight['impressions'] ?? 0),
                        'clicks' => (int) ($insight['clicks'] ?? 0),
                        'cost' => (float) ($insight['spend'] ?? 0) / 100, // Facebook returns spend in cents
                        'conversions' => $conversions,
                        'reach' => (int) ($insight['reach'] ?? null),
                        'frequency' => (float) ($insight['frequency'] ?? null),
                        'cpc' => (float) ($insight['cpc'] ?? null),
                        'cpm' => (float) ($insight['cpm'] ?? null),
                        'cpa' => (float) ($insight['cpa'] ?? null),
                    ];

                    FacebookAdsPerformanceData::updateOrCreate(
                        [
                            'campaign_id' => $this->campaign->id,
                            'facebook_campaign_id' => $this->campaign->facebook_ads_campaign_id,
                            'date' => $data['date'],
                        ],
                        $data
                    );

                    $performanceData[] = $data;
                }

                Log::info("Successfully fetched and stored Facebook Ads performance data for campaign ID: {$this->campaign->id}", [
                    'records_stored' => count($performanceData),
                ]);

                // Generate recommendations based on performance
                $strategy = $this->campaign->strategies()->latest()->first();
                if ($strategy && !empty($performanceData)) {
                    $recommendationService = new RecommendationGenerationService();
                    $campaignConfig = [
                        'campaignId' => $this->campaign->facebook_ads_campaign_id,
                        'dailyBudget' => $strategy->budget,
                        'platform' => 'facebook',
                    ];

                    $recommendations = ($recommendationService)($performanceData, $campaignConfig);

                    foreach ($recommendations as $rec) {
                        Recommendation::create([
                            'campaign_id' => $this->campaign->id,
                            'type' => $rec['type'],
                            'target_entity' => $rec['target_entity'],
                            'parameters' => $rec['parameters'],
                            'rationale' => $rec['rationale'],
                            'status' => 'pending',
                            'platform' => 'facebook',
                        ]);
                    }

                    Log::info("Generated {count($recommendations)} recommendations for campaign {$this->campaign->id}");
                }

                $lock->release();
            } catch (\Exception $e) {
                Log::error("Error in FetchFacebookAdsPerformanceData job for campaign {$this->campaign->id}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $circuitBreaker->recordFailure();
                throw $e;
            }
        } else {
            Log::warning("Could not acquire lock or circuit breaker is open for campaign {$this->campaign->id}");
        }
    }
}
