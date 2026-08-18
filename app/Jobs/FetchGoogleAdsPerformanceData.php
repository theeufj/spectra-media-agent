<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\GoogleAds\AccountStructureService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
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

                $service = new AccountStructureService($customer);
                $googleAdsServiceClient = $service->getClient()->getGoogleAdsServiceClient();

                // Backfill 30 days on first run; 7 days on subsequent runs.
                // The updateOrCreate below prevents duplicates on overlap.
                $hasData = \App\Models\GoogleAdsPerformanceData::where('campaign_id', $this->campaign->id)->exists();
                $daysBack = $hasData ? 7 : 30;

                $resourceName = $this->campaign->google_ads_campaign_id;
                $dateFrom = now()->subDays($daysBack)->format('Y-m-d');
                $dateTo = now()->format('Y-m-d');
                $query = 'SELECT campaign.id, campaign.name, metrics.impressions, metrics.clicks, '
                       .'metrics.cost_micros, metrics.conversions, metrics.conversions_value, '
                       .'metrics.search_impression_share, metrics.search_top_impression_share, '
                       .'metrics.view_through_conversions, metrics.all_conversions, metrics.interaction_rate, '
                       .'segments.date FROM campaign '
                       ."WHERE campaign.resource_name = '".addslashes($resourceName)."' "
                       ."AND segments.date BETWEEN '{$dateFrom}' AND '{$dateTo}'";

                $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                    'customer_id' => $customer->google_ads_customer_id,
                    'query' => $query,
                ]));
                $circuitBreaker->recordSuccess();

                $performanceData = [];
                foreach ($response->getIterator() as $googleAdsRow) {
                    $metrics = $googleAdsRow->getMetrics();
                    $segments = $googleAdsRow->getSegments();

                    $impressions = $metrics->getImpressions();
                    $clicks = $metrics->getClicks();
                    $cost = $metrics->getCostMicros() / 1000000;
                    $conversions = $metrics->getConversions();
                    $conversionValue = $metrics->getConversionsValue();
                    $searchImpressionShare = $metrics->getSearchImpressionShare();
                    $searchTopImpressionShare = $metrics->getSearchTopImpressionShare();
                    $viewThroughConversions = $metrics->getViewThroughConversions();
                    $allConversions = $metrics->getAllConversions();
                    $interactionRate = $metrics->getInteractionRate();

                    $data = [
                        'campaign_id' => $this->campaign->id,
                        'date' => $segments->getDate(),
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'cost' => $cost,
                        'conversions' => $conversions,
                        'conversion_value' => $conversionValue,
                        'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
                        'cpc' => $clicks > 0 ? round($cost / $clicks, 2) : 0,
                        'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0,
                        'search_impression_share' => $searchImpressionShare,
                        'search_top_impression_share' => $searchTopImpressionShare,
                        'view_through_conversions' => $viewThroughConversions ?? 0,
                        'all_conversions' => $allConversions ?? 0,
                        'interaction_rate' => $interactionRate,
                    ];

                    GoogleAdsPerformanceData::updateOrCreate(
                        ['campaign_id' => $this->campaign->id, 'date' => $segments->getDate()],
                        $data
                    );
                    $performanceData[] = $data;
                }

                Log::info("Successfully fetched and stored Google Ads performance data for campaign ID: {$this->campaign->id}");

                $strategy = $this->campaign->strategies()->latest()->first();
                if ($strategy) {
                    $recommendationService = new RecommendationGenerationService;
                    $campaignConfig = [
                        'campaignId' => $this->campaign->google_ads_campaign_id,
                        'dailyBudget' => $strategy->daily_budget ?? $this->campaign->daily_budget,
                    ];
                    $recommendations = ($recommendationService)($performanceData, $campaignConfig);

                    foreach ($recommendations as $rec) {
                        Recommendation::create([
                            'campaign_id' => $this->campaign->id,
                            'type' => $rec['type'] ?? 'UNKNOWN',
                            'target_entity' => $this->recommendationTarget($rec),
                            'parameters' => $this->recommendationParameters($rec),
                            'rationale' => $rec['rationale'] ?? '',
                            'status' => 'pending',
                        ]);
                    }

                }

            } catch (\Exception $e) {
                $circuitBreaker->recordFailure();
                Log::error("Error in FetchGoogleAdsPerformanceData job for campaign {$this->campaign->id}: ".$e->getMessage());
                $this->release(60);
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not obtain lock or circuit breaker is tripped for campaign ID: {$this->campaign->id}. Will retry next scheduled run.");
            // Do not release — burning tries against a locked/open-circuit state is wasteful.
            // The scheduler will re-dispatch on the next run.
        }
    }

    /**
     * What a recommendation acts on.
     *
     * RecommendationGenerationService emits target_campaign_id or keyword_text
     * depending on the type, and the LLM path emits whatever the model returned.
     * This job read 'target_entity' and 'parameters' — keys none of those shapes
     * produce — so every fetch threw "Undefined array key", released, and burned
     * all five retries. Fourteen failed jobs in a day, and no recommendation was
     * ever stored from this path.
     *
     * @param  array<string, mixed>  $rec
     * @return array<string, mixed>
     */
    private function recommendationTarget(array $rec): array
    {
        foreach (['target_entity', 'target_campaign_id', 'keyword_text', 'target'] as $key) {
            if (! empty($rec[$key])) {
                return is_array($rec[$key]) ? $rec[$key] : [$key => $rec[$key]];
            }
        }

        return ['campaign_id' => $this->campaign->google_ads_campaign_id];
    }

    /**
     * The action payload, which is everything that is not narrative.
     *
     * Keeping the unrecognised keys matters more than naming them: a shape this
     * job does not know about is still worth storing, and dropping it silently
     * is how the original assumption survived.
     *
     * @param  array<string, mixed>  $rec
     * @return array<string, mixed>
     */
    private function recommendationParameters(array $rec): array
    {
        if (! empty($rec['parameters']) && is_array($rec['parameters'])) {
            return $rec['parameters'];
        }

        return collect($rec)
            ->except(['type', 'rationale', 'target_entity', 'parameters'])
            ->all();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FetchGoogleAdsPerformanceData failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
