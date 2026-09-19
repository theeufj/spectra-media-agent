<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Billing\AdSpendPaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Tests\TestCase;

class AdSpendPaymentGatewayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_settled_intent_is_retrieved_even_after_the_idempotency_window(): void
    {
        $customer = Customer::factory()->create();
        $gateway = new RecordingAdSpendGateway;
        $params = ['amount' => 70000, 'currency' => 'aud', 'payment_method' => 'pm_test'];
        $gateway->collect($customer, $params, 'initial-test');
        DB::table('ad_spend_charge_attempts')->where('idempotency_key', 'initial-test')->update(['created_at' => now()->subDays(3)]);
        $this->assertSame('pi_test', $gateway->collect($customer, $params, 'initial-test')->id);
        $this->assertSame(1, $gateway->creates);
        $this->assertSame(0, $gateway->confirms);
    }

    public function test_a_known_decline_retries_the_same_intent_and_never_recharges_a_success(): void
    {
        $customer = Customer::factory()->create();
        $gateway = new RecordingAdSpendGateway;
        $gateway->decline = true;
        $params = ['amount' => 70000, 'currency' => 'aud', 'payment_method' => 'pm_test'];
        try {
            $gateway->collect($customer, $params, 'decline-test');
            $this->fail('Expected the first charge to decline.');
        } catch (CardException) {
            $this->assertSame('pi_test', DB::table('ad_spend_charge_attempts')->where('idempotency_key', 'decline-test')->value('payment_intent_id'));
        }
        DB::table('ad_spend_charge_attempts')->where('idempotency_key', 'decline-test')->update(['created_at' => now()->subDays(3)]);
        $this->assertSame('succeeded', $gateway->collect($customer, $params, 'decline-test')->status);
        $gateway->collect($customer, $params, 'decline-test');
        $this->assertSame(1, $gateway->creates);
        $this->assertSame(1, $gateway->confirms);
    }

    public function test_an_old_uncertain_request_cannot_create_a_second_payment(): void
    {
        $customer = Customer::factory()->create();
        DB::table('ad_spend_charge_attempts')->insert([
            'customer_id' => $customer->id, 'idempotency_key' => 'uncertain-test',
            'amount_cents' => 70000, 'currency' => 'aud', 'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);
        $gateway = new RecordingAdSpendGateway;
        try {
            $gateway->collect($customer, ['amount' => 70000, 'currency' => 'aud', 'payment_method' => 'pm_test'], 'uncertain-test');
            $this->fail('An uncertain payment must require reconciliation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
            $this->assertSame(0, $gateway->creates);
        }
    }
}

class RecordingAdSpendGateway extends AdSpendPaymentGateway
{
    public int $creates = 0;

    public int $confirms = 0;

    public bool $decline = false;

    protected function create(array $params, string $key): PaymentIntent
    {
        $this->creates++;
        if ($this->decline) {
            throw CardException::factory('Card declined', 402, null, ['error' => ['type' => 'card_error', 'payment_intent' => ['id' => 'pi_test', 'object' => 'payment_intent']]]);
        }

        return $this->retrieve('pi_test');
    }

    protected function retrieve(string $id): PaymentIntent
    {
        return PaymentIntent::constructFrom(['id' => $id, 'object' => 'payment_intent', 'amount' => 70000, 'currency' => 'aud', 'status' => $this->decline ? 'requires_payment_method' : 'succeeded']);
    }

    protected function confirm(string $id, string $paymentMethod, string $key): PaymentIntent
    {
        $this->confirms++;
        $this->decline = false;

        return $this->retrieve($id);
    }
}
