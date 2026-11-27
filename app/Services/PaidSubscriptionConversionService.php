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

        if (! $subscriptionId || ($invoice['status'] ?? null) !== 'paid' || ! is_int($amount) || $amount <= 0) {
            return;
        }

        $lines = data_get($invoice, 'lines.data', []);
        if (($payload['livemode'] ?? null) === true) {
            $this->measureStarterSettlement($invoice, $lines);
        }

        // The advertising event remains restricted to the first acquisition invoice.
        if (($invoice['billing_reason'] ?? null) !== 'subscription_create') {
            return;
        }

        $prices = collect($lines)
            ->map(fn ($line) => data_get($line, 'pricing.price_details.price') ?? data_get($line, 'price.id'))
            ->filter()->all();
        if (! Plan::forStripePrices($prices)->where('is_free', false)->exists()) {
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
            'verified_source' => ['kind' => 'stripe_invoice', 'id' => $invoice['id'] ?? null, 'transaction' => $subscriptionId,
                'user_id' => $user->id, 'consent' => $user->ad_measurement_consent === true, 'livemode' => ($payload['livemode'] ?? null) === true,
                'paid_cents' => $amount, 'currency' => $currency, 'paid_at' => $paidAt],
            'event' => 'paid_subscription',
            'user_id' => $user->id,
            'mode' => 'server_google',
            'value' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
            'currency' => $currency,
            'occurred_at' => Carbon::createFromTimestampUTC($paidAt),
            'ad_identifiers' => $identifiers,
            'gclid' => $user->googleClickId(),
            'google_conversion_resource' => \App\Models\Setting::get('conversion_resource_name.paid_subscription'),
            'uploaded_to_google' => false,
        ]);

        if ($user->tenant_key === 'sitetospend' && $user->fbclid && $user->ad_measurement_consent === true && ($event->meta_delivery['status'] ?? null) !== 'accepted') {
            \App\Jobs\RecordSiteFacebookConversion::dispatch($user, $event->event, receiptId: $event->id)->afterCommit();
        }
        if ($event->ad_identifiers && ! $event->uploaded_to_google) {
            RecordSiteGoogleConversion::dispatch($user, 'paid_subscription', $event->occurred_at, $event->id)->afterCommit();
        }
    }

    private function measureStarterSettlement(array $invoice, array $lines): void
    {
        if (strtoupper($invoice['currency'] ?? '') !== 'USD'
            || ! in_array($invoice['billing_reason'] ?? null, ['subscription_create', 'subscription_cycle'], true)
            || ($invoice['paid_out_of_band'] ?? false) === true) {
            return;
        }

        $paidAt = data_get($invoice, 'status_transitions.paid_at');
        $price = config('services.stripe.starter_price_id');
        if (! is_int($paidAt) || $paidAt <= 0 || ! is_string($price) || $price === '') {
            return;
        }

        // Require a positive recurring Starter line after discounts and credits.
        // Newer Stripe versions include discounts in pre-tax credit amounts.
        $includesPaidStarter = collect($lines)->contains(function ($line) use ($price): bool {
            $linePrice = data_get($line, 'pricing.price_details.price') ?? data_get($line, 'price.id');
            $lineAmount = $line['amount'] ?? null;
            $proration = data_get($line, 'parent.subscription_item_details.proration') ?? ($line['proration'] ?? false);
            $deductions = max(collect($line['discount_amounts'] ?? [])->sum('amount'), collect($line['pretax_credit_amounts'] ?? [])->sum('amount'));

            return $linePrice === $price && $proration === false && is_int($lineAmount) && $lineAmount > $deductions;
        });
        if (! $includesPaidStarter || ! Plan::offeredTo('sitetospend')->where('stripe_price_id', $price)->exists()) {
            return;
        }

        // A cycle can be the first paid month after a fully discounted first
        // invoice. The funnel records a single earliest settlement per signup.
        $user = User::where('stripe_id', $invoice['customer'])->first();
        if ($user) {
            app(SignupFunnel::class)->paid($user, Carbon::createFromTimestampUTC($paidAt));
        }
    }
}
