<?php

namespace App\Services\Billing;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

/** Keep a durable Stripe identity across network failures and ledger retries. */
class AdSpendPaymentGateway
{
    public function collect(Customer $customer, array $params, string $key): PaymentIntent
    {
        DB::table('ad_spend_charge_attempts')->insertOrIgnore([
            'customer_id' => $customer->id, 'idempotency_key' => $key,
            'amount_cents' => $params['amount'], 'currency' => $params['currency'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = DB::table('ad_spend_charge_attempts')->where('idempotency_key', $key)->first();
        if ((int) $attempt->customer_id !== $customer->id || (int) $attempt->amount_cents !== $params['amount'] || $attempt->currency !== $params['currency']) {
            throw new \RuntimeException('Payment attempt parameters changed; reconciliation is required.');
        }
        try {
            if ($attempt->payment_intent_id) {
                $intent = $this->retrieve($attempt->payment_intent_id);
                if ($intent->amount !== $params['amount'] || $intent->currency !== $params['currency']) {
                    throw new \RuntimeException('Stored payment does not match the expected amount and currency.');
                }
                if ($intent->status === 'requires_payment_method') {
                    // Retry the same intent after a card update/temporary decline.
                    // A succeeded intent is retrieved above and never confirmed again.
                    $intent = $this->confirm($intent->id, $params['payment_method'], $key.':confirm:'.now()->toDateString().':'.$params['payment_method']);
                }
            } else {
                if (\Carbon\Carbon::parse($attempt->created_at)->lessThan(now()->subHours(23))) {
                    throw new \RuntimeException('Uncertain ad spend payment requires reconciliation before retry: '.$key);
                }
                $intent = $this->create($params, $key);
            }
            $this->remember($attempt->id, $intent->id);

            return $intent;
        } catch (CardException $e) {
            // A known decline is different from a lost response. Keep its intent
            // so tomorrow can retry it without creating a second payment.
            $id = $e->getError()?->payment_intent?->id;
            if (is_string($id)) {
                $this->remember($attempt->id, $id);
            }
            throw $e;
        }
    }

    private function remember(int $attemptId, string $intentId): void
    {
        DB::table('ad_spend_charge_attempts')->where('id', $attemptId)->update(['payment_intent_id' => $intentId, 'updated_at' => now()]);
    }

    protected function create(array $params, string $key): PaymentIntent
    {
        return Cashier::stripe()->paymentIntents->create($params, ['idempotency_key' => $key]);
    }

    protected function retrieve(string $id): PaymentIntent
    {
        return Cashier::stripe()->paymentIntents->retrieve($id);
    }

    protected function confirm(string $id, string $paymentMethod, string $key): PaymentIntent
    {
        return Cashier::stripe()->paymentIntents->confirm($id, ['payment_method' => $paymentMethod], ['idempotency_key' => $key]);
    }
}
