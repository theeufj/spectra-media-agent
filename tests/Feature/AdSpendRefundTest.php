<?php

namespace Tests\Feature;

use App\Http\Controllers\StripeWebhookController;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\Billing\AdSpendPaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\PaymentIntent;
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

    private ClientInterface $previousStripeClient;

    private RefundStripeTransport $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier.secret' => 'sk_test_refunds', 'services.stripe.secret' => 'sk_test_refunds']);
        $this->previousStripeClient = ApiRequestor::httpClient();
        $this->stripe = new RefundStripeTransport;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousStripeClient);
        parent::tearDown();
    }

    /** Exercise chargeCustomer() and the ledger with a fake payment gateway. */
    private function fundedCredit(string $paymentIntentId, string $chargeId): AdSpendCredit
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $payer = User::factory()->create(['stripe_id' => 'cus_refund', 'pm_type' => 'visa']);
        $customer->users()->attach($payer->id, ['role' => 'owner']);
        $this->mock(AdSpendPaymentGateway::class, function ($mock) use ($customer, $paymentIntentId, $chargeId) {
            $mock->shouldReceive('collect')->once()
                ->withArgs(fn ($account, $params, $key) => $account->id === $customer->id && $params['amount'] === 35000 && $params['currency'] === 'aud')
                ->andReturn(PaymentIntent::constructFrom([
                    'id' => $paymentIntentId, 'object' => 'payment_intent',
                    'amount' => 35000, 'currency' => 'aud', 'status' => 'succeeded', 'latest_charge' => $chargeId,
                ]));
        });

        return app(AdSpendBillingService::class)->initializeCreditAccount($customer, 50);
    }

    private function refundEvent(string $chargeId, int $refundedCents, string|array|null $paymentIntent = null): void
    {
        $method = 'handle'.Str::studly(str_replace('.', '_', 'charge.refunded'));
        (new \ReflectionMethod(StripeWebhookController::class, $method))->invoke(new StripeWebhookController, [
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => $chargeId, 'amount_refunded' => $refundedCents, 'payment_intent' => $paymentIntent]],
        ]);
    }

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $this->actingAs($admin);
    }

    public function test_a_real_payment_is_reversed_by_its_charge_refund_events(): void
    {
        $credit = $this->fundedCredit('pi_real_refund', 'ch_real_refund');
        $this->assertSame('pi_real_refund', $credit->transactions()->sole()->getAttribute('stripe_charge_id'));

        $this->refundEvent('ch_real_refund', 20000, 'pi_real_refund');
        $this->assertSame(150.0, (float) $credit->fresh()->current_balance);
        $this->refundEvent('ch_real_refund', 20000, 'pi_real_refund');
        $this->refundEvent('ch_real_refund', 30000, 'pi_real_refund');
        $this->refundEvent('ch_real_refund', 20000, 'pi_real_refund');

        $this->assertSame(50.0, (float) $credit->fresh()->current_balance);
        $this->assertSame(2, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
        $this->assertSame(0.0, AdSpendTransaction::totalDebited($credit->id));
    }

    public function test_legacy_charge_ids_still_settle_when_the_event_also_names_a_payment_intent(): void
    {
        $credit = $this->creditBoughtBy('ch_legacy_refund');

        $this->refundEvent('ch_legacy_refund', 20000, ['id' => 'pi_legacy_refund', 'object' => 'payment_intent']);
        $this->refundEvent('ch_legacy_refund', 20000, 'pi_legacy_refund');

        $this->assertSame(150.0, (float) $credit->fresh()->current_balance);
        $this->assertSame('ch_legacy_refund', $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->sole()->getAttribute('stripe_charge_id'));
    }

    public function test_an_existing_charge_id_refund_is_counted_against_a_payment_intent_credit(): void
    {
        $credit = $this->creditBoughtBy('pi_mixed_refund');
        $credit->recordAdjustment(-100, 'Earlier Stripe refund', AdSpendTransaction::TYPE_REFUND, 'ch_mixed_refund');

        $this->refundEvent('ch_mixed_refund', 20000, 'pi_mixed_refund');
        $this->refundEvent('ch_mixed_refund', 20000, 'pi_mixed_refund');

        $this->assertSame(150.0, (float) $credit->fresh()->current_balance);
        $this->assertSame(2, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_admin_and_webhook_refunds_share_the_real_payments_cumulative_total(): void
    {
        $credit = $this->fundedCredit('pi_admin_refund', 'ch_admin_refund');
        $this->stripe->refundResponses[] = [
            'id' => 're_first', 'object' => 'refund', 'amount' => 20000, 'payment_intent' => 'pi_admin_refund',
            'charge' => ['id' => 'ch_admin_refund', 'object' => 'charge', 'amount_refunded' => 20000, 'payment_intent' => 'pi_admin_refund'],
        ];
        $this->actingAsAdmin()->post(route('admin.revenue.refund', 'ch_admin_refund'), ['amount' => 200, 'confirmed' => true])
            ->assertSessionHas('flash.type', 'success');
        $this->assertSame(150.0, (float) $credit->fresh()->current_balance);

        $this->refundEvent('ch_admin_refund', 20000, 'pi_admin_refund');
        $this->refundEvent('ch_admin_refund', 30000, 'pi_admin_refund');
        $this->stripe->refundResponses[] = [
            'id' => 're_second', 'object' => 'refund', 'amount' => 10000, 'payment_intent' => 'pi_admin_refund',
            'charge' => ['id' => 'ch_admin_refund', 'object' => 'charge', 'amount_refunded' => 30000, 'payment_intent' => 'pi_admin_refund'],
        ];
        $this->post(route('admin.revenue.refund', 'ch_admin_refund'), ['amount' => 100, 'confirmed' => true])
            ->assertSessionHas('flash.type', 'success');

        $this->assertSame(50.0, (float) $credit->fresh()->current_balance);
        $this->assertSame(2, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_an_unexpanded_admin_refund_uses_its_payment_intent_then_the_webhook_settles_the_remainder(): void
    {
        $credit = $this->creditBoughtBy('pi_unexpanded_refund');
        $this->stripe->refundResponses[] = [
            'id' => 're_unexpanded', 'object' => 'refund', 'amount' => 10000,
            'charge' => 'ch_unexpanded_refund', 'payment_intent' => 'pi_unexpanded_refund',
        ];
        $this->actingAsAdmin()->post(route('admin.revenue.refund', 'ch_unexpanded_refund'), ['amount' => 100, 'confirmed' => true])
            ->assertSessionHas('flash.type', 'success');
        $this->assertSame(250.0, (float) $credit->fresh()->current_balance);

        $this->refundEvent('ch_unexpanded_refund', 20000, 'pi_unexpanded_refund');
        $this->refundEvent('ch_unexpanded_refund', 20000, 'pi_unexpanded_refund');

        $this->assertSame(150.0, (float) $credit->fresh()->current_balance);
        $this->assertSame(2, $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->count());
    }

    public function test_an_expanded_charge_without_a_payment_intent_uses_the_refunds_payment_intent(): void
    {
        $credit = $this->creditBoughtBy('pi_expanded_refund');
        $this->stripe->refundResponses[] = [
            'id' => 're_expanded', 'object' => 'refund', 'amount' => 10000,
            'charge' => ['id' => 'ch_expanded_refund', 'object' => 'charge', 'amount_refunded' => 10000, 'payment_intent' => null],
            'payment_intent' => ['id' => 'pi_expanded_refund', 'object' => 'payment_intent'],
        ];

        $this->actingAsAdmin()->post(route('admin.revenue.refund', 'ch_expanded_refund'), ['amount' => 100, 'confirmed' => true])
            ->assertSessionHas('flash.type', 'success');

        $this->assertSame(250.0, (float) $credit->fresh()->current_balance);
        $this->assertSame('pi_expanded_refund', $credit->transactions()->where('type', AdSpendTransaction::TYPE_REFUND)->sole()->getAttribute('stripe_charge_id'));
    }

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

/** Stripe's SDK bypasses Laravel HTTP fakes, so reject any unexpected request here. */
class RefundStripeTransport implements ClientInterface
{
    public array $refundResponses = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        if ($method === 'get' && $path === '/v1/customers/cus_refund') {
            return [json_encode([
                'id' => 'cus_refund', 'object' => 'customer',
                'invoice_settings' => ['default_payment_method' => [
                    'id' => 'pm_refund', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_refund',
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                ]],
            ]), 200, []];
        }
        if ($method === 'post' && $path === '/v1/refunds' && $this->refundResponses !== []) {
            return [json_encode(array_shift($this->refundResponses)), 200, []];
        }

        throw new \RuntimeException('Unexpected Stripe test request: '.$method.' '.$path);
    }
}
