<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A day must not be billed twice by two different paths.
 *
 * The idempotency marker on the nightly job is per customer per *run* date, so
 * it does nothing to stop a day being settled once by a reconciliation catch-up
 * and again by the next morning's run. That is exactly what happened: 207.70 of
 * spend on 18 August was reconciled at 21:53, then billed again at 08:00 the
 * next day. 246.56 taken for 207.70 of spend, the balance driven to zero and the
 * account into grace period — while the campaign kept running.
 */
class BillingDoubleChargeTest extends TestCase
{
    use RefreshDatabase;

    private function creditFor(Customer $customer, float $balance = 300): AdSpendCredit
    {
        return AdSpendCredit::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'payment_status' => 'current',
            'current_balance' => $balance,
            'initial_credit_amount' => $balance,
        ]);
    }

    private function deductionsFor(AdSpendCredit $credit, string $day): float
    {
        $method = new \ReflectionMethod(AdSpendBillingService::class, 'deductionsFor');
        $method->setAccessible(true);

        return $method->invoke(app(AdSpendBillingService::class), $credit, $day);
    }

    public function test_a_day_already_reconciled_is_recognised(): void
    {
        // The catch-up entry the nightly run failed to notice.
        Carbon::setTestNow('2026-08-19 08:00:00');

        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);
        $credit = $this->creditFor($customer);

        $credit->deduct(207.70, 'Reconciliation catch-up for 2026-08-18');

        $this->assertEqualsWithDelta(207.70, $this->deductionsFor($credit, '2026-08-18'), 0.01);
    }

    public function test_a_deduction_for_another_day_is_not_counted(): void
    {
        // Otherwise netting off would swing the other way and under-bill.
        Carbon::setTestNow('2026-08-19 08:00:00');

        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);
        $credit = $this->creditFor($customer);

        $credit->deduct(10.39, 'Daily ad spend - 2026-08-17');

        $this->assertSame(0.0, $this->deductionsFor($credit, '2026-08-18'));
    }

    public function test_entries_are_matched_by_the_day_they_bill_for_not_when_written(): void
    {
        // A catch-up posted today may settle a day from last week, and the
        // reverse mistake — matching on created_at — is what made a day look
        // settled when it was not.
        Carbon::setTestNow('2026-08-19 08:00:00');

        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);
        $credit = $this->creditFor($customer);

        $credit->deduct(17.64, 'Reconciliation catch-up for 2026-08-08');

        $this->assertSame(0.0, $this->deductionsFor($credit, '2026-08-19'));
        $this->assertEqualsWithDelta(17.64, $this->deductionsFor($credit, '2026-08-08'), 0.01);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
