<?php

namespace App\Services\Health;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class BillingHealthChecker
{
    use HealthCheckTrait;

    public function check(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            if ($customer->subscription) {
                $subscription = $customer->subscription;

                if ($subscription->stripe_status === 'past_due') {
                    $health['issues'][] = [
                        'type'     => 'payment',
                        'severity' => 'critical',
                        'message'  => 'Subscription payment is past due',
                        'details'  => 'Campaign delivery may be paused if not resolved',
                    ];
                } elseif ($subscription->stripe_status === 'incomplete') {
                    $health['warnings'][] = [
                        'type'     => 'payment',
                        'severity' => 'high',
                        'message'  => 'Subscription setup is incomplete',
                        'details'  => 'Complete setup to enable full features',
                    ];
                }
            }

            $adSpendCredits = $customer->adSpendCredits()
                ->where('expires_at', '>', now())
                ->where('remaining_amount', '>', 0)
                ->sum('remaining_amount');

            $health['metrics']['ad_spend_credits'] = $adSpendCredits;

            if ($adSpendCredits < 10) {
                $health['warnings'][] = [
                    'type'     => 'credits',
                    'severity' => 'medium',
                    'message'  => 'Low ad spend credits balance',
                    'details'  => "Current balance: \${$adSpendCredits}. Consider adding more credits.",
                ];
            }

            $recentFailures = $customer->adSpendTransactions()
                ->where('status', 'failed')
                ->where('created_at', '>', now()->subDays(7))
                ->count();

            if ($recentFailures > 0) {
                $health['warnings'][] = [
                    'type'     => 'payment_failures',
                    'severity' => 'high',
                    'message'  => 'Recent payment failures detected',
                    'details'  => "{$recentFailures} failed transaction(s) in the last 7 days",
                ];
            }

        } catch (\Exception $e) {
            Log::error("BillingHealthChecker: Error checking billing health", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }
}
