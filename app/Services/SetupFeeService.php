<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
            // Coupons are created in the Stripe dashboard; without this the
            // code field never appears and a promotion code has no way in.
            'allow_promotion_codes' => true,
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

        /*
           A fully discounted session is a paid session.

           Stripe reports payment_status 'no_payment_required' — not 'paid' —
           when a coupon takes the total to zero, because no PaymentIntent is
           created. Gating on 'paid' alone means a 100%-off promotion code is
           accepted at Stripe and then refused here, which reads to the customer
           as their payment vanishing.
        */
        $settled = in_array($session->payment_status, ['paid', 'no_payment_required'], true);

        if (! $belongsHere || ! $settled) {
            Log::warning('SetupFeeService: checkout session not accepted', [
                'customer_id' => $customer->id,
                'session_id' => $sessionId,
                'payment_status' => $session->payment_status ?? null,
            ]);

            return false;
        }

        $this->recordPayment($customer, $user);

        return true;
    }

    /**
     * The single place a setup-fee payment becomes real: marks the customer,
     * sends the receipt/roadmap to the buyer and the build order to the
     * admin — exactly once, whichever path got here first (the success
     * redirect, or the checkout.session.completed webhook when the buyer
     * closed the tab on Stripe's confirmation screen).
     */
    public function recordPayment(Customer $customer, User $user): bool
    {
        if ($customer->isPaidSetupOnly()) {
            return false;
        }

        $customer->forceFill([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
        ])->save();

        ActivityLogger::customer('setup_fee_paid', $customer);
        Log::info('Setup fee paid', ['customer_id' => $customer->id, 'user_id' => $user->id]);

        // Paying the setup fee IS deploy-intent — start their account build.
        \App\Jobs\ProvisionGoogleAdsAccount::dispatchIfNeeded($customer);
        // A paid customer who already has a child account need not wait for
        // campaign deployment to receive administrator access.
        \App\Jobs\InvitePaidSetupCustomer::dispatchIfReady($customer);

        /*
           And the campaign, which is the thing they actually bought.

           GenerateFirstCampaign normally runs once, off the back of the crawl,
           and only for accounts that cleared its content threshold — it is an
           unprompted gift, so it declines rather than write a generic campaign
           from thin material. Neither condition suits a paid engagement: the
           customer may have signed up, been declined for having four good pages
           instead of five, and only decided to pay afterwards. Paying is what
           commissions the campaign, so it is asked for here, and $paidFor
           lowers the bar to "there is something to write from".
        */
        if (! \App\Models\Campaign::where('customer_id', $customer->id)->exists()) {
            \App\Jobs\GenerateFirstCampaign::dispatch($customer, paidFor: true);
        }

        Mail::to($user->email)->send(new \App\Mail\SetupFeeReceived($customer, $user->name));
        Mail::raw(
            "One-time setup fee paid\n\nCustomer: {$customer->name} (#{$customer->id})\nWebsite: {$customer->website}\nUser: {$user->name} <{$user->email}>\n\nBuild their account, then mark the handover from the admin customer page.",
            fn ($m) => $m->to(config('app.admin_email'))->subject("Setup fee paid: {$customer->name}")
        );

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
