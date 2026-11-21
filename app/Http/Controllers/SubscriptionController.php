<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionGoogleAdsAccount;
use App\Models\EnabledPlatform;
use App\Models\Plan;
use App\Services\Billing\ManagedSubscriptionCheckout;
use App\Services\Billing\PlatformFunding;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Cashier\Exceptions\InvalidCustomer;

class SubscriptionController extends Controller
{
    /**
     * pricing is a method on our controller struct.
     * It's responsible for rendering the pricing page view.
     *
     * @param  Request  $request  The incoming HTTP request, similar to http.Request in Go.
     * @return \Inertia\Response Returns a rendered Inertia.js view.
     */
    public function pricing(Request $request)
    {
        $plans = Plan::active()->ordered()->get();

        $customer = $request->user()?->customers()->find(session('active_customer_id'));

        $latest = $customer ? \Illuminate\Support\Facades\DB::table('channel_checkout_readiness')->where('customer_id', $customer->id)
            ->where('user_id', $request->user()->id)->latest('updated_at')->first() : null;

        return Inertia::render('Subscription/Pricing', [
            'plans' => $plans,
            'availableChannels' => array_values(array_filter(['google', 'facebook'], fn ($channel) => EnabledPlatform::isEnabled($channel))),
            'prequalification' => $latest ? ['ready' => (bool) $latest->ready, 'channels' => json_decode($latest->channels, true), 'checked_at' => $latest->updated_at] : null,
            'journey' => $customer ? app(\App\Services\Onboarding\SetupJourney::class)->forCustomer($customer) : null,
            // The one-and-done alternative: a single USD fee instead of a plan.
            'setupFee' => [
                'price_usd' => (int) round(config('services.stripe.setup_fee_usd_cents', 99900) / 100),
                'intent' => $customer?->service_type === 'setup_only',
                'paid' => (bool) $customer?->isPaidSetupOnly(),
            ],
        ]);
    }

    /**
     * checkout is our handler for initiating a subscription.
     * It creates a Stripe Checkout session and redirects the user to Stripe's hosted payment page.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkout(Request $request)
    {
        // Get the currently authenticated user.
        $user = Auth::user();

        // Look up the plan from the database to get the Stripe price ID
        $plan = Plan::active()
            ->where('stripe_price_id', $request->input('price_id'))
            ->first();

        if (! $plan || ! $plan->stripe_price_id) {
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'Invalid plan selected. Please choose a valid plan.',
            ]);
        }

        $priceId = $plan->stripe_price_id;
        $adSpendPriceId = $plan->stripe_ad_spend_price_id;

        Log::info("Initiating checkout for User ID: {$user->id} with Price ID: {$priceId} and Ad Spend Price ID: {$adSpendPriceId}");

        // Check if deployment is enabled to determine checkout mode
        $deploymentEnabled = \App\Models\Setting::get('deployment_enabled', true);

        $workspace = $user->customers()->find(session('active_customer_id'));
        if ($deploymentEnabled) {
            $request->validate(['platforms' => 'sometimes|array|min:1|max:2', 'platforms.*' => 'required|string|distinct|in:google,facebook']);
            if (! $workspace || $workspace->is_sandbox || $workspace->service_type === 'setup_only') {
                return back()->with('flash', ['type' => 'error', 'message' => 'Choose a managed workspace before starting a monthly subscription.']);
            }
            if ($workspace->adSpendPayer(allowWithoutPaymentMethod: true)?->id !== $user->id) {
                return back()->with('flash', ['type' => 'error', 'message' => 'The workspace billing contact must start its monthly subscription.']);
            }
            $managed = app(ManagedSubscriptionCheckout::class);
            if ($managed->hasSubscription($user)) {
                return redirect()->route('subscription.portal');
            }
            if ($workspace->subscribedPlan()) {
                return back()->with('flash', ['type' => 'info', 'message' => 'This workspace already has a subscription. Its subscriber can manage it in the billing portal.']);
            }
            $wanted = $request->input('platforms', $plan->slug === 'starter' ? ['google'] : []);
            if ($wanted === [] || array_diff($wanted, Plan::platformsFor($plan->slug)) !== []) {
                return back()->with('flash', ['type' => 'error', 'message' => 'Choose at least one advertising channel included in this plan.']);
            }
            // Checking a specific paid plan is the provisioning intent. No subscription or ads are created here.
            if (in_array('google', $wanted, true) && EnabledPlatform::isEnabled('google')) {
                ProvisionGoogleAdsAccount::dispatchIfNeeded($workspace);
            }
            if (in_array('facebook', $wanted, true) && EnabledPlatform::isEnabled('facebook') && ! $workspace->facebook_ads_account_id) {
                app(CreateFacebookAdsAccount::class, ['customer' => $workspace])->provision(prequalifying: true);
                $workspace->refresh();
            }
            $qualified = app(PlatformFunding::class)->forPlan($workspace, $plan, $wanted);
            \Illuminate\Support\Facades\DB::table('channel_checkout_readiness')->upsert([[
                'customer_id' => $workspace->id, 'plan_id' => $plan->id, 'user_id' => $user->id,
                'ready' => $qualified['ready'], 'channels' => json_encode($qualified['channels'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['customer_id', 'user_id', 'plan_id'], ['ready', 'channels', 'updated_at']);
            if (! $qualified['ready']) {
                return redirect()->route('subscription.pricing')->with('subscription_prequalification', $qualified)
                    ->with('flash', ['type' => 'info', 'message' => 'Your plan has not started. Check account setup below before continuing to payment.']);
            }
        }

        if ($deploymentEnabled) {
            try {
                $purchase = $managed->start($user, $workspace, $plan);
            } catch (\Stripe\Exception\ApiErrorException|\UnexpectedValueException $exception) {
                report($exception);

                return redirect()->route('subscription.pricing')->with('flash', ['type' => 'error', 'message' => 'We could not confirm checkout with Stripe. Please retry to resume your existing purchase.']);
            }

            return match ($purchase['state']) {
                'open' => Inertia::location($purchase['url']),
                'portal' => redirect()->route('subscription.portal'),
                default => redirect()->route('subscription.pricing')->with('flash', ['type' => 'info', 'message' => $purchase['message']]),
            };
        } else {
            if (! $user->hasStripeId()) {
                $user->createAsStripeCustomer();
            }
            // Testing mode - only collect payment method without charging
            // Use Stripe Checkout in setup mode
            //
            // The one checkout without a promotion-code field, and it cannot
            // have one: a setup-mode session has no line items and no amount,
            // so Stripe rejects allow_promotion_codes here. Nothing is being
            // charged, so there is nothing to discount — the code goes in when
            // the subscription is actually created, on the branch above.
            $checkout = \Stripe\Checkout\Session::create([
                'customer' => $user->stripe_id,
                'mode' => 'setup',
                'payment_method_types' => ['card'],
                'success_url' => route('dashboard').'?setup=success',
                'cancel_url' => route('subscription.pricing'),
                'customer_update' => [
                    'name' => 'auto',
                    'address' => 'auto',
                ],
            ], [
                'api_key' => config('services.stripe.secret'),
            ]);
        }

        // Redirect to Stripe using Inertia::location.
        return Inertia::location($checkout->url);
    }

    public function cancelCheckout(Request $request, string $attempt, ManagedSubscriptionCheckout $checkout)
    {
        try {
            $checkout->cancel($request->user(), $attempt);
        } catch (\Stripe\Exception\ApiErrorException|\UnexpectedValueException $exception) {
            report($exception);

            return redirect()->route('subscription.pricing')->with('flash', ['type' => 'info', 'message' => 'Stripe is confirming your checkout. Return to pricing to check again.']);
        }

        return redirect()->route('subscription.pricing');
    }

    /**
     * portal is our handler for redirecting the user to their Stripe Billing Portal.
     * This is a secure, Stripe-hosted page where users can manage their subscription,
     * update payment methods, and view invoice history.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return \Illuminate\Http\RedirectResponse A redirect to the Stripe Billing Portal.
     */
    public function portal(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if the user has an active subscription.
            if (! app(ManagedSubscriptionCheckout::class)->hasSubscription($user)) {
                return redirect()->route('subscription.pricing')->with('flash', [
                    'type' => 'info',
                    'message' => 'You do not have an active subscription. Please select a plan to continue.',
                ]);
            }

            // Generate the billing portal URL and redirect.
            $billingPortalUrl = $user->billingPortalUrl(route('dashboard'));

            return Inertia::location($billingPortalUrl);
        } catch (InvalidCustomer $e) {
            // This user is not a Stripe customer yet. Redirect them to the pricing page.
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'info',
                'message' => 'You do not have an active subscription. Please select a plan to continue.',
            ]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::warning('Invalid Stripe customer ID for user '.Auth::id().': '.$e->getMessage());

            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'info',
                'message' => 'Your billing account needs to be set up. Please select a plan to continue.',
            ]);
        }
    }
}
