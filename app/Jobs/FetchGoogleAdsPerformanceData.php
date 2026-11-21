<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\GoogleAds\AccountStructureService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchGoogleAdsPerformanceData implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [10, 20, 30, 40, 50];

    protected Campaign $campaign;

    protected ?string $resourceId = null;

    public function __construct(Campaign $campaign, ?string $resourceId = null)
    {
        $this->campaign = $campaign;
        $this->resourceId = $resourceId;
    }

    public function handle(): void
    {
        $entries = collect(CampaignResourceInventory::entries($this->campaign))->where('platform', 'google');
        if ($this->resourceId === null && $entries->count() > 1) {
            foreach ($entries as $entry) {
                static::dispatch($this->campaign, $entry['resource_id']);
            }

            return;
        }
        $entry = $this->resourceId === null ? $entries->first() : $entries->firstWhere('resource_id', $this->resourceId);
        if (! $entry) {
            return;
        }
        $strategy = $this->campaign->strategies->firstWhere('id', $entry['strategy_id']);
        $lock = Cache::lock('fetch-google-performance-'.$entry['account_id'].'-'.$entry['resource_id'], 600);
        $circuitBreaker = new CircuitBreakerService('GoogleAdsAPI');
        $customer = $this->campaign->customer;

        if ($circuitBreaker->isAvailable() && $lock->get()) {
            try {
                Log::info("Starting FetchGoogleAdsPerformanceData job for campaign ID: {$this->campaign->id}");

                $service = new AccountStructureService($customer);
                $googleAdsServiceClient = $service->getClient()->getGoogleAdsServiceClient();

                // Backfill 30 days on first run; 7 days on subsequent runs.
                // The updateOrCreate below prevents duplicates on overlap.
                $hasData = \App\Models\GoogleAdsPerformanceData::where('campaign_id', $this->campaign->id)->where('google_campaign_id', $entry['resource_id'])->exists();
                $daysBack = $hasData ? 7 : 30;

                $resourceName = $entry['resource_name'];
                $dateFrom = now()->subDays($daysBack)->format('Y-m-d');
                $dateTo = now()->format('Y-m-d');
                $query = 'SELECT customer.id, customer.currency_code, campaign.id, campaign.name, metrics.impressions, metrics.clicks, '
                       .'metrics.cost_micros, metrics.conversions, metrics.conversions_value, '
                       .'metrics.search_impression_share, metrics.search_top_impression_share, '
                       .'metrics.view_through_conversions, metrics.all_conversions, metrics.interaction_rate, '
                       .'segments.date FROM campaign '
                       ."WHERE campaign.resource_name = '".addslashes($resourceName)."' "
                       ."AND segments.date BETWEEN '{$dateFrom}' AND '{$dateTo}'";

                $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                    'customer_id' => $entry['account_id'],
                    'query' => $query,
                ]));
                $circuitBreaker->recordSuccess();

                $performanceData = [];
                foreach ($response->getIterator() as $googleAdsRow) {
                    $remoteCustomer = $googleAdsRow->getCustomer();
                    $currency = $remoteCustomer?->getCurrencyCode();
                    if ((string) $remoteCustomer?->getId() !== $entry['account_id'] || (string) $googleAdsRow->getCampaign()?->getId() !== $entry['resource_id']
                        || ! preg_match('/^[A-Z]{3}$/D', (string) $currency)) {
                        throw new \RuntimeException('Daily Google delivery did not identify the requested account, campaign and currency.');
                    }
                    $metrics = $googleAdsRow->getMetrics();
                    $segments = $googleAdsRow->getSegments();

                    $impressions = $metrics->getImpressions();
                    $clicks = $metrics->getClicks();
                    $cost = bcdiv((string) $metrics->getCostMicros(), '1000000', 6);
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
                        'google_campaign_id' => $entry['resource_id'],
                        'google_account_id' => $entry['account_id'], 'currency' => $currency,
                        'strategy_id' => $entry['strategy_id'],
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
                        ['campaign_id' => $this->campaign->id, 'google_campaign_id' => $entry['resource_id'], 'date' => $segments->getDate()],
                        $data
                    );
                    $performanceData[] = $data;
                }

                Log::info("Successfully fetched and stored Google Ads performance data for campaign ID: {$this->campaign->id}");

                if ($strategy) {
                    $recommendationService = new RecommendationGenerationService;
                    $campaignConfig = [
                        'campaignId' => $entry['resource_name'],
                        'dailyBudget' => $strategy->daily_budget ?? $this->campaign->daily_budget,
                    ];
                    $recommendations = ($recommendationService)($performanceData, $campaignConfig);

                    foreach ($recommendations as $rec) {
                        Recommendation::create([
                            'campaign_id' => $this->campaign->id,
                            'type' => $rec['type'] ?? 'UNKNOWN',
                            'target_entity' => array_merge($this->recommendationTarget($rec), ['external_campaign_id' => $entry['resource_id'], 'strategy_id' => $entry['strategy_id'], 'account_id' => $entry['account_id']]),
                            'parameters' => $this->recommendationParameters($rec),
                            'rationale' => $rec['rationale'] ?? '',
                            'status' => 'pending',
                        ]);
                    }

                }

            } catch (\Throwable $e) {
                report($e);
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
