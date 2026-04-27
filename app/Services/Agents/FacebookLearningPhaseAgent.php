<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CriticalAgentAlert;
use App\Services\FacebookAds\InsightService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages Facebook campaign learning phase health.
 *
 * Facebook campaigns enter a learning phase (≤50 optimisation events) where
 * performance is unstable and edits reset the clock. Agencies actively manage
 * this — suppressing other agents from editing learning campaigns and escalating
 * campaigns stuck in LEARNING_LIMITED.
 *
 * Statuses handled:
 *   LEARNING            — normal, suppress optimisation for 72h after any recent edit
 *   LEARNING_LIMITED    — stuck (>7 days), recommend consolidation or budget increase
 *   LEARNING (>14 days) — never graduated, escalate to critical
 */
class FacebookLearningPhaseAgent
{
    public function analyze(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$campaign->facebook_ads_campaign_id || !$customer?->facebook_ads_account_id) {
            return ['skipped' => true];
        }

        $result = [
            'campaign_id'    => $campaign->id,
            'learning_status' => null,
            'issues'          => [],
            'warnings'        => [],
            'hold_applied'    => false,
        ];

        try {
            $insightService = new InsightService($customer);
            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                now()->subDays(1)->toDateString(),
                now()->toDateString(),
                ['learning_stage_info', 'insights_updated_time']
            );

            $learningInfo = $insights[0]['learning_stage_info'] ?? null;

            if (!$learningInfo) {
                return $result;
            }

            $status      = $learningInfo['status'] ?? null;
            $attribution = $learningInfo['attribution_windows'] ?? [];
            $result['learning_status'] = $status;

            if ($status === 'LEARNING') {
                // If the campaign received any optimisation edit in the last 3 days, suppress other agents.
                $recentEdit = Cache::get("fb_recent_edit:{$campaign->id}");
                if ($recentEdit) {
                    $holdKey = "fb_learning_hold:{$campaign->id}";
                    Cache::put($holdKey, true, now()->addHours(72));
                    $result['hold_applied'] = true;

                    $result['warnings'][] = [
                        'type'    => 'learning_reset',
                        'message' => "Campaign edit detected during learning phase — optimisation hold applied for 72h",
                    ];
                }

                // If still learning after 14 days, escalate
                $campaignAge = $campaign->created_at?->diffInDays(now()) ?? 0;
                if ($campaignAge >= 14) {
                    $result['issues'][] = [
                        'type'     => 'learning_stalled',
                        'severity' => 'high',
                        'message'  => "Campaign has been in Learning phase for {$campaignAge} days without graduating",
                        'fix'      => 'Check conversion event is firing, audience is not too narrow, and daily budget allows ≥50 events/week',
                    ];

                    $this->notifyStalled($campaign, $campaignAge, 'learning_too_long');
                }
            }

            if ($status === 'LEARNING_LIMITED') {
                // Stuck in limited learning — common causes: budget too low, audience overlap, too many ad sets
                $result['issues'][] = [
                    'type'     => 'learning_limited',
                    'severity' => 'high',
                    'message'  => 'Campaign is LEARNING_LIMITED: not receiving enough optimisation events',
                    'fix'      => 'Consolidate ad sets, increase daily budget, or broaden targeting to reach ≥50 events/week',
                ];

                // Suppress other agents mutating this campaign — edits reset learning
                Cache::put("fb_learning_hold:{$campaign->id}", true, now()->addHours(48));
                $result['hold_applied'] = true;

                $this->notifyStalled($campaign, 0, 'learning_limited');

                AgentActivity::record(
                    'facebook_learning',
                    'learning_limited_detected',
                    "Campaign \"{$campaign->name}\" is LEARNING_LIMITED — optimisation hold applied for 48h",
                    $campaign->customer_id,
                    $campaign->id,
                    ['fix' => 'Consolidate ad sets or increase budget to reach ≥50 conversion events/week']
                );
            }

        } catch (\Exception $e) {
            Log::warning("FacebookLearningPhaseAgent: Could not check learning phase for campaign {$campaign->id}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Returns true if other agents should skip mutations on this campaign.
     */
    public static function isOnHold(Campaign $campaign): bool
    {
        return (bool) Cache::get("fb_learning_hold:{$campaign->id}");
    }

    /**
     * Record that a mutation was made to this campaign (resets 72h learning hold window).
     */
    public static function recordEdit(Campaign $campaign): void
    {
        Cache::put("fb_recent_edit:{$campaign->id}", true, now()->addDays(3));
    }

    private function notifyStalled(Campaign $campaign, int $days, string $type): void
    {
        $cacheKey = "notif:fb_learning:{$type}:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $messages = [
            'learning_limited'  => "Campaign \"{$campaign->name}\" is stuck in Facebook LEARNING_LIMITED. Consolidate ad sets or increase budget to reach ≥50 events/week.",
            'learning_too_long' => "Campaign \"{$campaign->name}\" has been in Facebook learning phase for {$days} days. Check conversion event setup, audience breadth, and minimum budget.",
        ];

        $admins = \App\Models\User::where('is_admin', true)->get();
        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'facebook_learning',
                'Facebook Learning Phase Issue',
                $messages[$type],
                ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name, 'type' => $type]
            ));
        }

        Cache::put($cacheKey, true, now()->addHours(24));
    }
}
