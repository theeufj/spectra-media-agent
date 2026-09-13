<?php

namespace App\Http\Controllers;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\CreativeBoostPurchase;
use App\Models\User;
use App\Services\CreativeQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;

class StripeWebhookController extends CashierController
{
    /**
     * Handle a Stripe webhook call.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        $type = $payload['type'] ?? 'unknown';

        // Log only the event type + id — never the full payload (PII, billing details).
        Log::info('Stripe Webhook Received: '.$type, [
            'event_id' => $payload['id'] ?? null,
        ]);

        return parent::handleWebhook($request);
    }

    /**
     * Handle customer subscription created webhook.
     *
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

            // Handle Creative Boost purchase
            $metadata = $session['metadata'] ?? [];

            // One-time setup fee: the fallback recorder for buyers who paid
            // and closed the tab before the success redirect ran. Idempotent
            // via SetupFeeService::recordPayment.
            /*
               'no_payment_required' is what Stripe reports when a promotion
               code takes the total to zero: no PaymentIntent exists, so there
               is no payment to mark paid. The session is still complete and
               the customer still bought the thing.
            */
            $settled = in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true);

            if (($metadata['purpose'] ?? null) === 'setup_fee' && $settled) {
                $customer = \App\Models\Customer::find($metadata['customer_id'] ?? null);

                if ($customer && $user->customers()->where('customers.id', $customer->id)->exists()) {
                    if (app(\App\Services\SetupFeeService::class)->recordPayment($customer, $user)) {
                        Log::info('💰 Setup fee recorded via webhook', [
                            'customer_id' => $customer->id,
                            'session_id' => $session['id'],
                        ]);
                    }
                } else {
                    Log::warning('Setup fee webhook: customer mismatch', [
                        'metadata_customer_id' => $metadata['customer_id'] ?? null,
                        'user_id' => $user->id,
                    ]);
                }
            }
            if (($metadata['type'] ?? null) === 'creative_boost' && $settled) {
                $purchaseId = $metadata['purchase_id'] ?? null;
                $purchase = $purchaseId ? CreativeBoostPurchase::find($purchaseId) : null;

                // Conditional update, not read-then-write: Stripe retries and can
                // deliver concurrently, and two deliveries both reading 'pending'
                // would both grant the boost. Whoever's UPDATE changes the row
                // wins; everyone else gets 0 and does nothing.
                $claimed = $purchase && CreativeBoostPurchase::whereKey($purchase->getKey())
                    ->where('status', 'pending')
                    ->update([
                        'stripe_checkout_session_id' => $session['id'],
                        'status' => 'completed',
                    ]) > 0;

                if ($claimed) {
                    app(CreativeQuotaService::class)->applyBoost($user, $purchase->refresh());

                    Log::info('🚀 Creative Boost applied', [
                        'user_id' => $user->id,
                        'purchase_id' => $purchase->id,
                        'images' => $purchase->image_generations,
                        'videos' => $purchase->video_generations,
                        'refinements' => $purchase->refinements,
                    ]);
                }
            }
        } else {
            Log::warning('⚠️ Checkout Completed but User Not Found', [
                'stripe_customer_id' => $stripeCustomerId,
                'session_id' => $session['id'],
            ]);
        }

        // Not parent::handleCheckoutSessionCompleted — this Cashier version
        // has no such method, so that call hit __call and threw AFTER the
        // work was done: every checkout.session.completed webhook returned
        // 500 and was retried by Stripe.
        return $this->successMethod();
    }

    /**
     * Handle invoice paid webhook.
     *
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

        return $this->successMethod(); // parent has no handleInvoicePaid — see checkout handler note
    }

    /**
     * Handle invoice payment succeeded webhook.
     *
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

        return $this->successMethod(); // parent has no handleInvoicePaymentSucceeded either
    }

    /**
     * Handle a refunded charge.
     *
     * Refunded ad-spend credit has to come back out of the balance. Nothing did
     * that: the admin refund tool called \Stripe\Refund::create and stopped, so
     * current_balance kept showing money that had already been given back and
     * the nightly billing run kept deducting live spend against it.
     *
     * Handled here as well as in Admin\RevenueController so a refund issued from
     * the Stripe dashboard settles exactly like one issued from the console.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleChargeRefunded(array $payload)
    {
        $charge = $payload['data']['object'] ?? [];

        if (! empty($charge['id'])) {
            self::recordAdSpendRefund($charge['id'], (int) ($charge['amount_refunded'] ?? 0) / 100);
        }

        return $this->successMethod();
    }

    /**
     * Take refunded money back out of the ad spend credit the charge bought.
     *
     * Settles against the charge's *cumulative* refunded total rather than
     * against one refund, which is what makes it idempotent under everything
     * Stripe does here: webhook redelivery, a second partial refund, and the
     * admin console recording the refund it just created rather than waiting
     * for the event. Whichever call runs second finds nothing outstanding.
     *
     * Charges that never bought ad spend credit — subscription invoices, setup
     * fees — have no matching credit transaction and are left alone.
     *
     * The ledger row is TYPE_REFUND and not TYPE_ADJUSTMENT deliberately:
     * adjustments count as spend already recovered in
     * AdSpendTransaction::totalDebited(), so booking a refund as one would tell
     * the admin reconciliation tool the platform had already been paid for that
     * spend and let it go uncharged.
     *
     * @param  float  $refundedTotal  Everything refunded on this charge so far, in dollars.
     */
    public static function recordAdSpendRefund(string $chargeId, float $refundedTotal): void
    {
        $creditId = AdSpendTransaction::query()
            ->where('stripe_charge_id', $chargeId)
            ->where('type', AdSpendTransaction::TYPE_CREDIT)
            ->value('ad_spend_credit_id');

        if (! $creditId) {
            return;
        }

        DB::transaction(function () use ($creditId, $chargeId, $refundedTotal) {
            // Locked read-modify-write, like every other balance mutation on
            // this model: two concurrent deliveries would otherwise both read
            // the refund as unrecorded and take it out twice. No acting user in
            // a webhook, an admin in the console — the tenant scope is inert
            // either way, but say so rather than depend on it.
            /** @var AdSpendCredit|null $credit */
            $credit = AdSpendCredit::withoutCustomerScope()
                ->whereKey($creditId)
                ->lockForUpdate()
                ->first();

            if (! $credit) {
                return;
            }

            $outstanding = round($refundedTotal - self::recordedRefundTotal($chargeId), 2);

            if ($outstanding <= 0) {
                return;
            }

            // Capped at the balance, as AdSpendCredit::recordAdjustment() caps:
            // credit already spent on ads cannot be taken back out of an empty
            // account, and the ledger records what was applied rather than what
            // was asked for, so the totals stay internally consistent.
            $applied = round(min($outstanding, (float) $credit->current_balance), 2);

            if ($applied <= 0) {
                Log::warning('Refund exceeds remaining ad spend credit — nothing left to take back', [
                    'charge_id' => $chargeId,
                    'ad_spend_credit_id' => $credit->id,
                    'outstanding' => $outstanding,
                ]);

                return;
            }

            // Through recordAdjustment() rather than writing the balance here:
            // it recalculates the balance status, so a refund that empties an
            // account leaves it marked depleted rather than active-at-zero.
            $credit->recordAdjustment(
                -$applied,
                'Stripe refund on charge '.$chargeId,
                AdSpendTransaction::TYPE_REFUND,
                $chargeId,
            );

            Log::info('Ad spend credit reduced by refund', [
                'charge_id' => $chargeId,
                'ad_spend_credit_id' => $credit->id,
                'amount' => $applied,
                'new_balance' => (float) $credit->current_balance,
            ]);
        });
    }

    /**
     * What has already been taken back out of the ledger for this charge,
     * always positive. Refund rows are stored negative, like every other debit.
     */
    private static function recordedRefundTotal(string $chargeId): float
    {
        return round((float) AdSpendTransaction::query()
            ->where('stripe_charge_id', $chargeId)
            ->where('type', AdSpendTransaction::TYPE_REFUND)
            ->sum(DB::raw('ABS(amount)')), 2);
    }
}
