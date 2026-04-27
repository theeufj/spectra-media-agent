<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Agents\PerformanceAnomalyAlertAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs PerformanceAnomalyAlertAgent across all active customers every 4 hours.
 * Scheduled via app/Console/Kernel.php.
 *
 * Detects intra-day anomalies (CTR drops, CPC spikes, CVR drops, zero delivery)
 * and sends CriticalAgentAlerts with AI-generated explanations before the full
 * day's budget is wasted.
 */
class RunPerformanceAnomalyCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600; // 10 minutes

    public function handle(PerformanceAnomalyAlertAgent $agent): void
    {
        Log::info("RunPerformanceAnomalyCheck: Starting anomaly check");

        $customers = Customer::whereHas('campaigns', fn($q) => $q->withDeployedPlatforms())->get();

        $totalAlerts = 0;

        foreach ($customers as $customer) {
            try {
                $alerts = $agent->runForCustomer($customer);
                $totalAlerts += count($alerts);
            } catch (\Exception $e) {
                Log::error("RunPerformanceAnomalyCheck: Error for customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info("RunPerformanceAnomalyCheck: Completed", [
            'customers_checked' => $customers->count(),
            'alerts_sent'       => $totalAlerts,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("RunPerformanceAnomalyCheck: Job failed: " . $e->getMessage());
    }
}
