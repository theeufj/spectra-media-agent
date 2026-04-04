<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CampaignHourlyPerformance;
use App\Models\AgentActivity;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Support\Facades\Log;

/**
 * CampaignAlertService
 *
 * Detects critical campaign events and sends real-time notifications.
 * Called from HourlyBudgetOptimization, AutomatedCampaignMaintenance, or standalone.
 */
class CampaignAlertService
{
    /**
     * Run all alert checks for a campaign.
     */
    public function checkAlerts(Campaign $campaign): array
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->checkBudgetExhaustion($campaign));
        $alerts = array_merge($alerts, $this->checkConversionDrop($campaign));
        $alerts = array_merge($alerts, $this->checkSpendAnomaly($campaign));

        // Send notifications for any critical alerts
        foreach ($alerts as $alert) {
            $this->sendAlert($campaign, $alert);
        }

        return $alerts;
    }

    /**
     * Check if campaign budget is exhausting too early in the day.
     * If 80%+ of daily budget is spent before 2 PM, alert.
     */
    protected function checkBudgetExhaustion(Campaign $campaign): array
    {
        $alerts = [];
        $currentHour = (int) now()->format('H');

        // Only check during business hours (before 2 PM)
        if ($currentHour >= 14) return [];

        $dailyBudget = $campaign->daily_budget ?? 0;
        if ($dailyBudget <= 0) return [];

        // Get today's spend so far
        $todaySpend = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', now()->toDateString())
            ->sum('spend');

        $spendRatio = $todaySpend / $dailyBudget;
        if ($spendRatio >= 0.8) {
            $percentSpent = round($spendRatio * 100);
            $alerts[] = [
                'type' => 'budget_exhaustion',
                'severity' => 'critical',
                'title' => "Budget {$percentSpent}% spent by {$currentHour}:00",
                'message' => "Campaign \"{$campaign->name}\" has spent {$percentSpent}% of its \${$dailyBudget} daily budget by {$currentHour}:00. It may run out of budget before the evening peak.",
                'action_required' => 'Consider increasing the daily budget or adjusting bid strategy to spread spend more evenly.',
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'spend' => $todaySpend,
                'budget' => $dailyBudget,
            ];
        }

        return $alerts;
    }

    /**
     * Check for significant conversion drops vs the previous period.
     * Compares last 3 days vs the 3 days before that.
     */
    protected function checkConversionDrop(Campaign $campaign): array
    {
        $alerts = [];

        $recentConversions = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(3)->toDateString(), now()->toDateString()])
            ->sum('conversions');

        $previousConversions = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(6)->toDateString(), now()->subDays(3)->toDateString()])
            ->sum('conversions');

        // Need baseline data to compare
        if ($previousConversions < 3) return [];

        $dropPercent = (($previousConversions - $recentConversions) / $previousConversions) * 100;

        if ($dropPercent >= 50) {
            $alerts[] = [
                'type' => 'conversion_drop',
                'severity' => $dropPercent >= 80 ? 'critical' : 'warning',
                'title' => "Conversions dropped " . round($dropPercent) . "% for \"{$campaign->name}\"",
                'message' => "Campaign \"{$campaign->name}\" had {$recentConversions} conversions in the last 3 days vs {$previousConversions} in the prior 3 days — a " . round($dropPercent) . "% drop.",
                'action_required' => 'Check for tracking issues, landing page changes, or market shifts. Review search terms and ad statuses.',
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'recent_conversions' => $recentConversions,
                'previous_conversions' => $previousConversions,
                'drop_percent' => round($dropPercent),
            ];
        }

        return $alerts;
    }

    /**
     * Check for spend anomalies — sudden spending spikes that could indicate runaway costs.
     */
    protected function checkSpendAnomaly(Campaign $campaign): array
    {
        $alerts = [];

        // Get average daily spend over last 7 days
        $avgDailySpend = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->where('date', '<', now()->toDateString())
            ->selectRaw('date, SUM(spend) as daily_spend')
            ->groupBy('date')
            ->get()
            ->avg('daily_spend') ?? 0;

        if ($avgDailySpend <= 0) return [];

        // Get today's spend so far
        $todaySpend = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', now()->toDateString())
            ->sum('spend');

        // Project today's total based on hours elapsed
        $currentHour = max(1, (int) now()->format('H'));
        $projectedSpend = ($todaySpend / $currentHour) * 24;

        // Alert if projected spend is more than 2x average
        if ($projectedSpend > $avgDailySpend * 2 && $todaySpend > 20) {
            $alerts[] = [
                'type' => 'spend_anomaly',
                'severity' => 'warning',
                'title' => "Unusual spend detected for \"{$campaign->name}\"",
                'message' => "Campaign \"{$campaign->name}\" is on track to spend \$" . round($projectedSpend, 2) . " today vs a \$" . round($avgDailySpend, 2) . " daily average — " . round(($projectedSpend / $avgDailySpend) * 100) . "% of normal.",
                'action_required' => 'Review bid strategy and check for competitive pressure driving up costs.',
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'projected_spend' => round($projectedSpend, 2),
                'avg_daily_spend' => round($avgDailySpend, 2),
            ];
        }

        return $alerts;
    }

    /**
     * Send a critical alert notification to the campaign's customer users.
     */
    protected function sendAlert(Campaign $campaign, array $alert): void
    {
        try {
            $customer = $campaign->customer;
            if (!$customer) return;

            // Log as agent activity
            AgentActivity::record(
                'alert',
                $alert['type'],
                $alert['message'],
                $customer->id,
                $campaign->id,
                $alert
            );

            // Notify all users attached to this customer
            $notification = new CriticalAgentAlert(
                $alert['type'],
                $alert['title'],
                $alert['message'],
                $alert
            );

            foreach ($customer->users as $user) {
                $user->notify($notification);
            }

            Log::info('CampaignAlertService: Alert sent', [
                'type' => $alert['type'],
                'campaign_id' => $campaign->id,
                'severity' => $alert['severity'],
            ]);
        } catch (\Exception $e) {
            Log::error('CampaignAlertService: Failed to send alert', [
                'type' => $alert['type'],
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
