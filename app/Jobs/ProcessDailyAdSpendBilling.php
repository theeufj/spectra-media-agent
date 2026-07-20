<?php

namespace App\Jobs;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ProcessDailyAdSpendBilling
 * 
 * Scheduled job that runs daily to:
 * 1. Calculate actual ad spend from yesterday for each customer
 * 2. Deduct from their credit balance
 * 3. Auto-replenish if balance is low
 * 4. Handle failed payments with grace period → pause flow
 * 
 * Schedule: Daily at 6 AM (after ad networks finalize yesterday's spend)
 */
class ProcessDailyAdSpendBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 300; // 5 minutes between retries

    /**
     * Execute the job.
     */
    public function handle(AdSpendBillingService $billingService): void
    {
        Log::info('ProcessDailyAdSpendBilling: Starting daily billing run');

        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_spend' => 0,
        ];

        // Bill every customer with a credit account that either has a running campaign
        // OR whose credit is in a paused/failed/grace state — the latter would otherwise
        // never reach the recovery path once their campaigns are paused (they have no
        // 'active' campaigns left), stranding them permanently. (BILL-2)
        $recoverableStatuses = [
            AdSpendCredit::PAYMENT_PAUSED,
            AdSpendCredit::PAYMENT_FAILED,
            AdSpendCredit::PAYMENT_GRACE_PERIOD,
        ];

        $customers = Customer::whereHas('adSpendCredit')
            ->where(function ($query) use ($recoverableStatuses) {
                $query->whereHas('campaigns', function ($q) {
                        $q->where('status', 'active');
                    })
                    ->orWhereHas('adSpendCredit', function ($q) use ($recoverableStatuses) {
                        $q->whereIn('payment_status', $recoverableStatuses);
                    });
            })
            ->with(['adSpendCredit', 'campaigns'])
            ->get();

        // Idempotency: bill each customer at most once per calendar day. On a mid-run
        // crash + retry (tries=3) this stops already-charged customers being re-deducted
        // and re-charged. Cache::add is atomic; the marker is cleared on failure so a
        // failed customer is retried. (BILL-3)
        $billingDate = now()->toDateString();

        foreach ($customers as $customer) {
            $marker = "adspend_billed:{$customer->id}:{$billingDate}";

            if (!Cache::add($marker, true, now()->addHours(47))) {
                $results['skipped']++;
                Log::info('ProcessDailyAdSpendBilling: Skipping already-billed customer', [
                    'customer_id' => $customer->id,
                    'billing_date' => $billingDate,
                ]);
                continue;
            }

            try {
                $result = $billingService->processDailyBilling($customer);

                $results['processed']++;

                if ($result['success']) {
                    $results['successful']++;
                    $results['total_spend'] += $result['actual_spend'];
                } else {
                    $results['failed']++;
                    // A failed charge is an expected business outcome (grace/pause flow),
                    // not a reason to re-bill — keep the marker so we don't double-charge.
                }

                Log::info('ProcessDailyAdSpendBilling: Processed customer', [
                    'customer_id' => $customer->id,
                    'result' => $result,
                ]);

            } catch (\Exception $e) {
                $results['failed']++;

                // Unexpected failure — release the marker so a retry reprocesses this customer.
                Cache::forget($marker);

                Log::error('ProcessDailyAdSpendBilling: Customer billing failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessDailyAdSpendBilling: Completed daily billing run', $results);

        // Surface partial failures — per-customer errors are caught above, so onFailure()
        // would otherwise never fire and a customer failing every day would go unnoticed.
        if ($results['failed'] > 0) {
            Log::error('ProcessDailyAdSpendBilling: Completed with failures', $results);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDailyAdSpendBilling failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
