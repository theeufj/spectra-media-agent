<?php

namespace App\Console\Commands;

use App\Models\AdSpendTransaction;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Post the deduction the daily billing run missed.
 *
 * The weekly reconciliation reports where platform spend and ledger deductions
 * diverged, then says "no automated correction has been applied" — which was
 * true, and left no way to apply one short of writing ledger rows by hand. So
 * the gap was reported every week and never closed.
 *
 * This closes it deliberately, against real platform figures rather than the
 * number quoted in an email, and it refuses to run twice for the same day.
 *
 * It only ever deducts. Crediting money back is a decision with a customer on
 * the other end of it and does not belong in a catch-up job.
 */
class ApplyAdSpendReconciliation extends Command
{
    protected $signature = 'adspend:apply-reconciliation
                            {customer : Customer id}
                            {--from= : First date to reconcile (YYYY-MM-DD)}
                            {--to= : Last date to reconcile (YYYY-MM-DD)}
                            {--dry-run : Show what would be posted}';

    protected $description = 'Deduct ad spend that the daily billing run never charged for';

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));

        if (! $customer) {
            $this->error('No customer with id '.$this->argument('customer'));

            return self::FAILURE;
        }

        // Fetched through the relation rather than the magic property: Larastan
        // cannot see hasOne relations on this model, and the alternative was
        // another baseline entry.
        $credit = \App\Models\AdSpendCredit::where('customer_id', $customer->id)->first();

        if (! $credit) {
            $this->error($customer->name.' has no ad spend credit account.');

            return self::FAILURE;
        }

        $timezone = $customer->timezone ?: config('app.timezone');
        $lastClosedDay = now()->setTimezone($timezone)->subDay()->toDateString();

        $from = $this->option('from') ?: now()->setTimezone($timezone)->subDays(14)->toDateString();
        $to = $this->option('to') ?: $lastClosedDay;

        // Never reconcile a day still accruing. Posting a partial day and
        // marking it done under-bills it permanently, because nothing revisits
        // a day that already has a catch-up entry.
        if ($to > $lastClosedDay) {
            $this->warn("Ignoring dates after {$lastClosedDay} — that day has not closed in {$timezone} yet.");
            $to = $lastClosedDay;
        }

        $spend = $this->platformSpendByDay($customer, $from, $to);

        if ($spend === []) {
            $this->info('No platform spend in that window.');

            return self::SUCCESS;
        }

        $this->line('Customer : '.$customer->name.' (#'.$customer->id.')');
        $this->line('Balance  : '.number_format((float) $credit->current_balance, 2));
        $this->newLine();

        $posted = 0.0;

        foreach ($spend as $day => $amount) {
            // Idempotent by description: re-running must not deduct twice for a
            // day already caught up.
            $marker = "Reconciliation catch-up for {$day}";

            $already = AdSpendTransaction::where('ad_spend_credit_id', $credit->id)
                ->where('description', $marker)
                ->exists();

            if ($already) {
                $this->line(sprintf('  %s  %8.2f  already reconciled', $day, $amount));

                continue;
            }

            // Matched on the day the entry bills for, not the day it was
            // written. Keying off created_at counted a catch-up posted on the
            // 17th as covering the 17th's spend, which would skip a day as
            // settled when it was not.
            $deducted = abs((float) AdSpendTransaction::where('ad_spend_credit_id', $credit->id)
                ->where('type', 'deduction')
                ->where('description', 'like', '%'.$day)
                ->sum('amount'));

            $owing = round($amount - $deducted, 2);

            if ($owing <= 0) {
                $this->line(sprintf('  %s  %8.2f  already covered (%.2f deducted)', $day, $amount, $deducted));

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  %s  %8.2f  would deduct %.2f', $day, $amount, $owing));
                $posted += $owing;

                continue;
            }

            if (! $credit->deduct($owing, $marker)) {
                // deduct() refuses rather than overdraw. Say so plainly: a
                // silent skip here would recreate the problem this command
                // exists to fix.
                $this->error(sprintf('  %s  %8.2f  REFUSED — balance %.2f is short of %.2f', $day, $amount, (float) $credit->current_balance, $owing));

                continue;
            }

            $this->info(sprintf('  %s  %8.2f  deducted %.2f, balance now %.2f', $day, $amount, $owing, (float) $credit->fresh()->current_balance));
            $posted += $owing;

            Log::info('ApplyAdSpendReconciliation: posted catch-up deduction', [
                'customer_id' => $customer->id,
                'day' => $day,
                'amount' => $owing,
            ]);
        }

        $this->newLine();
        $this->line(sprintf('%s %.2f', $this->option('dry-run') ? 'Would post' : 'Posted', $posted));

        return self::SUCCESS;
    }

    /**
     * Actual spend per day from the ad platform, not from our own records.
     *
     * The whole point is that our records are the thing in doubt.
     *
     * @return array<string, float>
     */
    private function platformSpendByDay(Customer $customer, string $from, string $to): array
    {
        if (! $customer->google_ads_customer_id) {
            return [];
        }

        $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService
        {
            public function query(string $customerId, string $gaql): \Google\ApiCore\PagedListResponse
            {
                $this->ensureClient();

                return $this->searchQuery($customerId, $gaql);
            }
        };

        $rows = $service->query($customer->cleanGoogleCustomerId(), "
            SELECT segments.date, metrics.cost_micros
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
              AND metrics.cost_micros > 0
        ");

        $byDay = [];

        foreach ($rows->iterateAllElements() as $row) {
            $day = $row->getSegments()->getDate();
            $byDay[$day] = ($byDay[$day] ?? 0) + ($row->getMetrics()->getCostMicros() / 1_000_000);
        }

        ksort($byDay);

        return array_map(fn ($v) => round($v, 2), $byDay);
    }
}
