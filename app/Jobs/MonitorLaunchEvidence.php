<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Recommendation;
use App\Models\Strategy;
use App\Notifications\CriticalAgentAlert;
use App\Services\Campaigns\CampaignResourceInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MonitorLaunchEvidence implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $issues = [];
        Strategy::whereNotNull('launch_intent')->with('campaign.customer', 'campaign.strategies')->eachById(function (Strategy $strategy) use (&$issues) {
            try {
                $campaign = $strategy->campaign;
                if (! $campaign || $campaign->spend_safety_hold || $campaign->customer?->service_type === 'setup_only') {
                    return;
                }
                $id = $strategy->launch_intent['id'] ?? null;
                $hold = $id ? DB::table('launch_funding_reservations')->where('launch_id', $id)
                    ->where('strategy_id', $strategy->id)->where('status', 'held')->first() : null;
                if (! $hold || now()->parse($hold->created_at)->greaterThan(now()->subHours(24))) {
                    return;
                }
                $entries = collect(CampaignResourceInventory::entries($campaign))->where('strategy_id', $strategy->id);
                if ($entries->isEmpty()) {
                    $issues[] = [$campaign->id, $strategy->id, 'funded_launch_stalled', 'A funded channel has no confirmed platform campaign after 24 hours.'];

                    return;
                }
                $start = max(now()->parse($hold->created_at), $strategy->deployed_at ?? now()->parse($hold->created_at),
                    now()->parse($campaign->getRawOriginal('start_date') ?? $hold->created_at));
                if (in_array($strategy->deployment_status, ['failed', 'deploying', 'queued', 'deploy_unverified'], true)) {
                    $issues[] = [$campaign->id, $strategy->id, 'funded_channel_unconfirmed', 'A funded channel has a platform campaign but its deployment has not been confirmed.'];

                    return;
                }
                if ($strategy->deployment_status !== 'active' || $start->greaterThan(now()->subDays(2))) {
                    return;
                }
                $platform = $hold->platform;
                $table = $platform === 'google' ? 'google_ads_performance_data' : 'facebook_ads_performance_data';
                if (! DB::table($table)->where('campaign_id', $campaign->id)->whereIn($platform.'_campaign_id', $entries->pluck('resource_id'))
                    ->where('date', '>=', $start->toDateString())->where('impressions', '>', 0)->exists()) {
                    $issues[] = [$campaign->id, $strategy->id, 'first_impressions_missing', 'Check delivery and reporting for this funded channel.'];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
        $pending = DB::table('campaign_outcome_events')->join('strategies', 'strategies.id', '=', 'campaign_outcome_events.strategy_id')
            ->where(function ($query) {
                $query->where('delivery_status', 'failed')
                    ->orWhere(function ($old) {
                        $old->where('campaign_outcome_events.created_at', '<', now()->subHours(24))
                            ->whereIn('delivery_status', ['pending', 'retry', 'submitted', 'awaiting_evidence']);
                    });
            })
            ->groupBy('strategies.id', 'strategies.campaign_id')->select('strategies.id', 'strategies.campaign_id')->get();
        foreach ($pending as $row) {
            $issues[] = [$row->campaign_id, $row->id, 'conversion_receipt_overdue', 'Check the destination and provider delivery receipt.'];
        }
        Recommendation::where('status', 'failed')->where('updated_at', '>=', now()->subDay())->eachById(function ($rec) use (&$issues) {
            $issues[] = [$rec->campaign_id, $rec->target_entity['strategy_id'] ?? null, 'platform_change_failed', 'Review the failed platform change and its readback.'];
        });
        foreach ($issues as [$campaignId, $strategyId, $code, $message]) {
            $campaign = Campaign::find($campaignId);
            if (! $campaign || ! Cache::add("channel-alert:{$campaignId}:{$strategyId}:{$code}", true, now()->addDay())) {
                continue;
            }
            AgentActivity::record('deployment', $code, $message, $campaign->customer_id, $campaignId,
                ['strategy_id' => $strategyId, 'owner' => 'Site to Spend'], 'needs_review');
            CriticalAgentAlert::deliver($code, 'Campaign needs review', $message,
                ['campaign_id' => $campaignId, 'strategy_id' => $strategyId], CriticalAgentAlert::RECIPIENTS_ADMINS);
        }
    }
}
