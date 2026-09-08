<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\Recommendation;
use App\Services\CircuitBreaker\CircuitBreakerService;
use App\Services\GoogleAds\RecommendationGenerationService;
use App\Services\LinkedInAds\PerformanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchLinkedInAdsPerformanceData implements ShouldQueue
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

        if (! $customer || ! $customer->linkedin_ads_account_id) {
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

                    if (! empty($performanceData)) {
                        $recommendationService = new RecommendationGenerationService;
                        $recommendations = ($recommendationService)($performanceData, [
                            'campaignId' => $this->campaign->linkedin_campaign_id,
                            // strategies has no 'budget' column — it is daily_budget, so this
                            // silently passed null into the recommendation engine.
                            'dailyBudget' => $strategy->daily_budget,
                            'platform' => 'linkedin',
                        ]);

                        foreach ($recommendations as $rec) {
                            Recommendation::create([
                                'campaign_id' => $this->campaign->id,
                                'type' => $rec['type'] ?? 'UNKNOWN',
                                'target_entity' => $this->recommendationTarget($rec),
                                'parameters' => $this->recommendationParameters($rec),
                                'rationale' => $rec['rationale'] ?? '',
                                'status' => 'pending',
                                'platform' => 'linkedin',
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                $circuitBreaker->recordFailure();
                Log::error("Error in FetchLinkedInAdsPerformanceData for campaign {$this->campaign->id}: ".$e->getMessage());
                $this->release(60);
            } finally {
                $lock->release();
            }
        } else {
            Log::warning("Could not acquire lock or circuit breaker open for LinkedIn campaign {$this->campaign->id}");
            $this->release(60);
        }
    }

    /**
     * What a recommendation acts on.
     *
     * RecommendationGenerationService emits target_campaign_id or keyword_text
     * depending on the type, and the LLM path emits whatever the model returned.
     * Reading 'target_entity' directly raised "Undefined array key", which
     * HandleExceptions turns into an ErrorException — so the fetch released and
     * burned all five tries, and no LinkedIn recommendation was ever stored.
     * Same shape as the helper of the same name in FetchGoogleAdsPerformanceData.
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

        return ['campaign_id' => $this->campaign->linkedin_campaign_id];
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
        Log::error('FetchLinkedInAdsPerformanceData failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
