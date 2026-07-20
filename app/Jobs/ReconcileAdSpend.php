<?php

namespace App\Jobs;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\User;
use App\Notifications\AdSpendReconciliationAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Weekly ad-spend reconciliation (BILL-7).
 *
 * Compares each customer's cumulative platform ad spend against the credit-ledger
 * deductions over a trailing window and alerts admins when they diverge beyond
 * tolerance. Alert-only: no automated ledger correction — spend syncs can lag or
 * restate, so a human reviews and adjusts. The deduction window is shifted one day
 * later than the spend window because daily billing charges yesterday's spend.
 */
class ReconcileAdSpend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Trailing window length in days. */
    private const WINDOW_DAYS = 7;

    /** Alert only when the gap exceeds BOTH an absolute floor and a relative share. */
    private const ABS_THRESHOLD = 5.0;
    private const REL_THRESHOLD = 0.10;

    public function handle(): void
    {
        // Spend days that are finalised and already billed: [now-8, now-2].
        $spendStart = now()->subDays(self::WINDOW_DAYS + 1)->toDateString();
        $spendEnd   = now()->subDays(2)->toDateString();
        // Deductions lag spend by ~1 day (bill-yesterday cadence): [now-7, now-1].
        $dedStart = now()->subDays(self::WINDOW_DAYS)->startOfDay();
        $dedEnd   = now()->subDay()->endOfDay();
        $windowLabel = "{$spendStart} to {$spendEnd}";

        $discrepancies = [];

        Customer::whereHas('adSpendCredit')
            ->with('adSpendCredit')
            ->chunkById(100, function ($customers) use ($spendStart, $spendEnd, $dedStart, $dedEnd, &$discrepancies) {
                foreach ($customers as $customer) {
                    $campaignIds = $customer->campaigns()->pluck('id');
                    if ($campaignIds->isEmpty()) {
                        continue;
                    }

                    $platformSpend = $this->platformSpend($campaignIds, $spendStart, $spendEnd);
                    $deductions = $this->deductions($customer->adSpendCredit, $dedStart, $dedEnd);

                    // Nothing happened either side — not a discrepancy worth flagging.
                    if ($platformSpend <= 0 && $deductions <= 0) {
                        continue;
                    }

                    $discrepancy = abs($platformSpend - $deductions);
                    $basis = max($platformSpend, $deductions);
                    $relative = $basis > 0 ? $discrepancy / $basis : 0.0;

                    if ($discrepancy > self::ABS_THRESHOLD && $relative > self::REL_THRESHOLD) {
                        $entry = [
                            'customer_id'    => $customer->id,
                            'customer'       => $customer->name,
                            'currency'       => $customer->adSpendCredit->currency ?? $customer->billingCurrency(),
                            'platform_spend' => round($platformSpend, 2),
                            'deductions'     => round($deductions, 2),
                            'discrepancy'    => round($discrepancy, 2),
                            'relative'       => round($relative, 4),
                        ];
                        $discrepancies[] = $entry;

                        Log::error('ReconcileAdSpend: discrepancy detected', $entry);
                    }
                }
            });

        Log::info('ReconcileAdSpend: completed', [
            'window' => $windowLabel,
            'discrepancies' => count($discrepancies),
        ]);

        if (!empty($discrepancies)) {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
            foreach ($admins as $admin) {
                $admin->notify(new AdSpendReconciliationAlert($discrepancies, $windowLabel));
            }
        }
    }

    /** Sum finalized cost across all platform performance tables for the window. */
    private function platformSpend($campaignIds, string $start, string $end): float
    {
        $total = 0.0;
        foreach ([
            GoogleAdsPerformanceData::class,
            FacebookAdsPerformanceData::class,
            MicrosoftAdsPerformanceData::class,
            LinkedInAdsPerformanceData::class,
        ] as $model) {
            $total += (float) $model::whereIn('campaign_id', $campaignIds)
                ->whereBetween('date', [$start, $end])
                ->sum('cost');
        }
        return $total;
    }

    /** Sum absolute deductions from the credit ledger over the window. */
    private function deductions(?AdSpendCredit $credit, $start, $end): float
    {
        if (!$credit) {
            return 0.0;
        }
        return (float) abs(
            AdSpendTransaction::where('ad_spend_credit_id', $credit->id)
                ->where('type', 'deduction')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount')
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ReconcileAdSpend failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
