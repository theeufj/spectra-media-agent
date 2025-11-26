<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        // Get all customers with active credit accounts
        $customers = Customer::whereHas('adSpendCredit')
            ->whereHas('campaigns', function ($query) {
                $query->whereIn('status', ['active', 'paused']);
            })
            ->with(['adSpendCredit', 'campaigns'])
            ->get();

        foreach ($customers as $customer) {
            try {
                $result = $billingService->processDailyBilling($customer);
                
                $results['processed']++;
                
                if ($result['success']) {
                    $results['successful']++;
                    $results['total_spend'] += $result['actual_spend'];
                } else {
                    $results['failed']++;
                }

                Log::info('ProcessDailyAdSpendBilling: Processed customer', [
                    'customer_id' => $customer->id,
                    'result' => $result,
                ]);

            } catch (\Exception $e) {
                $results['failed']++;
                
                Log::error('ProcessDailyAdSpendBilling: Customer billing failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessDailyAdSpendBilling: Completed daily billing run', $results);
    }
}
