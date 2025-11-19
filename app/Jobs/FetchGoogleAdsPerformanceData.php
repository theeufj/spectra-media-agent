<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\GoogleAds\GoogleAdsService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Google\Ads\GoogleAds\V15\Services\GoogleAdsServiceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchGoogleAdsPerformanceData implements ShouldQueue
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
        if (empty($this->campaign->google_ads_campaign_id)) {
            Log::warning("Campaign {$this->campaign->id} does not have a Google Ads Campaign ID. Skipping performance fetch.");
            return;
        }

        $lock = Cache::lock('fetch-performance-data-'.$this->campaign->google_ads_campaign_id, 600);
        $circuitBreaker = new CircuitBreakerService('GoogleAdsAPI');
        $customer = $this->campaign->customer;

        if ($lock->get() && $circuitBreaker->isAvailable()) {
            try {
                Log::info("Starting FetchGoogleAdsPerformanceData job for campaign ID: {$this->campaign->id}");

                $googleAdsService = new GoogleAdsService($customer);
                $googleAdsServiceClient = $googleAdsService->getClient()->getGoogleAdsServiceClient();

                $query = "SELECT campaign.id, campaign.name, metrics.impressions, metrics.clicks, "
                       . "metrics.cost_micros, metrics.conversions, segments.date FROM campaign "
                       . "WHERE campaign.resource_name = '{$this->campaign->google_ads_campaign_id}' "
                       . "AND segments.date BETWEEN '" . now()->subDays(3)->format('Y-m-d') . "' AND '" . now()->format('Y-m-d') . "'";

                $response = $googleAdsServiceClient->search($customer->google_ads_customer_id, $query);
                $circuitBreaker->recordSuccess();

                $performanceData = [];
                foreach ($response->getIterator() as $googleAdsRow) {
                    $metrics = $googleAdsRow->getMetrics();
                    $segments = $googleAdsRow->getSegments();

                    $data = [
                        'campaign_id' => $this->campaign->id,
                        'date' => $segments->getDate(),
                        'impressions' => $metrics->getImpressions(),
                        'clicks' => $metrics->getClicks(),
                        'cost' => $metrics->getCostMicros() / 1000000,
                        'conversions' => $metrics->getConversions(),
                    ];

                    GoogleAdsPerformanceData::create($data);
                    $performanceData[] = $data;
                }

                Log::info("Successfully fetched and stored Google Ads performance data for campaign ID: {$this->campaign->id}");

                $strategy = $this->campaign->strategies()->latest()->first();
                if ($strategy) {
                    $recommendationService = new RecommendationGenerationService();
                    $campaignConfig = [
                        'campaignId' => $this->campaign->google_ads_campaign_id,
                        'dailyBudget' => $strategy->budget,
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
                        ]);
                    }

                    // Dispatch the GenerateStrategy job to act as the Strategy Agent
                    GenerateStrategy::dispatch($this->campaign);
                }

            } catch (\Exception $e) {
                $circuitBreaker->recordFailure();
                Log::error("Error in FetchGoogleAdsPerformanceData job for campaign {$this->campaign->id}: " . $e->getMessage());
                $this->release(60);
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not obtain lock or circuit breaker is tripped for campaign ID: {$this->campaign->id}.");
            $this->release(60);
        }
    }
}
