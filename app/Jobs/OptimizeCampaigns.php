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

                    $categorized    = $recommendations['categorized'] ?? [];
                    $autoApply      = $categorized['auto_apply'] ?? [];
                    $needsReview    = array_merge(
                        $categorized['recommended'] ?? [],
                        $categorized['review_required'] ?? []
                    );

                    $appliedCount = 0;
                    $pendingCount = 0;

                    // Auto-apply high-confidence recommendations immediately
                    foreach ($autoApply as $rec) {
                        $result = $optimizationAgent->applyRecommendation($campaign, $rec);

                        Recommendation::create([
                            'campaign_id'      => $campaign->id,
                            'type'             => $rec['type'] ?? 'general',
                            'target_entity'    => $rec['target_entity'] ?? $rec['target'] ?? null,
                            'parameters'       => $rec['parameters'] ?? $rec['params'] ?? null,
                            'rationale'        => $rec['rationale'] ?? $rec['reason'] ?? $rec['description'] ?? null,
                            'status'           => $result['applied'] ? 'applied' : 'failed',
                            'requires_approval' => false,
                            'platform'         => $campaign->google_ads_campaign_id ? 'google' : 'facebook',
                        ]);

                        if ($result['applied']) {
                            $appliedCount++;
                        }
                    }

                    // Store lower-confidence recommendations for human review
                    foreach ($needsReview as $rec) {
                        Recommendation::create([
                            'campaign_id'      => $campaign->id,
                            'type'             => $rec['type'] ?? 'general',
                            'target_entity'    => $rec['target_entity'] ?? $rec['target'] ?? null,
                            'parameters'       => $rec['parameters'] ?? $rec['params'] ?? null,
                            'rationale'        => $rec['rationale'] ?? $rec['reason'] ?? $rec['description'] ?? null,
                            'status'           => 'pending',
                            'requires_approval' => true,
                            'platform'         => $campaign->google_ads_campaign_id ? 'google' : 'facebook',
                        ]);
                        $pendingCount++;
                    }

                    $campaign->update([
                        'latest_optimization_analysis' => $recommendations,
                        'last_optimized_at'            => now(),
                    ]);

                    Log::info("Optimization complete for campaign {$campaign->id}", [
                        'auto_applied'  => $appliedCount,
                        'pending_review' => $pendingCount,
                    ]);

                    AgentActivity::record(
                        'optimization',
                        'analyzed_campaign',
                        "Optimised \"{$campaign->name}\": {$appliedCount} changes applied automatically, {$pendingCount} queued for review",
                        $campaign->customer_id,
                        $campaign->id,
                        ['auto_applied' => $appliedCount, 'pending_review' => $pendingCount]
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
