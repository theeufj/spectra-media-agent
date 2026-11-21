<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\FacebookAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use App\Services\FacebookAds\FacebookCampaignMetrics;
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
        $entries = collect(CampaignResourceInventory::entries($this->campaign))->where('platform', 'facebook');
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
        $lock = Cache::lock('fetch-facebook-performance-'.$entry['account_id'].'-'.$entry['resource_id'], 600);
        $circuitBreaker = new CircuitBreakerService('FacebookAdsAPI');
        $customer = $this->campaign->customer;

        if (! $customer) {
            Log::warning("Campaign {$this->campaign->id} does not have an associated customer. Skipping performance fetch.");

            return;
        }

        if ($circuitBreaker->isAvailable() && $lock->get()) {
            try {
                Log::info("Starting FetchFacebookAdsPerformanceData job for campaign ID: {$this->campaign->id}");

                $insightService = app(InsightService::class, ['customer' => $customer]);
                // Backfill 30 days on first run; 7 days on subsequent runs.
                $hasData = \App\Models\FacebookAdsPerformanceData::where('campaign_id', $this->campaign->id)->where('facebook_campaign_id', $entry['resource_id'])->exists();
                $daysBack = $hasData ? 7 : 30;
                $dateStart = now()->subDays($daysBack)->format('Y-m-d');
                $dateEnd = now()->format('Y-m-d');

                $actionType = FacebookCampaignMetrics::actionType($strategy);
                $insights = $insightService->getCampaignInsights(
                    $entry['resource_id'],
                    $dateStart,
                    $dateEnd
                );

                if (is_array($insights) && empty($insights)) {
                    $insights = $this->getFallbackAdSetInsights($this->campaign, $insightService, $dateStart, $dateEnd, $actionType, $entry['resource_id'], $entry['account_id']);
                }

                if ($insights === null) {
                    throw new \RuntimeException('Meta did not return a complete daily campaign report.');
                }

                $circuitBreaker->recordSuccess();

                $performanceData = [];
                foreach ($insights as $insight) {
                    $date = $insight['date_start'] ?? $insight['date_stop'] ?? null;

                    if (! $date || ($insight['date_stop'] ?? '') !== $date || $date < $dateStart || $date > $dateEnd
                        || (string) ($insight['account_id'] ?? '') !== $entry['account_id']
                        || (string) ($insight['campaign_id'] ?? '') !== $entry['resource_id']
                        || ! preg_match('/^[A-Z]{3}$/D', (string) ($insight['account_currency'] ?? ''))) {
                        throw new \RuntimeException('Daily Meta delivery did not identify the requested account, campaign, currency and day.');
                    }

                    // Parse conversions from actions
                    $conversions = $insight['goal_conversions'] ?? FacebookCampaignMetrics::conversions($insight['actions'] ?? null, $actionType);
                    $clicks = (int) ($insight['inline_link_clicks'] ?? 0);

                    $cost = (string) ($insight['spend'] ?? '0');
                    $data = [
                        'campaign_id' => $this->campaign->id,
                        'facebook_campaign_id' => $entry['resource_id'],
                        'facebook_account_id' => $entry['account_id'],
                        'currency' => $insight['account_currency'],
                        'date' => $date,
                        'strategy_id' => $entry['strategy_id'],
                        'impressions' => (int) ($insight['impressions'] ?? 0),
                        'clicks' => $clicks,
                        'cost' => $cost,
                        'conversions' => $conversions,
                        'conversion_value' => $insight['purchase_value'] ?? FacebookCampaignMetrics::conversions($insight['action_values'] ?? null, 'purchase'),
                        'reach' => (int) ($insight['reach'] ?? null),
                        'frequency' => (float) ($insight['frequency'] ?? null),
                        'cpc' => $clicks > 0 ? bcround(bcdiv($cost, (string) $clicks, 6), 4) : 0,
                        'cpm' => (float) ($insight['cpm'] ?? null),
                        'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0.0,
                    ];

                    FacebookAdsPerformanceData::updateOrCreate(
                        [
                            'campaign_id' => $this->campaign->id,
                            'facebook_campaign_id' => $entry['resource_id'],
                            'date' => $data['date'],
                        ],
                        $data
                    );

                    $performanceData[] = $data;
                }

                Log::info("Successfully fetched and stored Facebook Ads performance data for campaign ID: {$this->campaign->id}", [
                    'records_stored' => count($performanceData),
                ]);

                if (! $strategy) {
                    return;
                }
                $evidence = app(FacebookAttributionEvidence::class);
                $evidence->synchronize($strategy, $entry, $insightService);
                // The legacy campaign totals include on-site/omni events. Only the signed ad-set pixel can inform a budget change.
                $recommendationRows = $evidence->verifiedDays($strategy)->whereBetween('date', [$dateStart, $dateEnd])
                    ->select(['date', 'impressions', 'clicks', 'cost', 'currency'])
                    ->selectRaw('attributed_conversions AS conversions, attributed_value AS conversion_value')
                    ->get()->map(fn ($row) => (array) $row)->all();

                if ($recommendationRows !== []) {
                    $recommendationService = app(RecommendationGenerationService::class);
                    $campaignConfig = [
                        'campaignId' => $entry['resource_id'],
                        'dailyBudget' => $strategy->daily_budget ?? $this->campaign->daily_budget,
                        'platform' => 'facebook',
                    ];

                    $recommendations = ($recommendationService)($recommendationRows, $campaignConfig);

                    foreach ($recommendations as $rec) {
                        Recommendation::create([
                            'campaign_id' => $this->campaign->id,
                            'type' => $rec['type'] ?? 'UNKNOWN',
                            'target_entity' => array_merge($this->recommendationTarget($rec), ['external_campaign_id' => $entry['resource_id'], 'strategy_id' => $entry['strategy_id'], 'account_id' => $entry['account_id']]),
                            'parameters' => $this->recommendationParameters($rec),
                            'rationale' => $rec['rationale'] ?? '',
                            'status' => 'pending',
                            'platform' => 'facebook',
                        ]);
                    }

                    Log::info('Generated '.count($recommendations)." recommendations for campaign {$this->campaign->id}");
                }

            } catch (\Throwable $e) {
                Log::error("Error in FetchFacebookAdsPerformanceData job for campaign {$this->campaign->id}: ".$e->getMessage(), [
                    'exception' => $e,
                ]);
                $circuitBreaker->recordFailure();
                throw $e;
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not acquire lock or circuit breaker is open for campaign {$this->campaign->id}");
        }
    }

    /**
     * What a recommendation acts on.
     *
     * RecommendationGenerationService emits target_campaign_id or keyword_text
     * depending on the type, and the LLM path emits whatever the model returned.
     * Reading 'target_entity' directly raised "Undefined array key", which
     * HandleExceptions turns into an ErrorException — so every fetch that
     * produced a deterministic budget recommendation threw, and no Facebook
     * recommendation was ever stored. Same shape as the helper of the same name
     * in FetchGoogleAdsPerformanceData.
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

        return ['campaign_id' => $this->campaign->facebook_ads_campaign_id];
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
        Log::error('FetchFacebookAdsPerformanceData failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }

    protected function getFallbackAdSetInsights(
        Campaign $campaign,
        InsightService $insightService,
        string $dateStart,
        string $dateEnd,
        string $actionType = 'purchase',
        ?string $resourceId = null,
        ?string $accountId = null,
    ): array {
        $resourceId ??= $campaign->facebook_ads_campaign_id;
        $adSets = $insightService->reportingAdSets($resourceId);
        if ($adSets === null) {
            throw new \RuntimeException('Meta could not list all ad sets for the daily delivery report.');
        }
        $aggregated = [];

        foreach ($adSets as $adSet) {
            if (empty($adSet['id'])) {
                continue;
            }

            $adSetInsights = $insightService->getAdSetInsights($adSet['id'], $dateStart, $dateEnd);
            if ($adSetInsights === null) {
                throw new \RuntimeException('Meta returned an incomplete fallback delivery report.');
            }

            foreach ($adSetInsights as $insight) {
                $date = $insight['date_start'] ?? $insight['date_stop'] ?? null;
                $currency = $insight['account_currency'] ?? '';
                if (! $date || $date !== ($insight['date_stop'] ?? '') || ! preg_match('/^[A-Z]{3}$/D', $currency)
                    || (string) ($insight['campaign_id'] ?? '') !== $resourceId || (string) ($insight['account_id'] ?? '') !== $accountId
                    || (string) ($insight['adset_id'] ?? '') !== (string) $adSet['id'] || ($aggregated[$date]['account_currency'] ?? $currency) !== $currency) {
                    throw new \RuntimeException('Fallback Meta delivery contained unexpected account, ad set, currency or date.');
                }

                if (! isset($aggregated[$date])) {
                    $aggregated[$date] = [
                        'date_start' => $date, 'campaign_id' => $resourceId, 'account_id' => $accountId, 'account_currency' => $currency,
                        'date_stop' => $date,
                        'impressions' => 0,
                        'inline_link_clicks' => 0,
                        'spend' => '0.00',
                        'reach' => 0,
                        'frequency' => 0.0,
                        'cpm' => 0.0,
                        'goal_conversions' => 0,
                        'purchase_value' => 0,
                    ];
                }

                $aggregated[$date]['impressions'] += (int) ($insight['impressions'] ?? 0);
                $aggregated[$date]['inline_link_clicks'] += (int) ($insight['inline_link_clicks'] ?? 0);
                $aggregated[$date]['spend'] = bcadd($aggregated[$date]['spend'], (string) ($insight['spend'] ?? '0'), 6);
                $aggregated[$date]['reach'] += (int) ($insight['reach'] ?? 0);
                $aggregated[$date]['purchase_value'] += FacebookCampaignMetrics::conversions($insight['action_values'] ?? null, 'purchase');
                $aggregated[$date]['goal_conversions'] += FacebookCampaignMetrics::conversions($insight['actions'] ?? null, $actionType);
            }
        }

        // Recalculate per-unit metrics from aggregated totals — copying them from individual
        // ad sets produces incorrect blended rates when multiple ad sets are present.
        foreach ($aggregated as &$row) {
            $row['cpm'] = $row['impressions'] > 0 ? round($row['spend'] / $row['impressions'] * 1000, 4) : 0.0;
            $row['frequency'] = $row['reach'] > 0 ? round($row['impressions'] / $row['reach'], 4) : 0.0;
        }
        unset($row);

        return array_values($aggregated);
    }
}
