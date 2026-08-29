<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one-and-done product: a single USD charge for setting up a customer's
 * Google Ads presence, after which we hand over and never manage the
 * account. Stripe Checkout in payment mode, always USD — the Cashier
 * default currency is AUD and must not leak into this price.
 */
class SetupFeeService
{
    public function checkoutUrl(User $user, Customer $customer, string $successUrl, string $cancelUrl): string
    {
        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
        }

        $session = \Stripe\Checkout\Session::create([
            'customer' => $user->stripe_id,
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) config('services.stripe.setup_fee_usd_cents', 99900),
                    'product_data' => [
                        'name' => 'One-time Google Ads setup',
                        'description' => 'Account setup, conversion tracking and launch-ready campaigns — built once, handed over to you.',
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'purpose' => 'setup_fee',
                'customer_id' => (string) $customer->id,
                'user_id' => (string) $user->id,
            ],
            // Deterministic per customer: a double-click cannot open two
            // charges for the same engagement.
            'client_reference_id' => 'setup-fee-'.$customer->id,
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ], [
            'api_key' => config('services.stripe.secret'),
        ]);

        return $session->url;
    }

    /**
     * Verify a returned checkout session with Stripe and mark the fee paid.
     * Idempotent: an already-recorded payment is simply re-acknowledged.
     * Returns true when the customer is (now) paid.
     */
    public function confirm(User $user, Customer $customer, string $sessionId): bool
    {
        if ($customer->isPaidSetupOnly()) {
            return true;
        }

        $session = $this->retrieveSession($sessionId);

        $belongsHere = ($session->metadata['customer_id'] ?? null) === (string) $customer->id
            && $session->customer === $user->stripe_id;

        if (! $belongsHere || $session->payment_status !== 'paid') {
            Log::warning('SetupFeeService: checkout session not accepted', [
                'customer_id' => $customer->id,
                'session_id' => $sessionId,
                'payment_status' => $session->payment_status ?? null,
            ]);

            return false;
        }

        $customer->forceFill([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
        ])->save();

        return true;
    }

    /**
     * The one Stripe read in the confirm path, separated so tests can
     * exercise the acceptance rules above without the network.
     */
    protected function retrieveSession(string $sessionId): object
    {
        return \Stripe\Checkout\Session::retrieve($sessionId, [
            'api_key' => config('services.stripe.secret'),
        ]);
    }
}
