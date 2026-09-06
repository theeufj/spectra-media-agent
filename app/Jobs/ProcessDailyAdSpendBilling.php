<?php

namespace App\Jobs;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\AdSpendBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
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

        // Selection has to follow the platform, not our lifecycle field.
        //
        // A campaign spends money because Google says ENABLED, not because our
        // `status` column says active. Those two drifted (BILL-8) and this query
        // only looked at the local one, so a campaign live on the platform but
        // not locally active spent without ever being billed — silently, because
        // finding nobody to bill looks exactly like having nobody to bill.
        $customers = $this->eligibleCustomers($recoverableStatuses);

        // Idempotency: bill each customer at most once per calendar day. On a mid-run
        // crash + retry (tries=3) this stops already-charged customers being re-deducted
        // and re-charged. The claim is released on failure so a failed customer is
        // retried. (BILL-3)
        //
        // This was a Cache::add() marker — the only thing standing between a
        // customer and a second day's billing, held somewhere a Redis flush,
        // failover, or an allkeys-lru eviction could silently drop it. The
        // unique index on ad_spend_billing_runs cannot be evicted.
        $billingDate = now()->toDateString();

        foreach ($customers as $customer) {
            if (! $this->claimBilling($customer, $billingDate)) {
                $results['skipped']++;
                Log::info('ProcessDailyAdSpendBilling: Skipping already-billed customer', [
                    'customer_id' => $customer->id,
                    'billing_date' => $billingDate,
                ]);

                continue;
            }

            // Queue workers have no session, so the exception reporter cannot
            // work out who a failure belongs to. Say so explicitly for the
            // duration of this customer's billing.
            Context::add('customer_id', $customer->id);

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

            } catch (\Throwable $e) {
                // \Throwable, not \Exception. The claim is taken before this try,
                // so an \Error escaping the guard aborted the rest of the run and
                // left this customer claimed but unbilled — skipped on retry, and
                // therefore never billed for that day at all.
                //
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                $results['failed']++;

                // Unexpected failure — release the claim so a retry reprocesses this customer.
                $this->releaseBilling($customer, $billingDate);

                Log::error('ProcessDailyAdSpendBilling: Customer billing failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Context::forget('customer_id');
            }
        }

        Log::info('ProcessDailyAdSpendBilling: Completed daily billing run', $results);

        // Surface partial failures — per-customer errors are caught above, so onFailure()
        // would otherwise never fire and a customer failing every day would go unnoticed.
        if ($results['failed'] > 0) {
            Log::error('ProcessDailyAdSpendBilling: Completed with failures', $results);
        }

        // Billing nobody is only good news if there was nobody to bill.
        //
        // This job ran clean every morning for a week while spend accumulated
        // unbilled, because a run that deducts nothing logs the same INFO line
        // as a run that deducts everything. The weekly reconciliation eventually
        // caught it; by then seven days had passed.
        $this->alertIfIdleWhileSpending($results);
    }

    /**
     * Claim this customer's billing for the day. False if someone already holds it.
     *
     * insertOrIgnore leans on the unique index, so two workers racing for the
     * same customer resolve in the database rather than in application code.
     */
    private function claimBilling(Customer $customer, string $billingDate): bool
    {
        return DB::table('ad_spend_billing_runs')->insertOrIgnore([
            'customer_id' => $customer->id,
            'billing_date' => $billingDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * Give the claim back so a retry can reprocess this customer.
     */
    private function releaseBilling(Customer $customer, string $billingDate): void
    {
        DB::table('ad_spend_billing_runs')
            ->where('customer_id', $customer->id)
            ->where('billing_date', $billingDate)
            ->delete();
    }

    /**
     * Customers whose ad spend should be deducted today.
     *
     * @param  list<string>  $recoverableStatuses
     * @return \Illuminate\Database\Eloquent\Collection<int, Customer>
     */
    private function eligibleCustomers(array $recoverableStatuses): \Illuminate\Database\Eloquent\Collection
    {
        return Customer::whereHas('adSpendCredit')
            ->where(function ($query) use ($recoverableStatuses) {
                $query->whereHas('campaigns', function ($q) {
                    $q->where('status', 'active')
                        ->orWhere('platform_status', 'ENABLED');
                })
                    ->orWhereHas('adSpendCredit', function ($q) use ($recoverableStatuses) {
                        $q->whereIn('payment_status', $recoverableStatuses);
                    });
            })
            ->with(['adSpendCredit', 'campaigns'])
            ->get();
    }

    /**
     * Warn when the run billed nobody but campaigns were live enough to spend.
     *
     * @param  array<string, int|float>  $results
     */
    private function alertIfIdleWhileSpending(array $results): void
    {
        if ($results['processed'] > 0 || $results['skipped'] > 0) {
            return;
        }

        $couldHaveSpent = Customer::whereHas('adSpendCredit')
            ->whereHas('campaigns', fn ($q) => $q->where('platform_status', 'ENABLED'))
            ->count();

        if ($couldHaveSpent === 0) {
            return;
        }

        Log::error('ProcessDailyAdSpendBilling: billed nobody while campaigns were live', [
            'customers_with_live_campaigns' => $couldHaveSpent,
            'results' => $results,
        ]);

        CriticalAgentAlert::deliver(
            'billing',
            'Daily ad spend billing deducted nothing while campaigns were live',
            "The daily billing run processed no customers, but {$couldHaveSpent} customer(s) with a credit account "
                .'have campaigns enabled on the platform. Spend is accruing that is not being deducted.',
            ['results' => $results, 'customers_with_live_campaigns' => $couldHaveSpent],
            \App\Models\NotificationTemplate::RECIPIENTS_ADMINS
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDailyAdSpendBilling failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
