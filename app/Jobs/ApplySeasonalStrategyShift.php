<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Prompts\SeasonalStrategyPrompt;
use App\Services\GeminiService;
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

            // For a real implementation, you would fetch current campaign data
            $campaignData = [
                'current_budget' => 50.00, // Placeholder
                'current_bidding_strategy' => 'MAXIMIZE_CONVERSIONS', // Placeholder
                'top_performing_keywords' => ['keyword1', 'keyword2'], // Placeholder
            ];

            $prompt = (new SeasonalStrategyPrompt($campaignData, $this->season, $baselineStrategy))->getPrompt();
            $generatedResponse = $geminiService->generateContent('gemini-2.5-pro', $prompt);

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

            // Here, you would dispatch jobs to the implementation agents to apply the strategy shift.
            // For example:
            // dispatch(new UpdateCampaignBudget($this->campaignId, $strategyShift['budget_adjustment']['new_daily_budget']));
            // dispatch(new UpdateBiddingStrategy($this->campaignId, $strategyShift['bidding_strategy_change']));

        } catch (\Exception $e) {
            Log::error("Error applying seasonal strategy shift to campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
