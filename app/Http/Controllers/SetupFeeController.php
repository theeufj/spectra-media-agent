<?php

namespace App\Http\Controllers;

use App\Services\SetupFeeService;
use Illuminate\Http\Request;

/**
 * The one-and-done product: US$999 once, we set up their Google Ads
 * presence and hand over. No subscription, no ongoing management.
 */
class SetupFeeController extends Controller
{
    public function checkout(Request $request, SetupFeeService $service)
    {
        $user = $request->user();
        $customer = $user->customers()->find(session('active_customer_id'));

        if (! $customer) {
            return redirect()->route('quick-start')->with('flash', [
                'type' => 'error',
                'message' => 'Tell us about your business first — then you can choose the one-time setup.',
            ]);
        }

        if ($customer->isPaidSetupOnly()) {
            return redirect()->route('dashboard')->with('flash', [
                'type' => 'info',
                'message' => 'Your one-time setup is already paid — we\'re on it.',
            ]);
        }

        return \Inertia\Inertia::location($service->checkoutUrl(
            $user,
            $customer,
            route('setup-fee.success'),
            route('subscription.pricing'),
        ));
    }

    public function success(Request $request, SetupFeeService $service)
    {
        $user = $request->user();
        $customer = $user->customers()->find(session('active_customer_id'));
        $sessionId = (string) $request->query('session_id');

        if (! $customer || $sessionId === '') {
            return redirect()->route('dashboard');
        }

        if (! $service->confirm($user, $customer, $sessionId)) {
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'We couldn\'t confirm the payment. Nothing has been charged twice — try again, or contact us if your card was charged.',
            ]);
        }

        // Receipt + admin emails are the service's job (recordPayment) so
        // the webhook path and this redirect can't double-send.

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'success',
            'message' => 'Payment received — we\'re building your Google Ads setup. We\'ll email you at every step and hand you the keys when it\'s ready.',
        ]);
    }
}
