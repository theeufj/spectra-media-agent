<?php

namespace App\Support;

use App\Notifications\ProviderBillingFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * A provider has stopped answering because of money, and somebody has to know
 * within minutes.
 *
 * Google moved this project from postpay to prepay without telling anyone. The
 * balance ran dry and every Gemini text call returned 403 BILLING_DISABLED for
 * more than a day — strategy generation, brand extraction, creative, the
 * copilot and the public demo all dead at once. Nothing said so. It was found
 * from a screenshot of the demo writing no ad copy.
 *
 * Both providers are prepaid now, so this will happen again; the only question
 * is how long it takes to notice. A billing lapse is the one failure that is
 * fixed in two minutes by a human with a card, which makes speed of notice
 * worth more than almost any other reliability work here.
 *
 * Mailed rather than queued: the queue is Redis, and an outage that has already
 * taken out one dependency is not the moment to assume another is healthy.
 */
class BillingAlert
{
    /**
     * One alert per provider per hour. Enough that a lapse is impossible to
     * miss, few enough that a day-long outage is not a thousand emails.
     */
    private const EVERY_MINUTES = 60;

    /**
     * Phrases that mean "this is about money", not "this request was bad".
     *
     * Deliberately narrow: waking someone at 3am for a malformed prompt is how
     * an alert stops being read.
     *
     * @var list<string>
     */
    private const SIGNALS = [
        'billing_disabled',
        'billing to be enabled',
        'billing account',
        'payment required',
        'insufficient credit',
        'insufficient funds',
        'insufficient_quota',
        'quota exceeded',
        'exceeded your current quota',
        'account is not active',
        'subscription expired',
        'add credits',
        'top up',
    ];

    /**
     * Does this failure look like a billing problem?
     *
     * @return string|null the phrase that matched, for the email to quote
     */
    public static function detect(?string $body, ?int $statusCode = null): ?string
    {
        // 402 says it outright and needs no phrase matching.
        if ($statusCode === 402) {
            return 'HTTP 402 Payment Required';
        }

        $haystack = mb_strtolower((string) $body);

        if ($haystack === '') {
            return null;
        }

        foreach (self::SIGNALS as $signal) {
            if (str_contains($haystack, $signal)) {
                return $signal;
            }
        }

        return null;
    }

    /**
     * Detect and, if it is money, email the operator.
     *
     * Returns whether an alert was raised, which is only used for logging —
     * callers carry on with their own error handling either way.
     */
    public static function check(string $provider, ?string $body, ?int $statusCode = null): bool
    {
        $signal = self::detect($body, $statusCode);

        if ($signal === null) {
            return false;
        }

        self::raise($provider, $signal, $body);

        return true;
    }

    public static function raise(string $provider, string $signal, ?string $detail = null): void
    {
        $recipient = config('services.billing_alert_email');

        if (blank($recipient)) {
            return;
        }

        try {
            if (! Cache::add('billing-alert:'.$provider, true, now()->addMinutes(self::EVERY_MINUTES))) {
                return;
            }
        } catch (\Throwable) {
            // Cache unavailable too: send anyway. A duplicate email is a far
            // smaller problem than a silent outage.
        }

        try {
            Notification::route('mail', $recipient)
                ->notify(new ProviderBillingFailed($provider, $signal, $detail));

            Log::warning('BillingAlert: emailed the operator', [
                'provider' => $provider,
                'signal' => $signal,
            ]);
        } catch (\Throwable $e) {
            // Never let the alert become the failure.
            report($e);
            Log::error('BillingAlert: could not send the alert: '.$e->getMessage());
        }
    }
}
