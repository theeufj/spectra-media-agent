<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Services\Health\BillingHealthChecker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The billing health check read two attributes that do not exist.
 *
 * `remaining_amount` occurs exactly once in the codebase — in the checker — so
 * under non-strict mode it resolved to null and every customer, including one
 * holding $5,000, permanently reported "Low ad spend credits balance. Current
 * balance: $0". The reversal count then flagged any negative amount, and
 * deduct() writes -$amount for every ordinary daily charge, so each billed
 * customer also collected a spurious high-severity warning per run. Between
 * them the checker was noise, and a genuine problem could not be seen in it.
 */
class BillingHealthCheckerTest extends TestCase
{
    use DatabaseTransactions;

    private function customerWithCredit(float $balance): Customer
    {
        $customer = Customer::factory()->create();

        AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => $balance,
            'current_balance' => $balance,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
        ]);

        return $customer->fresh();
    }

    /** @param  array<int, array<string, mixed>>  $warnings */
    private function warningTypes(array $warnings): array
    {
        return array_map(fn ($warning) => $warning['type'], $warnings);
    }

    public function test_a_funded_account_does_not_report_a_low_balance(): void
    {
        $health = (new BillingHealthChecker)->check($this->customerWithCredit(5000.00));

        $this->assertEqualsWithDelta(5000.00, $health['metrics']['ad_spend_credits'], 0.01);
        $this->assertNotContains('credits', $this->warningTypes($health['warnings']));
        $this->assertSame('healthy', $health['status']);
    }

    public function test_an_empty_account_still_reports_a_low_balance(): void
    {
        $health = (new BillingHealthChecker)->check($this->customerWithCredit(4.00));

        $this->assertContains('credits', $this->warningTypes($health['warnings']));
    }

    public function test_an_ordinary_daily_deduction_is_not_a_credit_reversal(): void
    {
        $customer = $this->customerWithCredit(500.00);
        $customer->adSpendCredit()->first()->deduct(50.00, 'Daily ad spend - '.now()->subDay()->toDateString());

        $health = (new BillingHealthChecker)->check($customer->fresh());

        $this->assertNotContains(
            'payment_failures',
            $this->warningTypes($health['warnings']),
            'deduct() stores -50 for a normal charge; that is billing working, not a reversal.'
        );
    }

    public function test_a_refund_is_a_credit_reversal(): void
    {
        $customer = $this->customerWithCredit(500.00);
        $customer->adSpendCredit()->first()->recordAdjustment(
            -25.00,
            'Stripe refund on charge pi_reversed',
            AdSpendTransaction::TYPE_REFUND,
            'pi_reversed',
        );

        $health = (new BillingHealthChecker)->check($customer->fresh());

        $this->assertContains('payment_failures', $this->warningTypes($health['warnings']));
    }

    public function test_a_customer_with_no_credit_account_is_not_warned_about_a_zero_balance(): void
    {
        // Self-funded accounts (Google bills the customer's own card) never
        // have a credit row. A permanent "$0 balance" warning against them is
        // noise nobody can act on.
        $health = (new BillingHealthChecker)->check(Customer::factory()->create());

        $this->assertNotContains('credits', $this->warningTypes($health['warnings']));
    }
}
