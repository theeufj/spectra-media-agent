<?php

namespace App\Services\Health;

use App\Models\AdSpendTransaction;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class BillingHealthChecker
{
    use HealthCheckTrait;

    public function check(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            // Through the owning user. Customer is not Billable and has no
            // `subscription` relation, so `$customer->subscription` was simply
            // null under non-strict mode and this whole block never ran — the
            // one checker that exists to surface a past_due subscription never
            // surfaced one.
            $owner = $customer->users()->wherePivot('role', 'owner')->first()
                ?? $customer->users()->first();

            $subscription = $owner?->subscription('default');

            if ($subscription) {
                if ($subscription->stripe_status === 'past_due') {
                    $health['issues'][] = [
                        'type' => 'payment',
                        'severity' => 'critical',
                        'message' => 'Subscription payment is past due',
                        'details' => 'Campaign delivery may be paused if not resolved',
                    ];
                } elseif ($subscription->stripe_status === 'incomplete') {
                    $health['warnings'][] = [
                        'type' => 'payment',
                        'severity' => 'high',
                        'message' => 'Subscription setup is incomplete',
                        'details' => 'Complete setup to enable full features',
                    ];
                }
            }

            // adSpendCredit is a hasOne relation — read the balance directly
            // from it. This read `remaining_amount`, an attribute that exists
            // nowhere on the model, so every customer resolved to $0 and a
            // customer holding $5,000 warned about a low balance forever.
            $credit = $customer->adSpendCredit;
            $adSpendCredits = $credit ? round((float) $credit->current_balance, 2) : 0.0;

            $health['metrics']['ad_spend_credits'] = $adSpendCredits;

            // Only an account that prepays ad spend can have a low balance. A
            // self-funded account (Google bills their card directly) has no
            // credit row at all, and $0 is not something they can act on.
            if ($credit && $adSpendCredits < 10) {
                $health['warnings'][] = [
                    'type' => 'credits',
                    'severity' => 'medium',
                    'message' => 'Low ad spend credits balance',
                    'details' => 'Current balance: $'.number_format($adSpendCredits, 2).'. Consider adding more credits.',
                ];
            }

            // Refunds only. This counted every row with a negative amount, and
            // deduct() writes -$amount for each ordinary daily charge — so a
            // customer being billed perfectly normally collected one spurious
            // high-severity "credit reversals detected" warning per run.
            $recentReversals = $customer->adSpendTransactions()
                ->where('ad_spend_transactions.type', AdSpendTransaction::TYPE_REFUND)
                ->where('ad_spend_transactions.created_at', '>', now()->subDays(7))
                ->count();

            if ($recentReversals > 0) {
                $health['warnings'][] = [
                    'type' => 'payment_failures',
                    'severity' => 'high',
                    'message' => 'Recent credit reversals detected',
                    'details' => "{$recentReversals} reversal(s) in the last 7 days",
                ];
            }

        } catch (\Throwable $e) {
            report($e);
            Log::error('BillingHealthChecker: Error checking billing health', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);

        return $health;
    }
}
