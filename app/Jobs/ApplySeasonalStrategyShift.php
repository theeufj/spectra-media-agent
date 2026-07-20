<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Prompts\SeasonalStrategyPrompt;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\CreateSeasonalityAdjustment;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ApplySeasonalStrategyShift implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $campaignId;
    protected $season;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId, string $season)
    {
        $this->campaignId = $campaignId;
        $this->season = $season;
    }

    /**
     * Execute the job.
     */
    public function handle(GeminiService $geminiService): void
    {
        try {
            $campaign = Campaign::findOrFail($this->campaignId);
            $baselineStrategy = Config::get("seasonal_strategies.{$this->season}", Config::get('seasonal_strategies.default'));

            Log::info("Applying {$this->season} strategy shift to campaign {$this->campaignId}.", [
                'campaign_id' => $this->campaignId,
                'season' => $this->season,
                'baseline_strategy' => $baselineStrategy,
            ]);

            // Fetch real campaign data from database
            $currentStrategy = $campaign->strategies()->latest()->first();
            
            // Extract bidding strategy name from JSON if it exists
            $biddingStrategyName = 'MAXIMIZE_CONVERSIONS'; // Default fallback
            if ($currentStrategy && $currentStrategy->bidding_strategy) {
                $biddingData = is_string($currentStrategy->bidding_strategy) 
                    ? json_decode($currentStrategy->bidding_strategy, true) 
                    : $currentStrategy->bidding_strategy;
                $biddingStrategyName = $biddingData['name'] ?? 'MAXIMIZE_CONVERSIONS';
            }
            
            // Fetch top performing keywords from ad copies
            $topKeywords = $campaign->adCopies()
                ->where('status', 'approved')
                ->limit(10)
                ->pluck('headlines')
                ->flatten()
                ->filter()
                ->take(5)
                ->values()
                ->toArray();
            
            if (empty($topKeywords)) {
                $topKeywords = ['brand', 'product', 'service']; // Generic fallback
            }

            $campaignData = [
                'current_budget' => $campaign->total_budget ?? 100.00,
                'current_bidding_strategy' => $biddingStrategyName,
                'top_performing_keywords' => $topKeywords,
            ];

            $prompt = (new SeasonalStrategyPrompt($campaignData, $this->season, $baselineStrategy))->getPrompt();
            $generatedResponse = $geminiService->generateContent(config('ai.models.default'), $prompt);

            if (is_null($generatedResponse) || !isset($generatedResponse['text'])) {
                Log::error("LLM failed to generate a seasonal strategy shift.");
                return;
            }

            $strategyShift = json_decode($generatedResponse['text'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse LLM's seasonal strategy response.", [
                    'generated_text' => $generatedResponse['text'],
                ]);
                return;
            }

            Log::info("Generated seasonal strategy shift:", $strategyShift);

            $this->applyBudgetAdjustment($campaign, $strategyShift, $baselineStrategy);

            AgentActivity::record(
                'seasonal',
                'strategy_applied',
                "Applied {$this->season} seasonal strategy to \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['season' => $this->season, 'strategy_shift' => $strategyShift]
            );

            // Optionally create a Google Ads BiddingSeasonalityAdjustment for Smart Bidding awareness
            $this->applySeasonalityAdjustment($campaign);

        } catch (\Exception $e) {
            Log::error("Error applying seasonal strategy shift to campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            // Rethrow so the queue marks this failed (retry + failed() + health monitor)
            // instead of silently reporting success on every run.
            throw $e;
        }
    }

    /**
     * Compute a per-campaign seasonal budget multiplier by comparing this season's
     * historical spend (same date range last year) to the 4-week pre-season baseline.
     * Blends 50/50 with the global config multiplier. Falls back to config-only if
     * fewer than 7 days of historical data exist.
     */
    private function computeSeasonalMultiplier(Campaign $campaign, string $season): float
    {
        $globalMultiplier = Config::get("seasonal_strategies.{$season}.budget_multiplier", 1.0);

        // Map season names to approximate date ranges (MM-DD format)
        $seasonRanges = [
            'black_friday' => ['11-01', '11-30'],
            'summer_sale'  => ['06-01', '08-31'],
            'default'      => null,
        ];

        if (!isset($seasonRanges[$season]) || $seasonRanges[$season] === null) {
            return $globalMultiplier;
        }

        [$startMD, $endMD] = $seasonRanges[$season];

        $model = $campaign->google_ads_campaign_id
            ? GoogleAdsPerformanceData::class
            : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

        if (!$model) {
            return $globalMultiplier;
        }

        $year = (int) now()->format('Y') - 1;

        $seasonStart = "{$year}-" . str_replace('-', '-', $startMD);
        $seasonEnd   = "{$year}-" . str_replace('-', '-', $endMD);

        $seasonSpend = $model::where('campaign_id', $campaign->id)
            ->whereBetween('date', [$seasonStart, $seasonEnd])
            ->sum('cost');

        $seasonDays = (int) round((strtotime($seasonEnd) - strtotime($seasonStart)) / 86400) + 1;

        if ($seasonSpend <= 0 || $seasonDays < 7) {
            return $globalMultiplier;
        }

        $seasonDailyAvg = $seasonSpend / $seasonDays;

        // 4-week pre-season baseline (same year, prior to season start)
        $baselineEnd   = date('Y-m-d', strtotime("{$year}-{$startMD} -1 day"));
        $baselineStart = date('Y-m-d', strtotime("{$baselineEnd} -27 days"));

        $baselineSpend = $model::where('campaign_id', $campaign->id)
            ->whereBetween('date', [$baselineStart, $baselineEnd])
            ->sum('cost');

        $baselineDailyAvg = $baselineSpend / 28;

        if ($baselineDailyAvg <= 0) {
            return $globalMultiplier;
        }

        $historicalRatio = $seasonDailyAvg / $baselineDailyAvg;

        // Blend: 50% historical signal + 50% global config guidance
        return round(($historicalRatio * 0.5) + ($globalMultiplier * 0.5), 3);
    }

    private function applyBudgetAdjustment(Campaign $campaign, array $strategyShift, array $baselineStrategy): void
    {
        $budgetMultiplier = $this->computeSeasonalMultiplier($campaign, $this->season);
        $currentBudget = $campaign->daily_budget ?? $campaign->total_budget ?? 0;

        // Use the AI-generated budget if available, otherwise apply the config multiplier
        $candidateBudget = (float) ($strategyShift['budget_adjustment']['new_daily_budget']
            ?? round($currentBudget * $budgetMultiplier, 2));

        // Clamp the candidate to a safe band around the existing baseline. The budget
        // may originate from an LLM, so an unbounded value (hallucination or prompt
        // drift) could otherwise push thousands of dollars/day of live spend. Never
        // move more than 2x/0.5x the current daily budget, and hard-cap absolutely.
        $absoluteCap = (float) config('ai.seasonal.max_daily_budget', 2000.0);
        if ($currentBudget > 0) {
            $floor = round($currentBudget * 0.5, 2);
            $ceil  = round($currentBudget * 2.0, 2);
            $newDailyBudget = max($floor, min($candidateBudget, $ceil));
        } else {
            // No baseline to bound against — fall back to a conservative absolute cap.
            $newDailyBudget = min($candidateBudget, (float) config('ai.seasonal.no_baseline_cap', 500.0));
        }
        $newDailyBudget = round(min($newDailyBudget, $absoluteCap), 2);

        if ($newDailyBudget !== $candidateBudget) {
            Log::warning("Seasonal budget clamped for campaign {$campaign->id}", [
                'requested' => $candidateBudget,
                'applied'   => $newDailyBudget,
                'baseline'  => $currentBudget,
            ]);
        }

        if ($newDailyBudget <= 0) {
            Log::warning("Skipping budget adjustment — calculated budget is zero for campaign {$campaign->id}");
            return;
        }

        $customer = $campaign->customer;

        // Apply to Google Ads
        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            try {
                $updateBudget = app(UpdateCampaignBudget::class, ['customer' => $customer]);
                $customerId = $customer->google_ads_customer_id;
                $resourceName = $campaign->googleAdsResourceName();
                $budgetMicros = $newDailyBudget * 1_000_000;

                $updateBudget($customerId, $resourceName, $budgetMicros);
                Log::info("Applied seasonal budget to Google campaign {$campaign->id}: \${$newDailyBudget}/day");
            } catch (\Exception $e) {
                Log::error("Failed to apply seasonal budget to Google campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        // Apply to Facebook Ads
        if ($campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
            try {
                $fbCampaignService = new FacebookCampaignService($customer);
                // Facebook budget is in cents
                $budgetCents = (int) round($newDailyBudget * 100);
                $fbCampaignService->updateCampaign($campaign->facebook_ads_campaign_id, [
                    'daily_budget' => $budgetCents,
                ]);
                Log::info("Applied seasonal budget to Facebook campaign {$campaign->id}: \${$newDailyBudget}/day");
            } catch (\Exception $e) {
                Log::error("Failed to apply seasonal budget to Facebook campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        // Update the campaign record
        $campaign->update(['daily_budget' => $newDailyBudget]);
    }

    /**
     * Create a Google Ads BiddingSeasonalityAdjustment so Smart Bidding is aware
     * of the expected conversion rate change. Failures here are non-blocking.
     */
    private function applySeasonalityAdjustment(Campaign $campaign): void
    {
        if (!$campaign->google_ads_campaign_id) {
            return;
        }

        $customer = $campaign->customer;
        if (!$customer || !$customer->google_ads_customer_id) {
            return;
        }

        try {
            // Map season names to date windows and conversion rate modifiers
            $season = strtolower($this->season);
            $year   = (int) now()->format('Y');

            [$startDateTime, $endDateTime, $modifier] = match (true) {
                in_array($season, ['holiday', 'christmas'], true) => [
                    "{$year}-12-20 00:00:00",
                    ($year + 1) . '-01-01 23:59:59',
                    1.40,
                ],
                $season === 'black_friday' => [
                    "{$year}-11-25 00:00:00",
                    "{$year}-12-02 23:59:59",
                    1.50,
                ],
                $season === 'summer' => [
                    "{$year}-06-01 00:00:00",
                    "{$year}-08-31 23:59:59",
                    0.90,
                ],
                default => [
                    now()->format('Y-m-d') . ' 00:00:00',
                    now()->addDays(14)->format('Y-m-d') . ' 23:59:59',
                    1.10,
                ],
            };

            $createAdjustment = new CreateSeasonalityAdjustment($customer);
            $resourceName = ($createAdjustment)($customer->google_ads_customer_id, [
                'name'                     => ucfirst($this->season) . ' ' . $year . ' - ' . $campaign->name,
                'scope'                    => 'CAMPAIGN',
                'start_date_time'          => $startDateTime,
                'end_date_time'            => $endDateTime,
                'conversion_rate_modifier' => $modifier,
                'campaign_resource'        => $campaign->google_ads_campaign_id,
            ]);

            if ($resourceName) {
                Log::info("ApplySeasonalStrategyShift: Created seasonality adjustment for campaign {$campaign->id}", [
                    'resource'  => $resourceName,
                    'season'    => $this->season,
                    'modifier'  => $modifier,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("ApplySeasonalStrategyShift: Seasonality adjustment failed for campaign {$campaign->id} — skipping", [
                'season' => $this->season,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ApplySeasonalStrategyShift failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
