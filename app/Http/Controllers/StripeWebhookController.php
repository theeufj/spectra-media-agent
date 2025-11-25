<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use App\Models\User;

class StripeWebhookController extends CashierController
{
    /**
     * Handle a Stripe webhook call.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        $type = $payload['type'] ?? 'unknown';
        
        Log::info('Stripe Webhook Received: ' . $type, [
            'payload' => $payload
        ]);

        return parent::handleWebhook($request);
    }

    /**
     * Handle customer subscription created webhook.
     *
     * @param  array  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        $subscription = $payload['data']['object'];
        $stripeCustomerId = $subscription['customer'];
        $subscriptionId = $subscription['id'];
        $status = $subscription['status'];
        $items = $subscription['items']['data'] ?? [];

        // Find the user by Stripe customer ID
        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if ($user) {
            Log::info('✅ Subscription Created for User', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'stripe_customer_id' => $stripeCustomerId,
                'subscription_id' => $subscriptionId,
                'status' => $status,
                'subscription_items' => array_map(function ($item) {
                    return [
                        'price_id' => $item['price']['id'],
                        'product_id' => $item['price']['product'],
                        'quantity' => $item['quantity'],
                        'type' => $item['price']['type'],
                    ];
                }, $items),
            ]);
        } else {
            Log::warning('⚠️ Subscription Created but User Not Found', [
                'stripe_customer_id' => $stripeCustomerId,
                'subscription_id' => $subscriptionId,
            ]);
        }

        return parent::handleCustomerSubscriptionCreated($payload);
    }

    /**
     * Handle checkout session completed webhook.
     *
     * @param  array  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCheckoutSessionCompleted(array $payload)
    {
        $session = $payload['data']['object'];
        $stripeCustomerId = $session['customer'];
        $subscriptionId = $session['subscription'] ?? null;
        $mode = $session['mode'];
        $status = $session['status'];

        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if ($user) {
            Log::info('✅ Checkout Session Completed', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'stripe_customer_id' => $stripeCustomerId,
                'subscription_id' => $subscriptionId,
                'mode' => $mode,
                'status' => $status,
                'payment_status' => $session['payment_status'],
                'amount_total' => $session['amount_total'] ?? 0,
            ]);
        } else {
            Log::warning('⚠️ Checkout Completed but User Not Found', [
                'stripe_customer_id' => $stripeCustomerId,
                'session_id' => $session['id'],
            ]);
        }

        return parent::handleCheckoutSessionCompleted($payload);
    }

    /**
     * Handle invoice paid webhook.
     *
     * @param  array  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleInvoicePaid(array $payload)
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'];
        $subscriptionId = $invoice['subscription'] ?? null;
        $amountPaid = $invoice['amount_paid'];
        $billingReason = $invoice['billing_reason'];

        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if ($user) {
            Log::info('💰 Invoice Paid', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'stripe_customer_id' => $stripeCustomerId,
                'subscription_id' => $subscriptionId,
                'amount_paid' => $amountPaid / 100, // Convert cents to dollars
                'currency' => $invoice['currency'],
                'billing_reason' => $billingReason,
                'invoice_number' => $invoice['number'],
            ]);
        }

        return parent::handleInvoicePaid($payload);
    }

    /**
     * Handle invoice payment succeeded webhook.
     *
     * @param  array  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'];
        $subscriptionId = $invoice['subscription'] ?? null;

        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if ($user) {
            // Check if this is for a subscription
            if ($subscriptionId) {
                $subscription = $user->subscriptions()->where('stripe_id', $subscriptionId)->first();
                
                Log::info('✅ Subscription Payment Succeeded', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'subscription_id' => $subscriptionId,
                    'subscription_name' => $subscription ? $subscription->type : 'unknown',
                    'amount_paid' => $invoice['amount_paid'] / 100,
                    'currency' => $invoice['currency'],
                    'invoice_number' => $invoice['number'],
                ]);
            }
        }

        return parent::handleInvoicePaymentSucceeded($payload);
    }
}
