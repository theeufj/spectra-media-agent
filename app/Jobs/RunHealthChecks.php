<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use App\Services\Agents\HealthCheckAgent;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * RunHealthChecks Job
 * 
 * Scheduled job that performs proactive health monitoring on all customer
 * ad accounts and campaigns. Detects issues early and sends alerts.
 * 
 * Run Schedule: Every 6 hours (recommended)
 * 
 * Checks Performed:
 * - API connectivity (Google Ads, Facebook Ads)
 * - Token validity and expiration
 * - Campaign delivery issues
 * - Budget pacing anomalies
 * - Ad approval status
 * - Billing health
 */
class RunHealthChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The backoff times in seconds between retries.
     */
    public array $backoff = [60, 300];

    /**
     * The maximum time in seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * Execute the job.
     */
    public function handle(HealthCheckAgent $healthCheckAgent): void
    {
        Log::info("RunHealthChecks: Starting health check run");

        // Get all customers with active subscriptions or active campaigns
        $customers = Customer::query()
            ->whereHas('campaigns', function ($q) {
                $q->withDeployedPlatforms();
            })
            ->get();

        $summary = [
            'customers_checked' => 0,
            'healthy' => 0,
            'warnings' => 0,
            'unhealthy' => 0,
            'critical' => 0,
            'alerts_sent' => 0,
            'errors' => [],
        ];

        foreach ($customers as $customer) {
            try {
                Log::info("RunHealthChecks: Checking customer health", [
                    'customer_id' => $customer->id,
                ]);

                $results = $healthCheckAgent->checkCustomerHealth($customer);
                $summary['customers_checked']++;

                // Categorize by overall health
                match ($results['overall_health']) {
                    'healthy' => $summary['healthy']++,
                    'warning' => $summary['warnings']++,
                    'unhealthy' => $summary['unhealthy']++,
                    'critical' => $summary['critical']++,
                    default => null,
                };

                // Send alert if severe issues detected.
                if (in_array($results['overall_health'], ['critical', 'unhealthy'], true)) {
                    $this->sendHealthAlert($customer, $results);
                    $summary['alerts_sent']++;
                }

                // Store results for dashboard display
                $this->storeHealthResults($customer, $results);

            } catch (\Exception $e) {
                Log::error("RunHealthChecks: Error checking customer {$customer->id}", [
                    'error' => $e->getMessage(),
                ]);
                $summary['errors'][] = "Customer {$customer->id}: " . $e->getMessage();
            }
        }

        // Store run summary
        Cache::put('health_check:last_run', [
            'timestamp' => now()->toIso8601String(),
            'summary' => $summary,
        ], now()->addDay());

        Log::info("RunHealthChecks: Completed health check run", $summary);

        // Send daily summary to admins if there are issues
        if ($summary['critical'] > 0 || $summary['unhealthy'] > 0) {
            $this->sendAdminSummary($summary);
        }
    }

    /**
     * Send critical alert for immediate attention.
     */
    protected function sendHealthAlert(Customer $customer, array $results): void
    {
        try {
            $isCritical = $results['overall_health'] === 'critical';
            $title = 'Automated Campaign Fixes Applied';
            $message = 'We detected an issue with your campaigns and have fixed them automatically.';
            $notificationType = $isCritical
                ? Notification::TYPE_HEALTH_CRITICAL
                : Notification::TYPE_HEALTH_WARNING;
            $details = [
                'severity' => $results['overall_health'],
                'customer_id' => $customer->id,
                'issues' => $results['issues'] ?? [],
                'warnings' => $results['warnings'] ?? [],
            ];

            foreach ($customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'health_check_critical',
                    $title,
                    $message,
                    $details
                ));

                Notification::notify(
                    $user,
                    $notificationType,
                    $title,
                    $message,
                    url('/dashboard'),
                    'Review Health Status',
                    $customer,
                    $details
                );
            }

            Log::info("RunHealthChecks: Health alert queued", [
                'customer_id' => $customer->id,
                'overall_health' => $results['overall_health'],
                'recipients' => $customer->users->pluck('email')->filter()->values()->all(),
                'issues_count' => count($results['issues'] ?? []),
            ]);
        } catch (\Exception $e) {
            Log::error("RunHealthChecks: Failed to send health alert", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store health results for dashboard access.
     */
    protected function storeHealthResults(Customer $customer, array $results): void
    {
        // Store in database or cache for dashboard display
        Cache::put(
            "health_check:customer:{$customer->id}:latest",
            $results,
            now()->addDay()
        );

        // Store health history for trending
        $historyKey = "health_check:customer:{$customer->id}:history";
        $history = Cache::get($historyKey, []);
        
        // Keep last 30 days of history
        $history[] = [
            'timestamp' => now()->toIso8601String(),
            'overall_health' => $results['overall_health'],
            'issues_count' => count($results['issues'] ?? []),
            'warnings_count' => count($results['warnings'] ?? []),
        ];
        
        // Trim to last 30 entries
        if (count($history) > 30) {
            $history = array_slice($history, -30);
        }
        
        Cache::put($historyKey, $history, now()->addDays(31));
    }

    /**
     * Send daily summary to admin users.
     */
    protected function sendAdminSummary(array $summary): void
    {
        $admins = User::where('is_admin', true)->get();

        if ($admins->isEmpty()) {
            Log::warning('RunHealthChecks: No admin users found for health summary notification');
            return;
        }

        $message = "Health check found {$summary['critical']} critical and {$summary['unhealthy']} unhealthy accounts across {$summary['total_customers']} customers.";

        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'health_check_summary',
                'Platform Health Alert',
                $message,
                $summary
            ));
        }

        Log::info('RunHealthChecks: Admin summary sent', [
            'recipients' => $admins->pluck('email')->all(),
            'critical' => $summary['critical'],
            'unhealthy' => $summary['unhealthy'],
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("RunHealthChecks: Job failed", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
