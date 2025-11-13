<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

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
     * @return \Illuminate\Http\RedirectResponse A redirect to Stripe.
     */
    public function checkout(Request $request)
    {
        // Get the currently authenticated user.
        // This is similar to getting a user from context in a Go middleware.
        $user = Auth::user();

        // Get the price ID from the request body. This corresponds to a Product Price in your Stripe dashboard.
        $priceId = $request->input('price_id');

        // newSubscription is a method provided by the Billable trait from Laravel Cashier.
        // It creates a new subscription checkout session for the user.
        // The `checkout()` method returns a Stripe Checkout session object, which includes the redirect URL.
        return $user->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('dashboard'), // Redirect here on successful payment.
                'cancel_url' => route('subscription.pricing'), // Redirect here if the user cancels.
            ]);
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
        // Get the currently authenticated user.
        $user = Auth::user();

        // redirectToBillingPortal is a method provided by the Billable trait.
        // It generates a secure, one-time-use URL for the user's Stripe portal session.
        return $user->redirectToBillingPortal(route('dashboard'));
    }
}
