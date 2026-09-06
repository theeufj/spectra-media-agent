<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin "Reconcile Spend" button must charge what is owed, and only once.
 *
 * Deductions are stored negative and the legacy 'debit' rows positive, so a
 * plain SUM() over the ledger returns a number that means nothing. Subtracting
 * that from actual spend added every dollar already billed back onto the bill:
 * for a real account with $2,046.80 of spend and $1,323.10 already taken, the
 * button offered to reconcile $3,369.90 against a true figure of $723.70, and
 * clicking it wiped the customer's whole remaining balance.
 *
 * It was self-concealing, too — the positive adjustment brought the debit total
 * up to exactly the actual spend, so afterwards the page read $0.00 in green.
 */
class AdSpendReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(float $balance, float $spend, float $alreadyBilled): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $user->roles()->attach(\App\Models\Role::unguarded(fn () => \App\Models\Role::firstOrCreate(['name' => 'admin'])));

        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'payment_status' => 'current',
            'current_balance' => $balance,
            'initial_credit_amount' => $balance,
        ]);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        GoogleAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => now()->subDay()->toDateString(),
            'cost' => $spend,
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
        ]);

        if ($alreadyBilled > 0) {
            $credit->deduct($alreadyBilled, 'Daily ad spend - '.now()->subDay()->toDateString());
        }

        return [$user, $customer, $credit->fresh()];
    }

    public function test_debited_totals_are_positive_across_every_debit_type(): void
    {
        [, , $credit] = $this->scenario(balance: 500, spend: 100, alreadyBilled: 100);

        // The two historical rows that no current code writes, and that every
        // ledger query used to drop on the floor.
        $credit->transactions()->create([
            'type' => AdSpendTransaction::TYPE_DEBIT,
            'amount' => 25.00,
            'balance_after' => $credit->current_balance,
            'description' => 'legacy debit row',
        ]);

        $this->assertEqualsWithDelta(
            125.00,
            AdSpendTransaction::totalDebited($credit->getKey()),
            0.01,
            'A deduction stored as -100 and a legacy debit stored as +25 are 125 of debits, not -75.'
        );
    }

    public function test_reconciling_charges_only_what_is_actually_outstanding(): void
    {
        // The production spend figures, funded well enough to tell the two
        // amounts apart: the true $723.70 leaves money in the account, the
        // buggy $3,369.90 empties it.
        [$user, $customer, $credit] = $this->scenario(
            balance: 3000.00,
            spend: 2046.80,
            alreadyBilled: 1323.10,
        );

        // Already reduced by the $1,323.10 the setup billed.
        $balanceBefore = (float) $credit->current_balance;

        $this->actingAs($user)
            ->post(route('admin.customers.reconcile-spend', $customer))
            ->assertRedirect();

        $owed = 2046.80 - 1323.10;   // 723.70

        $this->assertEqualsWithDelta(
            $balanceBefore - $owed,
            (float) $credit->fresh()->current_balance,
            0.01,
            'Reconciliation must take the unbilled remainder, not the remainder plus everything already billed.'
        );

        $adjustment = $credit->transactions()
            ->where('type', AdSpendTransaction::TYPE_ADJUSTMENT)
            ->sole();

        $this->assertEqualsWithDelta(-$owed, (float) $adjustment->amount, 0.01);
    }

    public function test_the_adjustment_is_a_debit_so_a_second_run_does_nothing(): void
    {
        [$user, $customer, $credit] = $this->scenario(
            balance: 3000.00,
            spend: 2046.80,
            alreadyBilled: 1323.10,
        );

        $this->actingAs($user)->post(route('admin.customers.reconcile-spend', $customer));

        $afterFirst = (float) $credit->fresh()->current_balance;

        $this->assertGreaterThan(0, $afterFirst);

        // A positive adjustment left the account's debit total short by twice
        // the adjustment, so the same spend stayed reconcilable for ever.
        $this->actingAs($user)->post(route('admin.customers.reconcile-spend', $customer));

        $this->assertEqualsWithDelta(
            $afterFirst,
            (float) $credit->fresh()->current_balance,
            0.01,
            'Reconciling twice must be a no-op — the first run settled the spend.'
        );
    }

    public function test_a_reconciled_account_reports_nothing_outstanding(): void
    {
        [$user, $customer] = $this->scenario(balance: 500, spend: 100, alreadyBilled: 100);

        $this->actingAs($user)
            ->post(route('admin.customers.reconcile-spend', $customer))
            ->assertSessionHas('flash.type', 'info');
    }

    public function test_the_balance_status_is_recalculated_when_a_balance_is_adjusted(): void
    {
        // update(['current_balance' => ...]) bypassed updateBalanceStatus(), so
        // an account could sit at 'active' with nothing in it — and the
        // low-balance and depleted alerting both key off that status.
        [$user, $customer, $credit] = $this->scenario(
            balance: 50,
            spend: 500,
            alreadyBilled: 0,
        );

        $this->actingAs($user)->post(route('admin.customers.reconcile-spend', $customer));

        $credit->refresh();

        $this->assertEqualsWithDelta(0.0, (float) $credit->current_balance, 0.01);
        $this->assertSame(AdSpendCredit::STATUS_DEPLETED, $credit->status);

        // $500 was owed against a $50 balance. The ledger records the $50 that
        // was actually taken, so the totals stay internally consistent and the
        // $450 remainder is still visible as unreconciled rather than being
        // quietly written off.
        $adjustment = $credit->transactions()
            ->where('type', AdSpendTransaction::TYPE_ADJUSTMENT)
            ->sole();

        $this->assertEqualsWithDelta(-50.0, (float) $adjustment->amount, 0.01);
    }
}
