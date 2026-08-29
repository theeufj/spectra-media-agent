<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * The ToS promises remaining ad-spend credit back within 5–7 business days
 * of cancellation. Nothing automated watched for that — the promise relied
 * on someone remembering. This alerts the admin the day a subscription is
 * cancelled with the exact refundable balance per customer, pointing at the
 * existing admin refund tool. Deliberately not an auto-refund: pending
 * spend deductions may still be reconciling, and moving money is a human
 * decision here.
 */
class AlertRefundableCreditOnCancellation
{
    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? null) !== 'customer.subscription.deleted') {
            return;
        }

        try {
            $stripeCustomerId = $event->payload['data']['object']['customer'] ?? null;
            $user = $stripeCustomerId ? User::where('stripe_id', $stripeCustomerId)->first() : null;

            if (! $user) {
                return;
            }

            foreach ($user->customers()->get() as $customer) {
                $credit = $customer->adSpendCredit;
                $balance = (float) ($credit->current_balance ?? 0);

                if ($balance <= 0) {
                    continue;
                }

                Log::info('Refundable ad-spend credit on cancellation', [
                    'customer_id' => $customer->id,
                    'balance' => $balance,
                ]);

                Mail::raw(
                    "Subscription cancelled with ad-spend credit remaining\n\n"
                    ."Customer: {$customer->name} (#{$customer->id})\n"
                    ."User: {$user->name} <{$user->email}>\n"
                    .'Remaining credit: $'.number_format($balance, 2)."\n\n"
                    .'The ToS promises this back within 5–7 business days. Wait for pending spend to reconcile, then refund via Admin → Revenue.',
                    fn ($m) => $m->to(config('app.admin_email'))
                        ->subject('Refund due: $'.number_format($balance, 2)." ad-spend credit — {$customer->name}")
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
