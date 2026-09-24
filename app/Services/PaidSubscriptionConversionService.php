<?php

namespace App\Services;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\Plan;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

class PaidSubscriptionConversionService
{
    /** Record acquisitions from our immediate-payment subscription checkout. */
    public function record(array $payload): void
    {
        if (! app()->environment('testing') && ($payload['livemode'] ?? false) !== true) {
            return;
        }

        $invoice = $payload['data']['object'];
        $subscription = data_get($invoice, 'parent.subscription_details.subscription') ?? ($invoice['subscription'] ?? null);
        $subscriptionId = is_array($subscription) ? ($subscription['id'] ?? null) : $subscription;
        $amount = $invoice['amount_paid'] ?? 0;

        // A renewal, upgrade, zero-value invoice, credit top-up or setup fee is
        // not a newly acquired paying subscriber. Checkout currently has no trial.
        if (! $subscriptionId || ($invoice['billing_reason'] ?? null) !== 'subscription_create'
            || ($invoice['status'] ?? null) !== 'paid' || ! is_int($amount) || $amount <= 0) {
            return;
        }

        $prices = collect(data_get($invoice, 'lines.data', []))
            ->map(fn ($line) => data_get($line, 'pricing.price_details.price') ?? data_get($line, 'price.id'))
            ->filter()->all();
        if (! Plan::whereIn('stripe_price_id', $prices)->where('is_free', false)->exists()) {
            return;
        }

        $currency = strtoupper($invoice['currency'] ?? '');
        // These are the two-decimal currencies sold by this checkout. Refuse an
        // unsupported amount rather than silently upload an incorrect value.
        if (! in_array($currency, ['USD', 'AUD'], true)) {
            throw new \UnexpectedValueException('Unsupported paid-subscription currency: '.$currency);
        }

        $user = User::where('stripe_id', $invoice['customer'])->firstOrFail();
        $paidAt = data_get($invoice, 'status_transitions.paid_at');
        if (! is_int($paidAt)) {
            throw new \UnexpectedValueException('Paid invoice is missing its payment timestamp.');
        }

        // Durable before dispatch. The unique constraint also covers concurrent
        // invoice.paid/payment_succeeded deliveries. Stripe can retry dispatch
        // failures and reuse this row; Google receives the same transaction ID.
        $identifiers = $user->googleAdIdentifiers();
        $event = SpectraConversionEvent::firstOrCreate([
            'deduplication_key' => 'paid_subscription:'.$subscriptionId,
        ], [
            'event' => 'paid_subscription',
            'user_id' => $user->id,
            'mode' => 'server_google',
            'value' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
            'currency' => $currency,
            'occurred_at' => Carbon::createFromTimestampUTC($paidAt),
            'ad_identifiers' => $identifiers,
            'gclid' => $user->googleClickId(),
            'uploaded_to_google' => false,
        ]);

        if ($event->ad_identifiers && ! $event->uploaded_to_google) {
            RecordSiteGoogleConversion::dispatch($user, 'paid_subscription', $event->occurred_at, $event->id)->afterCommit();
        }
    }
}
