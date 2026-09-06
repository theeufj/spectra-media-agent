<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailyAdSpendBilling;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guards that stop money moving wrongly without anyone noticing.
 *
 * Each of these covers a path that used to report success while doing nothing,
 * or do something twice while reporting once.
 */
class BillingSafetyNetsTest extends TestCase
{
    use RefreshDatabase;

    private function creditFor(Customer $customer, float $balance): AdSpendCredit
    {
        return AdSpendCredit::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'payment_status' => 'current',
            'current_balance' => $balance,
            'initial_credit_amount' => $balance,
        ]);
    }

    public function test_a_refused_deduction_takes_nothing_and_writes_no_ledger_row(): void
    {
        // deduct() refuses rather than overdraw. The billing service used to
        // discard this return and report "Deducted from credit balance"
        // regardless — so the day looked settled while nothing was taken, and
        // the claim below means it is never retried.
        $customer = Customer::factory()->create();
        $credit = $this->creditFor($customer, 10.00);

        $this->assertFalse($credit->deduct(50.00, 'more than is there'));

        $this->assertEqualsWithDelta(10.00, (float) $credit->fresh()->current_balance, 0.01);
        $this->assertSame(0, $credit->transactions()->count());
    }

    public function test_a_deduction_records_the_day_it_bills_for(): void
    {
        // The double-billing guard matched on `description LIKE '%2026-09-05'`.
        // It held only because every writer happened to append the date —
        // deduct()'s own default description does not.
        $customer = Customer::factory()->create();
        $credit = $this->creditFor($customer, 500.00);

        $credit->deduct(100.00, 'Daily ad spend charge', '2026-09-05');

        $transaction = AdSpendTransaction::where('ad_spend_credit_id', $credit->getKey())->sole();

        $this->assertSame('2026-09-05', $transaction->billed_for->toDateString());
    }

    public function test_the_day_guard_finds_a_deduction_with_no_date_in_its_description(): void
    {
        $customer = Customer::factory()->create();
        $credit = $this->creditFor($customer, 500.00);

        $credit->deduct(100.00, 'Daily ad spend charge', '2026-09-05');

        $method = new \ReflectionMethod(AdSpendBillingService::class, 'deductionsFor');
        $method->setAccessible(true);

        $this->assertEqualsWithDelta(
            100.00,
            $method->invoke(app(AdSpendBillingService::class), $credit, '2026-09-05'),
            0.01,
            'The guard must read billed_for, not the description text.'
        );
    }

    public function test_the_day_guard_still_finds_rows_written_before_the_column_existed(): void
    {
        $customer = Customer::factory()->create();
        $credit = $this->creditFor($customer, 500.00);

        // A row as the old code wrote it: date in the description, billed_for null.
        $credit->transactions()->create([
            'type' => AdSpendTransaction::TYPE_DEDUCTION,
            'amount' => -100.00,
            'balance_after' => 400.00,
            'description' => 'Daily ad spend - 2026-09-05',
        ]);

        $method = new \ReflectionMethod(AdSpendBillingService::class, 'deductionsFor');
        $method->setAccessible(true);

        $this->assertEqualsWithDelta(
            100.00,
            $method->invoke(app(AdSpendBillingService::class), $credit, '2026-09-05'),
            0.01
        );
    }

    public function test_a_customer_can_only_be_claimed_once_per_billing_day(): void
    {
        // This claim was a Cache::add() marker — the only thing between a
        // customer and a second day's billing, held somewhere a Redis flush or
        // an lru eviction could silently drop.
        $customer = Customer::factory()->create();

        $claim = new \ReflectionMethod(ProcessDailyAdSpendBilling::class, 'claimBilling');
        $claim->setAccessible(true);
        $job = new ProcessDailyAdSpendBilling;

        $this->assertTrue($claim->invoke($job, $customer, '2026-09-05'));
        $this->assertFalse($claim->invoke($job, $customer, '2026-09-05'), 'The second claim on the same day must lose.');
        $this->assertTrue($claim->invoke($job, $customer, '2026-09-06'), 'A different day is a different claim.');

        $this->assertSame(2, DB::table('ad_spend_billing_runs')->where('customer_id', $customer->id)->count());
    }

    public function test_a_released_claim_can_be_taken_again(): void
    {
        $customer = Customer::factory()->create();
        $job = new ProcessDailyAdSpendBilling;

        $claim = new \ReflectionMethod(ProcessDailyAdSpendBilling::class, 'claimBilling');
        $claim->setAccessible(true);
        $release = new \ReflectionMethod(ProcessDailyAdSpendBilling::class, 'releaseBilling');
        $release->setAccessible(true);

        $this->assertTrue($claim->invoke($job, $customer, '2026-09-05'));
        $release->invoke($job, $customer, '2026-09-05');

        $this->assertTrue(
            $claim->invoke($job, $customer, '2026-09-05'),
            'An unexpected failure releases the claim so a retry reprocesses the customer.'
        );
    }

    public function test_charge_idempotency_keys_are_stable_and_purpose_scoped(): void
    {
        // Stripe replays the original response for a repeated key rather than
        // charging again, so the key must derive only from what identifies the
        // charge — never from the clock or a random value.
        $customer = Customer::factory()->create();

        $method = new \ReflectionMethod(AdSpendBillingService::class, 'idempotencyKey');
        $method->setAccessible(true);
        $service = app(AdSpendBillingService::class);

        $first = $method->invoke($service, 'replenish', $customer, '2026-09-05', 42.50);
        $second = $method->invoke($service, 'replenish', $customer, '2026-09-05', 42.50);

        $this->assertSame($first, $second, 'The same intended charge must produce the same key.');

        $this->assertNotSame(
            $first,
            $method->invoke($service, 'recovery', $customer, '2026-09-05', 42.50),
            'A different purpose is a different charge.'
        );

        $this->assertNotSame(
            $first,
            $method->invoke($service, 'replenish', $customer, '2026-09-06', 42.50),
            'A different day is a different charge.'
        );

        $this->assertNotSame(
            $first,
            $method->invoke($service, 'replenish', $customer, '2026-09-05', 42.51),
            'A different amount is a different charge.'
        );
    }
}
