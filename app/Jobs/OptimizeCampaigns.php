<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Recommendation;
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
        // Find active campaigns that are 'ELIGIBLE' (primary status)
        // This covers both Google (ENABLED/ELIGIBLE) and Facebook (ACTIVE)
        $campaigns = Campaign::where('primary_status', 'ELIGIBLE')
            ->where(function ($query) {
                $query->whereNotNull('google_ads_campaign_id')
                      ->orWhereNotNull('facebook_ads_campaign_id');
            })
            ->whereNull('last_optimized_at')
            ->orWhere('last_optimized_at', '<=', now()->subHours(24))
            ->get();

        // Filter by plan-aware optimization frequency:
        // Free / Starter → weekly (7 days), Growth / Agency → daily (24h)
        $campaigns = $campaigns->filter(function (Campaign $campaign) {
            if (is_null($campaign->last_optimized_at)) {
                return true; // never optimized — always run
            }

            $user = $campaign->customer?->users()?->first();
            $slug = $user?->resolveCurrentPlan()?->slug ?? 'free';

            $cooldown = in_array($slug, ['growth', 'agency'], true)
                ? now()->subHours(24)
                : now()->subDays(7);

            return $campaign->last_optimized_at <= $cooldown;
        });

        foreach ($campaigns as $campaign) {
            try {
                Log::info("Starting optimization for campaign {$campaign->id} ({$campaign->name})");

                $recommendations = $optimizationAgent->analyze($campaign);

                if ($recommendations) {
                    // Clear old pending recommendations for this campaign
                    Recommendation::where('campaign_id', $campaign->id)
                        ->where('status', 'pending')
                        ->delete();

                    // Store each recommendation as a proper DB record
                    $recommendationCount = 0;
                    if (is_array($recommendations)) {
                        foreach ($recommendations as $rec) {
                            if (is_array($rec)) {
                                Recommendation::create([
                                    'campaign_id' => $campaign->id,
                                    'type' => $rec['type'] ?? 'general',
                                    'target_entity' => $rec['target_entity'] ?? $rec['target'] ?? null,
                                    'parameters' => $rec['parameters'] ?? $rec['params'] ?? null,
                                    'rationale' => $rec['rationale'] ?? $rec['reason'] ?? $rec['description'] ?? null,
                                    'status' => 'pending',
                                    'requires_approval' => $rec['requires_approval'] ?? true,
                                ]);
                                $recommendationCount++;
                            }
                        }
                    }

                    // Keep JSON summary on campaign for quick access
                    $campaign->update([
                        'latest_optimization_analysis' => $recommendations,
                        'last_optimized_at' => now(),
                    ]);

                    Log::info("Optimization analysis completed for campaign {$campaign->id}", [
                        'recommendations_stored' => $recommendationCount,
                    ]);

                    AgentActivity::record(
                        'optimization',
                        'analyzed_campaign',
                        "Analyzed \"{$campaign->name}\" and generated {$recommendationCount} optimization recommendations",
                        $campaign->customer_id,
                        $campaign->id,
                        ['recommendations_count' => $recommendationCount]
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

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('OptimizeCampaigns failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
