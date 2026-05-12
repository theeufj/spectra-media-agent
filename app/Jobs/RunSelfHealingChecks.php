<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs every 4 hours. Scans all live campaigns for disapproved ads, rewrites
 * them, and resubmits — without running the heavier daily maintenance tasks
 * (keyword mining, bid adjustments, extensions, etc.).
 */
class RunSelfHealingChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 600;

    public function handle(
        SelfHealingAgent $selfHealingAgent,
        FacebookLearningPhaseAgent $fbLearningAgent,
        FacebookAdRelevanceDiagnosticsAgent $fbRelevanceAgent,
        LinkedInCampaignOptimizationAgent $linkedInAgent
    ): void {
        Log::info('RunSelfHealingChecks: Starting 4-hour healing pass');

        $campaigns = Campaign::with('customer')
            ->where('primary_status', 'ELIGIBLE')
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
                                ->orWhereNotNull('facebook_ads_campaign_id')
                                ->orWhereNotNull('microsoft_ads_campaign_id')
                                ->orWhereNotNull('linkedin_campaign_id'))
            ->get();

        $healed = 0;
        $errors = 0;

        foreach ($campaigns as $campaign) {
            $lock = Cache::lock("self_heal:campaign:{$campaign->id}", 3600);
            if (!$lock->get()) {
                continue;
            }

            try {
                $results = $selfHealingAgent->heal($campaign);
                $healed += count($results['actions_taken'] ?? []);
                $errors += count($results['errors'] ?? []);

                if ($campaign->facebook_ads_campaign_id) {
                    $fbLearningAgent->analyze($campaign);
                    if (!FacebookLearningPhaseAgent::isOnHold($campaign)) {
                        $fbRelevanceAgent->analyze($campaign);
                    }
                }

                if ($campaign->linkedin_campaign_id) {
                    $linkedInAgent->analyze($campaign);
                }

                if (!empty($results['actions_taken'])) {
                    AgentActivity::record(
                        'maintenance',
                        'self_healed',
                        'Fixed ' . count($results['actions_taken']) . ' issue(s) in "' . $campaign->name . '"',
                        $campaign->customer_id,
                        $campaign->id,
                        ['actions' => $results['actions_taken']]
                    );
                }
            } catch (\Exception $e) {
                Log::error('RunSelfHealingChecks: Error processing campaign ' . $campaign->id . ': ' . $e->getMessage());
                $errors++;
            } finally {
                $lock->release();
            }
        }

        Log::info('RunSelfHealingChecks: Completed', [
            'campaigns' => $campaigns->count(),
            'healed'    => $healed,
            'errors'    => $errors,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RunSelfHealingChecks failed: ' . $exception->getMessage());
    }
}
