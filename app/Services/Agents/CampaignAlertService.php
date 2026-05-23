<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\AgentActivity;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CampaignAlertService
 *
 * Detects critical campaign events and sends real-time notifications.
 * Called from HourlyBudgetOptimization, AutomatedCampaignMaintenance, or standalone.
 *
 * Spend data design note:
 * The campaign_hourly_performance table stores CUMULATIVE daily spend snapshots —
 * each row contains "total spend from midnight to this hour", not just that hour's
 * spend. Callers must use MAX(spend) per day (the latest snapshot) to get the
 * actual daily total, never SUM(spend) across rows.
 */
class CampaignAlertService
{
    // How long (seconds) before the same alert type can fire again for a campaign.
    private const ALERT_COOLDOWN_SECONDS = 14400; // 4 hours

    /**
     * Run all alert checks for a campaign.
     */
    public function checkAlerts(Campaign $campaign): array
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->checkBudgetExhaustion($campaign));
        $alerts = array_merge($alerts, $this->checkConversionDrop($campaign));
        $alerts = array_merge($alerts, $this->checkSpendAnomaly($campaign));

        foreach ($alerts as $alert) {
            $this->sendAlert($campaign, $alert);
        }

        return $alerts;
    }

    /**
     * Check if campaign budget is exhausting too early in the day.
     * Fires at most once per cooldown window.
     */
    protected function checkBudgetExhaustion(Campaign $campaign): array
    {
        $currentHour = (int) now()->format('H');

        // Only meaningful before 2 PM — after that, running low is expected
        if ($currentHour >= 14) return [];

        $dailyBudget = $campaign->daily_budget ?? 0;
        if ($dailyBudget <= 0) return [];

        // Each hourly row holds the cumulative daily total at snapshot time.
        // MAX gives the most recent (highest) cumulative total = actual spend so far.
        $todaySpend = $this->getTodaySpend($campaign);

        $spendRatio = $todaySpend / $dailyBudget;
        if ($spendRatio < 0.8) return [];

        $alertType = 'budget_exhaustion';
        if ($this->isOnCooldown($campaign, $alertType)) return [];

        $percentSpent = round($spendRatio * 100);
        return [[
            'type'            => $alertType,
            'severity'        => 'critical',
            'title'           => "Budget {$percentSpent}% spent by {$currentHour}:00",
            'message'         => "Campaign \"{$campaign->name}\" has spent \$" . round($todaySpend, 2) . " ({$percentSpent}%) of its \${$dailyBudget} daily budget by {$currentHour}:00. It may run out before the evening peak.",
            'action_required' => 'Consider increasing the daily budget or adjusting bid strategy to spread spend more evenly.',
            'campaign_id'     => $campaign->id,
            'campaign_name'   => $campaign->name,
            'spend'           => round($todaySpend, 2),
            'budget'          => $dailyBudget,
        ]];
    }

    /**
     * Check for significant conversion drops vs the previous period.
     * Compares last 3 days vs the 3 days before that.
     * Fires at most once per cooldown window.
     */
    protected function checkConversionDrop(Campaign $campaign): array
    {
        // Conversions are not cumulative — each row is a per-hour count, so SUM is correct here
        $recentConversions = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(3)->toDateString(), now()->toDateString()])
            ->sum('conversions');

        $previousConversions = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(6)->toDateString(), now()->subDays(3)->toDateString()])
            ->sum('conversions');

        if ($previousConversions < 3) return [];

        $dropPercent = (($previousConversions - $recentConversions) / $previousConversions) * 100;
        if ($dropPercent < 50) return [];

        $alertType = 'conversion_drop';
        if ($this->isOnCooldown($campaign, $alertType)) return [];

        return [[
            'type'                 => $alertType,
            'severity'             => $dropPercent >= 80 ? 'critical' : 'warning',
            'title'                => "Conversions dropped " . round($dropPercent) . "% for \"{$campaign->name}\"",
            'message'              => "Campaign \"{$campaign->name}\" had {$recentConversions} conversions in the last 3 days vs {$previousConversions} in the prior 3 days — a " . round($dropPercent) . "% drop.",
            'action_required'      => 'Check for tracking issues, landing page changes, or market shifts. Review search terms and ad statuses.',
            'campaign_id'          => $campaign->id,
            'campaign_name'        => $campaign->name,
            'recent_conversions'   => $recentConversions,
            'previous_conversions' => $previousConversions,
            'drop_percent'         => round($dropPercent),
        ]];
    }

    /**
     * Check for spend anomalies — sudden spikes that could indicate runaway costs.
     * Fires at most once per cooldown window.
     */
    protected function checkSpendAnomaly(Campaign $campaign): array
    {
        // 7-day average: for each past day, take the MAX cumulative snapshot (= actual day total),
        // then average across days. This avoids summing cumulative rows which inflates the figure.
        $avgDailySpend = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->where('date', '<', now()->toDateString())
            ->selectRaw('date, MAX(spend) as daily_spend')
            ->groupBy('date')
            ->get()
            ->avg('daily_spend') ?? 0;

        if ($avgDailySpend <= 0) return [];

        // Today's spend: MAX cumulative snapshot so far (not the sum of all snapshots)
        $todaySpend = $this->getTodaySpend($campaign);

        // Only project if we have at least 2 hours of data to avoid early-morning noise
        $currentHour = max(2, (int) now()->format('H'));
        $projectedSpend = ($todaySpend / $currentHour) * 24;

        // Alert only if both the projection AND actual spend so far are meaningfully elevated
        if ($projectedSpend <= $avgDailySpend * 2) return [];
        if ($todaySpend <= 20) return [];

        $alertType = 'spend_anomaly';
        if ($this->isOnCooldown($campaign, $alertType)) return [];

        return [[
            'type'            => $alertType,
            'severity'        => 'warning',
            'title'           => "Unusual spend detected for \"{$campaign->name}\"",
            'message'         => "Campaign \"{$campaign->name}\" has spent \$" . round($todaySpend, 2) . " so far today and is projected to reach \$" . round($projectedSpend, 2) . " vs a \$" . round($avgDailySpend, 2) . " daily average (" . round(($projectedSpend / $avgDailySpend) * 100) . "% of normal).",
            'action_required' => 'Review bid strategy and check for competitive pressure driving up costs.',
            'campaign_id'     => $campaign->id,
            'campaign_name'   => $campaign->name,
            'projected_spend' => round($projectedSpend, 2),
            'avg_daily_spend' => round($avgDailySpend, 2),
            'today_spend'     => round($todaySpend, 2),
        ]];
    }

    /**
     * Get today's actual spend for a campaign.
     *
     * Each hourly row is a CUMULATIVE snapshot ("spend from midnight to this hour"),
     * so MAX gives the most recent snapshot = actual spend so far. SUM would count
     * each snapshot repeatedly and inflate the figure by the number of hours elapsed.
     */
    protected function getTodaySpend(Campaign $campaign): float
    {
        return (float) CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', now()->toDateString())
            ->max('spend') ?? 0;
    }

    /**
     * Returns true if this alert type was already sent for this campaign within the cooldown window.
     * Records the alert in the cache when returning false (i.e., first time in the window).
     */
    protected function isOnCooldown(Campaign $campaign, string $alertType): bool
    {
        $key = "campaign_alert:{$campaign->id}:{$alertType}";

        if (Cache::has($key)) {
            Log::debug("CampaignAlertService: Suppressed duplicate {$alertType} for campaign {$campaign->id} (cooldown active)");
            return true;
        }

        Cache::put($key, true, self::ALERT_COOLDOWN_SECONDS);
        return false;
    }

    /**
     * Send a critical alert notification to the campaign's customer users.
     */
    protected function sendAlert(Campaign $campaign, array $alert): void
    {
        try {
            $customer = $campaign->customer;
            if (!$customer) return;

            AgentActivity::record(
                'alert',
                $alert['type'],
                $alert['message'],
                $customer->id,
                $campaign->id,
                $alert
            );

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
                'type'        => $alert['type'],
                'campaign_id' => $campaign->id,
                'severity'    => $alert['severity'],
            ]);
        } catch (\Exception $e) {
            Log::error('CampaignAlertService: Failed to send alert', [
                'type'        => $alert['type'],
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
