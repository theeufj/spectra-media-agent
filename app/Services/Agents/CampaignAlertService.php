<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\Notification;
use App\Notifications\CriticalAgentAlert;
use App\Services\Health\CampaignBudgetPacing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CampaignAlertService
 *
 * Detects critical campaign events and sends real-time notifications.
 * Called from HourlyBudgetOptimization, AutomatedCampaignMaintenance, or standalone.
 *
 * Spend alerts compare verified complete account days at the current budget.
 * Incomplete hourly snapshots do not establish budget exhaustion or future costs.
 */
class CampaignAlertService
{
    // How long (seconds) before the same alert type can fire again for a campaign.
    private const ALERT_COOLDOWN_SECONDS = 14400; // 4 hours

    /** Policy state is independent of whether a campaign is intentionally paused. */
    public static function policyStatus(Campaign $campaign): ?array
    {
        $state = $campaign->getAttribute('policy_checks');

        return is_array($state) ? $state : null;
    }

    /**
     * Persist a verified platform read and its incident transition atomically.
     * Unknown retains the last evidence; only a successful verification clears it.
     */
    public function recordPolicyCheck(Campaign $campaign, string $platform, array $issues, ?string $uncertainReason = null, bool $readSucceeded = true): array
    {
        $state = DB::transaction(function () use ($campaign, $platform, $issues, $uncertainReason, $readSucceeded) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $state = self::policyStatus($locked) ?? ['platforms' => []];
            $previous = $state['platforms'][$platform] ?? [];
            $checkedAt = now()->toIso8601String();
            $status = $uncertainReason !== null ? 'unknown' : ($issues ? 'issues' : 'clear');
            $next = array_merge($previous, [
                'status' => $status, 'checked_at' => $checkedAt,
                'last_successful_checked_at' => $readSucceeded ? $checkedAt : ($previous['last_successful_checked_at'] ?? null),
                'issues' => $status === 'unknown' ? ($previous['issues'] ?? []) : $issues,
                'error' => $uncertainReason,
            ]);
            $event = null;

            if ($status === 'issues') {
                $fingerprint = $this->policyFingerprint($issues);
                $newIncident = empty($previous['issues']) || ($previous['fingerprint'] ?? null) !== $fingerprint;
                if ($newIncident) {
                    $next['incident_id'] = (string) Str::uuid();
                    $next['first_seen_at'] = $checkedAt;
                    $next['last_incident'] = ['id' => $next['incident_id'], 'issues' => $issues, 'first_seen_at' => $checkedAt, 'resolved_at' => null];
                    $event = $this->policyAlert($locked, $platform, 'policy_disapproved', $next['incident_id'], $issues);
                } else {
                    $next['last_incident']['issues'] = $issues;
                }
                $next['fingerprint'] = $fingerprint;
            } elseif ($status === 'clear' && ! empty($previous['issues'])) {
                $next['last_incident']['resolved_at'] = $checkedAt;
                $event = $this->policyAlert($locked, $platform, 'policy_recovered', $previous['incident_id'], $previous['issues']);
                $next['fingerprint'] = null;
            } elseif ($status === 'unknown' && ($previous['status'] ?? null) !== 'unknown') {
                $event = $this->policyAlert($locked, $platform, 'policy_check_unavailable', (string) Str::uuid(), $next['issues']);
            }

            if ($event) {
                $next['pending_alert'] = $event;
                AgentActivity::record('policy_monitor', $event['type'], $event['message'], $locked->customer_id, $locked->id, [
                    'platform' => $platform, 'status' => $status, 'issues' => $next['issues'], 'incident_id' => $event['dedupe_key'],
                ], $status === 'clear' ? 'completed' : 'needs_review');
                // The bell is durable even if the mail queue/provider is unavailable.
                foreach ($locked->customer ? $locked->customer->users : [] as $user) {
                    Notification::notify($user, $event['type'], $event['title'], $event['message'], $event['action_url'], 'Review campaign', $locked->customer, $event);
                }
            }

            $state['platforms'][$platform] = $next;
            $platforms = array_values($state['platforms']);
            $state['status'] = in_array('unknown', array_column($platforms, 'status'), true)
                ? 'unknown' : (in_array('issues', array_column($platforms, 'status'), true) ? 'issues' : 'clear');
            $state['checked_at'] = $checkedAt;
            $successful = array_filter(array_column($platforms, 'last_successful_checked_at'));
            $state['last_successful_checked_at'] = count($successful) === count($platforms) ? min($successful) : null;
            $state['issues'] = array_merge(...array_column($platforms, 'issues'));
            $state['repair_status'] = $state['status'] === 'unknown' ? 'verification_unavailable'
                : ($state['issues'] ? (in_array(true, array_column($state['issues'], 'destination_issue'), true) ? 'needs_website_repair' : 'needs_policy_review') : 'resolved');
            $incidents = array_filter(array_column($platforms, 'last_incident'));
            usort($incidents, fn ($a, $b) => strcmp($b['first_seen_at'], $a['first_seen_at']));
            $state['last_incident'] = $incidents[0] ?? null;
            $locked->update(['policy_checks' => $state]);

            return $state;
        });

        $pending = $state['platforms'][$platform]['pending_alert'] ?? null;
        if ($pending && $campaign->customer) {
            try {
                CriticalAgentAlert::deliver($pending['type'], $pending['title'], $pending['message'], $pending, CriticalAgentAlert::RECIPIENTS_CUSTOMERS, $campaign->customer);
                DB::transaction(function () use ($campaign, $platform, $pending) {
                    $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                    $current = self::policyStatus($locked);
                    if (($current['platforms'][$platform]['pending_alert']['dedupe_key'] ?? null) === $pending['dedupe_key']) {
                        unset($current['platforms'][$platform]['pending_alert']);
                        $locked->update(['policy_checks' => $current]);
                    }
                });
            } catch (\Throwable $e) {
                report($e);
                Log::error('CampaignAlertService: policy mail could not be queued', ['campaign_id' => $campaign->id, 'platform' => $platform, 'error' => $e->getMessage()]);
            }
        }
        $campaign->refresh();

        return self::policyStatus($campaign) ?? $state;
    }

    private function policyFingerprint(array $issues): string
    {
        // Google's latest check timestamp changes each scan; it is not a new incident.
        $keys = array_map(function ($issue) {
            $topics = array_map(fn ($topic) => ['topic' => $topic['topic'] ?? null, 'evidences' => array_map(fn ($evidence) => array_intersect_key($evidence, array_flip(['type', 'expanded_url', 'device', 'http_error_code', 'dns_error_type'])), $topic['evidences'] ?? [])], $issue['policy_topics'] ?? []);

            return json_encode([$issue['ad_resource_name'] ?? null, $issue['final_urls'] ?? [], $topics], JSON_THROW_ON_ERROR);
        }, $issues);
        sort($keys);

        return hash('sha256', implode('|', $keys));
    }

    private function policyAlert(Campaign $campaign, string $platform, string $type, string $incident, array $issues): array
    {
        $name = $platform === 'google_ads' ? 'Google Ads' : 'Facebook Ads';
        $message = match ($type) {
            'policy_recovered' => $name.' reports that the previously disapproved ads in "'.$campaign->name.'" are no longer rejected. This does not resume the campaign.',
            'policy_check_unavailable' => 'The latest '.$name.' approval check for "'.$campaign->name.'" could not be confirmed. The last known policy evidence is retained.',
            default => $name.' disapproved an ad in "'.$campaign->name.'": '.implode('; ', array_column($issues, 'message')),
        };

        return [
            'type' => $type, 'title' => $type === 'policy_recovered' ? 'Ad policy issue cleared' : ($type === 'policy_check_unavailable' ? 'Ad approval check unavailable' : 'Ad approval needs attention'),
            'message' => $message, 'campaign_id' => $campaign->id, 'campaign_name' => $campaign->name, 'customer_id' => $campaign->customer_id,
            'platform' => $platform, 'issues' => $issues, 'severity' => $type === 'policy_recovered' ? 'info' : 'critical',
            'action_url' => route('campaigns.show', $campaign), 'dedupe_key' => $platform.':'.$type.':'.$incident,
            'action_required' => $type === 'policy_disapproved'
                ? (in_array(true, array_column($issues, 'destination_issue'), true) ? 'Fix the landing-page availability with your website host, then request a review in the ad platform. The campaign remains in its current state.' : 'Review the reported policy issue and request a review in the ad platform.')
                : 'Open the campaign to review the latest verified policy status.',
        ];
    }

    /**
     * Run all alert checks for a campaign.
     */
    public function checkAlerts(Campaign $campaign): array
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->checkBudgetPacing($campaign));
        $alerts = array_merge($alerts, $this->checkConversionDrop($campaign));

        foreach ($alerts as $alert) {
            $this->sendAlert($campaign, $alert);
        }

        return $alerts;
    }

    /**
     * A verified average-budget pace is a review signal, not a daily spend cap.
     */
    protected function checkBudgetPacing(Campaign $campaign): array
    {
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        if ($pacing['status'] !== 'ready' || $pacing['pacing_ratio'] <= 1.2) {
            return [];
        }
        $alertType = 'budget_overpacing';
        if ($this->isOnCooldown($campaign, $alertType)) {
            return [];
        }
        $pacingPercent = round($pacing['pacing_ratio'] * 100);

        return [[
            'type' => $alertType,
            'severity' => 'warning',
            'title' => 'Budget pace needs review for "'.$campaign->name.'"',
            'message' => 'Campaign "'.$campaign->name.'" is at '.$pacingPercent.'% of its current average-budget pace. '
                .CampaignBudgetPacing::summary($pacing),
            'action_required' => 'Review the reported spend, delivery and approved spending limits together. This alert does not change the budget, bids or campaign status.',
            'action_url' => route('campaigns.show', $campaign),
            'action_label' => 'Review campaign spending',
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'pacing_pct' => $pacingPercent,
            'actual_spend' => CampaignBudgetPacing::amount($pacing['actual_spend_micros'], $pacing['currency_code']),
            'expected_spend' => CampaignBudgetPacing::amount($pacing['expected_spend_micros'], $pacing['currency_code']),
            'currency_code' => $pacing['currency_code'],
            'window_from' => $pacing['from'],
            'window_through' => $pacing['through'],
            'pacing' => $pacing,
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

        if ($previousConversions < 3) {
            return [];
        }

        $dropPercent = (($previousConversions - $recentConversions) / $previousConversions) * 100;
        if ($dropPercent < 50) {
            return [];
        }

        $alertType = 'conversion_drop';
        if ($this->isOnCooldown($campaign, $alertType)) {
            return [];
        }

        return [[
            'type' => $alertType,
            'severity' => $dropPercent >= 80 ? 'critical' : 'warning',
            'title' => 'Conversions dropped '.round($dropPercent)."% for \"{$campaign->name}\"",
            'message' => "Campaign \"{$campaign->name}\" had {$recentConversions} conversions in the last 3 days vs {$previousConversions} in the prior 3 days — a ".round($dropPercent).'% drop.',
            'action_required' => 'Check for tracking issues, landing page changes, or market shifts. Review search terms and ad statuses.',
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'recent_conversions' => $recentConversions,
            'previous_conversions' => $previousConversions,
            'drop_percent' => round($dropPercent),
        ]];
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
            if (! $customer) {
                return;
            }

            AgentActivity::record(
                'alert',
                $alert['type'],
                $alert['message'],
                $customer->id,
                $campaign->id,
                $alert
            );

            CriticalAgentAlert::deliver(
                $alert['type'],
                $alert['title'],
                $alert['message'],
                $alert,
                CriticalAgentAlert::RECIPIENTS_CUSTOMERS,
                $customer
            );

            Log::info('CampaignAlertService: Alert sent', [
                'type' => $alert['type'],
                'campaign_id' => $campaign->id,
                'severity' => $alert['severity'],
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('CampaignAlertService: Failed to send alert', [
                'type' => $alert['type'],
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
