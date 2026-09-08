<?php

namespace Tests\Feature;

use App\Http\Controllers\StripeWebhookController;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A refund has to come back out of the ad spend credit it bought.
 *
 * The admin refund tool called \Stripe\Refund::create, wrote an ActivityLog and
 * stopped. Refund $800 of unused credit and current_balance still read $800, so
 * the nightly billing run carried on deducting live spend against money that
 * had already been given back.
 */
class AdSpendRefundTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A credit account whose balance was bought by one Stripe charge — the
     * shape initializeCreditAccount() writes.
     */
    private function creditBoughtBy(string $chargeId, float $amount = 350.00): AdSpendCredit
    {
        $credit = AdSpendCredit::factory()->create([
            'customer_id' => Customer::factory(),
            'initial_credit_amount' => $amount,
            'current_balance' => $amount,
        ]);

        $credit->transactions()->create([
            'type' => AdSpendTransaction::TYPE_CREDIT,
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'Initial ad spend credit (7 days prepaid)',
            'stripe_charge_id' => $chargeId,
        ]);

        return $credit;
    }

    public function test_a_refunded_charge_comes_back_out_of_the_balance(): void
    {
        $credit = $this->creditBoughtBy('ch_refund_balance');

        StripeWebhookController::recordAdSpendRefund('ch_refund_balance', 200.00);

        $this->assertSame(150.00, (float) $credit->fresh()->current_balance);
    }

    public function test_the_refund_is_written_to_the_ledger(): void
    {
        $credit = $this->creditBoughtBy('ch_refund_ledger');

        StripeWebhookController::recordAdSpendRefund('ch_refund_ledger', 200.00);

        /** @var \App\Models\AdSpendTransaction $refund */
        $refund = $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->sole();

        // Negative, like every other row that takes money out, so the ledger's
        // credit total nets to what the customer actually still holds.
        $this->assertSame(-200.00, (float) $refund->amount);
        $this->assertSame(150.00, (float) $refund->balance_after);
        $this->assertSame('ch_refund_ledger', $refund->stripe_charge_id);
    }

    public function test_a_refund_is_not_counted_as_ad_spend_already_billed(): void
    {
        // Why the row is TYPE_REFUND and not TYPE_ADJUSTMENT: adjustments are
        // debit types, and totalDebited() is what the admin reconciliation tool
        // subtracts from real platform spend. Booking a refund as an adjustment
        // would tell it the platform had already recovered that spend, and the
        // customer would never be charged for it.
        $credit = $this->creditBoughtBy('ch_refund_not_a_debit');

        StripeWebhookController::recordAdSpendRefund('ch_refund_not_a_debit', 200.00);

        $this->assertSame(0.0, AdSpendTransaction::totalDebited($credit->getKey()));
    }

    public function test_a_redelivered_refund_only_moves_the_money_once(): void
    {
        // Stripe redelivers webhooks, and the admin console records the refund
        // it just created rather than waiting for one. Both settle against the
        // charge's cumulative refunded total, so the second call finds nothing
        // outstanding.
        $credit = $this->creditBoughtBy('ch_refund_twice');

        StripeWebhookController::recordAdSpendRefund('ch_refund_twice', 200.00);
        StripeWebhookController::recordAdSpendRefund('ch_refund_twice', 200.00);

        $this->assertSame(150.00, (float) $credit->fresh()->current_balance);
        $this->assertSame(1, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_a_second_partial_refund_takes_out_only_the_difference(): void
    {
        $credit = $this->creditBoughtBy('ch_refund_partial');

        StripeWebhookController::recordAdSpendRefund('ch_refund_partial', 200.00);
        StripeWebhookController::recordAdSpendRefund('ch_refund_partial', 350.00);

        $this->assertSame(0.0, (float) $credit->fresh()->current_balance);
        $this->assertSame(2, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_a_refund_never_takes_the_balance_below_zero(): void
    {
        // Credit already spent on ads cannot be taken back out of an empty
        // account. The ledger records what was applied, not what was asked for.
        $credit = $this->creditBoughtBy('ch_refund_capped');
        $credit->deduct(300.00, 'Daily ad spend');

        StripeWebhookController::recordAdSpendRefund('ch_refund_capped', 350.00);

        $this->assertSame(0.0, (float) $credit->fresh()->current_balance);

        /** @var \App\Models\AdSpendTransaction $capped */
        $capped = $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->sole();

        $this->assertSame(-50.00, (float) $capped->amount);
    }

    public function test_a_charge_that_never_bought_ad_spend_credit_is_left_alone(): void
    {
        // Subscription invoices and setup fees are refunded through the same
        // admin tool and the same webhook, and must not touch the balance.
        $credit = $this->creditBoughtBy('ch_refund_unrelated');

        StripeWebhookController::recordAdSpendRefund('ch_a_subscription_invoice', 99.00);

        $this->assertSame(350.00, (float) $credit->fresh()->current_balance);
        $this->assertSame(0, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_the_charge_refunded_event_reaches_a_handler(): void
    {
        // Cashier dispatches on the event name, so the handler is only wired up
        // for as long as its name keeps matching. Derive it the same way.
        $method = 'handle'.Str::studly(str_replace('.', '_', 'charge.refunded'));

        $credit = $this->creditBoughtBy('ch_refund_webhook');

        $handler = new \ReflectionMethod(StripeWebhookController::class, $method);
        $handler->invoke(new StripeWebhookController, [
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_refund_webhook',
                'amount_refunded' => 20000, // cents, cumulative
            ]],
        ]);

        $this->assertSame(150.00, (float) $credit->fresh()->current_balance);
    }
}
