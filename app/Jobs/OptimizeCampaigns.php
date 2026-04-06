<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\CampaignOptimizationAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizeCampaigns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(CampaignOptimizationAgent $optimizationAgent): void
    {
        // Find active campaigns that haven't been optimized in the last 24 hours
        // We only optimize campaigns that are 'ELIGIBLE' (primary status)
        // This covers both Google (ENABLED/ELIGIBLE) and Facebook (ACTIVE)
        $campaigns = Campaign::where('primary_status', 'ELIGIBLE')
            ->where(function ($query) {
                $query->whereNotNull('google_ads_campaign_id')
                      ->orWhereNotNull('facebook_ads_campaign_id');
            })
            ->where(function ($query) {
                $query->whereNull('last_optimized_at')
                      ->orWhere('last_optimized_at', '<=', now()->subHours(24));
            })
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                Log::info("Starting optimization for campaign {$campaign->id} ({$campaign->name})");

                $recommendations = $optimizationAgent->analyze($campaign);

                if ($recommendations) {
                    // Store recommendations in the database
                    // We need a place to store these. For now, let's assume a 'recommendations' column or a related model.
                    // Since we don't have a 'recommendations' table yet, I'll add a JSON column to the campaign or create a new model.
                    // For this iteration, let's log them and maybe update a 'optimization_status' field.
                    
                    // Ideally: CampaignRecommendation model.
                    // For now: Update a 'latest_optimization_analysis' column on the campaign.
                    
                    $campaign->update([
                        'latest_optimization_analysis' => $recommendations,
                        'last_optimized_at' => now(),
                    ]);

                    Log::info("Optimization analysis completed for campaign {$campaign->id}");

                    AgentActivity::record(
                        'optimization',
                        'analyzed_campaign',
                        "Analyzed \"{$campaign->name}\" and generated optimization recommendations",
                        $campaign->customer_id,
                        $campaign->id,
                        ['recommendations_count' => is_array($recommendations) ? count($recommendations) : 0]
                    );
                }

            } catch (\Exception $e) {
                Log::error("Failed to optimize campaign {$campaign->id}: " . $e->getMessage(), [
                    'campaign_id' => $campaign->id,
                    'exception' => get_class($e),
                ]);
                // Continue to next campaign — don't let one failure block others
            }
        }
    }
}
