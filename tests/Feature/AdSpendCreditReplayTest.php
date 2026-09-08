<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A replayed Stripe charge must move the balance once.
 *
 * Stripe replays the original response for a repeated idempotency key rather
 * than charging again, so a retried top-up comes back as a *success* carrying
 * the same charge id — and chargeCustomer() cannot tell the two apart, because
 * Stripe does not flag the replay. addCredit() had no lookup on the charge id,
 * so a $100 top-up retried inside the minute produced $200 of balance against
 * $100 actually collected. Spectra funds the difference.
 */
class AdSpendCreditReplayTest extends TestCase
{
    use DatabaseTransactions;

    private function credit(float $balance = 0.0): AdSpendCredit
    {
        $customer = Customer::factory()->create();

        return AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => $balance,
            'current_balance' => $balance,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
        ]);
    }

    public function test_a_replayed_charge_is_credited_once(): void
    {
        $credit = $this->credit();

        $credit->addCredit(100.00, 'Credit replenishment', 'pi_replayed');
        $credit->addCredit(100.00, 'Credit replenishment', 'pi_replayed');

        $this->assertEqualsWithDelta(
            100.00,
            (float) $credit->fresh()->current_balance,
            0.01,
            'Stripe collected $100 once, so the balance must not read $200.'
        );

        $this->assertEquals(
            1,
            $credit->transactions()->where('stripe_charge_id', 'pi_replayed')->count(),
            'The replay must not write a second ledger row either — the totals key off it.'
        );
    }

    public function test_two_genuine_charges_are_both_credited(): void
    {
        $credit = $this->credit();

        $credit->addCredit(100.00, 'Manual credit top-up', 'pi_one');
        $credit->addCredit(100.00, 'Manual credit top-up', 'pi_two');

        $this->assertEqualsWithDelta(200.00, (float) $credit->fresh()->current_balance, 0.01);
        $this->assertEquals(2, $credit->transactions()->count());
    }

    public function test_credits_without_a_charge_id_are_not_deduplicated(): void
    {
        // Nothing was collected through Stripe, so two grants of the same size
        // are two grants — not one delivered twice.
        $credit = $this->credit();

        $credit->addCredit(25.00, 'Goodwill credit');
        $credit->addCredit(25.00, 'Goodwill credit');

        $this->assertEqualsWithDelta(50.00, (float) $credit->fresh()->current_balance, 0.01);
        $this->assertEquals(2, $credit->transactions()->count());
    }

    public function test_a_refund_may_reuse_the_charge_id_of_the_credit_it_reverses(): void
    {
        // StripeWebhookController::recordAdSpendRefund books the refund against
        // the charge it reverses, and a second partial refund adds another row
        // on the same charge. The uniqueness guard is scoped to credit rows for
        // exactly this reason.
        $credit = $this->credit();

        $credit->addCredit(100.00, 'Credit replenishment', 'pi_refunded');
        $credit->recordAdjustment(-30.00, 'Stripe refund on charge pi_refunded', AdSpendTransaction::TYPE_REFUND, 'pi_refunded');
        $credit->recordAdjustment(-10.00, 'Stripe refund on charge pi_refunded', AdSpendTransaction::TYPE_REFUND, 'pi_refunded');

        $this->assertEqualsWithDelta(60.00, (float) $credit->fresh()->current_balance, 0.01);
    }
}
