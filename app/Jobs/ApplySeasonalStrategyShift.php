<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Prompts\SeasonalStrategyPrompt;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
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

        } catch (\Exception $e) {
            Log::error("Error applying seasonal strategy shift to campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function applyBudgetAdjustment(Campaign $campaign, array $strategyShift, array $baselineStrategy): void
    {
        $budgetMultiplier = $baselineStrategy['budget_multiplier'] ?? 1.0;
        $currentBudget = $campaign->daily_budget ?? $campaign->total_budget ?? 0;

        // Use the AI-generated budget if available, otherwise apply the config multiplier
        $newDailyBudget = $strategyShift['budget_adjustment']['new_daily_budget']
            ?? round($currentBudget * $budgetMultiplier, 2);

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
                $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
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
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ApplySeasonalStrategyShift failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
