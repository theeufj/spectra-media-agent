<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * pricing is a method on our controller struct.
     * It's responsible for rendering the pricing page view.
     *
     * @param Request $request The incoming HTTP request, similar to http.Request in Go.
     * @return \Inertia\Response Returns a rendered Inertia.js view.
     */
    public function pricing(Request $request)
    {
        // Inertia::render is a helper that renders a React component from the `resources/js/Pages` directory.
        // It passes data from the backend (PHP) to the frontend (React) as props.
        return Inertia::render('Subscription/Pricing');
    }

    /**
     * checkout is our handler for initiating a subscription.
     * It creates a Stripe Checkout session and redirects the user to Stripe's hosted payment page.
     *
     * @param Request $request The incoming HTTP request.
     * @return \Inertia\Response A response that instructs Inertia to redirect to Stripe.
     */
    public function checkout(Request $request)
    {
        // Get the currently authenticated user.
        // This is similar to getting a user from context in a Go middleware.
        $user = Auth::user();

        // Get the price ID from the request body. This corresponds to a Product Price in your Stripe dashboard.
        $priceId = $request->input('price_id');
        $adSpendPriceId = config('services.stripe.ad_spend_price_id');

        Log::info("Initiating checkout for User ID: {$user->id} with Price ID: {$priceId} and Ad Spend Price ID: {$adSpendPriceId}");

        // Create the Stripe Checkout session.
        $checkout = $user->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('dashboard'),
                'cancel_url' => route('subscription.pricing'),
                'line_items' => [
                    [
                        'price' => $adSpendPriceId,
                        // Do NOT specify a quantity for metered items.
                    ],
                ],
            ]);

        // Redirect to Stripe using Inertia::location.
        return Inertia::location($checkout->url);
    }

    /**
     * portal is our handler for redirecting the user to their Stripe Billing Portal.
     * This is a secure, Stripe-hosted page where users can manage their subscription,
     * update payment methods, and view invoice history.
     *
     * @param Request $request The incoming HTTP request.
     * @return \Illuminate\Http\RedirectResponse A redirect to the Stripe Billing Portal.
     */
    public function portal(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if the user has an active subscription.
            if (!$user->subscribed('default')) {
                return redirect()->route('subscription.pricing')->with('flash', [
                    'type' => 'info',
                    'message' => 'You do not have an active subscription. Please select a plan to continue.'
                ]);
            }

            // Generate the billing portal URL and redirect.
            $billingPortalUrl = $user->billingPortalUrl(route('dashboard'));
            return Inertia::location($billingPortalUrl);
        } catch (InvalidCustomer $e) {
            // This user is not a Stripe customer yet. Redirect them to the pricing page.
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'info',
                'message' => 'You do not have an active subscription. Please select a plan to continue.'
            ]);
        }
    }
}
