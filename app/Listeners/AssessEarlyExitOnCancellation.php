<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\EarlyExitFeeService;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * The other drift trigger: cancelling the subscription while the build we
 * did lives in an account they own. Fires the same (once-only) early-exit
 * assessment as a revoked link — whichever door they leave through, the
 * terms are assessed exactly once.
 */
class AssessEarlyExitOnCancellation
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

            $service = app(EarlyExitFeeService::class);
            foreach ($user->customers()->get() as $customer) {
                $service->assess($customer, 'subscription_cancelled');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
