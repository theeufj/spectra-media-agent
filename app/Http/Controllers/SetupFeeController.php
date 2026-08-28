<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\SetupFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $alreadyPaid = $customer->isPaidSetupOnly();

        if (! $service->confirm($user, $customer, $sessionId)) {
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'We couldn\'t confirm the payment. Nothing has been charged twice — try again, or contact us if your card was charged.',
            ]);
        }

        if (! $alreadyPaid) {
            ActivityLogger::customer('setup_fee_paid', $customer);
            Log::info('Setup fee paid', ['customer_id' => $customer->id, 'user_id' => $user->id]);

            Mail::to($user->email)->send(
                (new \App\Mail\SetupFeeReceived($customer, $user->name))
            );
            Mail::raw(
                "One-time setup fee paid (US$999)\n\nCustomer: {$customer->name} (#{$customer->id})\nWebsite: {$customer->website}\nUser: {$user->name} <{$user->email}>\n\nBuild their account, then mark the handover from the admin customer page.",
                fn ($m) => $m->to(config('app.admin_email'))->subject("Setup fee paid: {$customer->name}")
            );
        }

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'success',
            'message' => 'Payment received — we\'re building your Google Ads setup. We\'ll email you at every step and hand you the keys when it\'s ready.',
        ]);
    }
}
