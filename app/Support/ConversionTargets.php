<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Resolves the gtag `send_to` target for each client-side conversion event.
 *
 * A gtag conversion only registers when the conversion ID and the label in
 * `send_to` belong to the SAME Google Ads conversion account. Pair a valid label
 * with the wrong account and gtag reports no error — the browser fires, the
 * request 200s, and Google silently discards the conversion.
 *
 * That is exactly what happened: the frontend hardcoded AW-16797144138 while
 * every provisioned action actually lives in AW-18115663500, so ~48 real
 * conversions over 30 days were thrown away. Maximize Conversions then had no
 * signal to bid on, which is why the campaign lost ~90% of impression share to
 * Ad Rank.
 *
 * The account id is therefore never hardcoded in JS. It is read from the tag
 * snippet at provision time (see ProvisionConversionActions) and stored
 * alongside the label, so the two halves of `send_to` cannot drift apart again.
 */
class ConversionTargets
{
    /**
     * Client-side (gtag) targets, keyed by event name.
     *
     * Events without a provisioned label are omitted — the frontend treats a
     * missing target as "not ready yet" and no-ops rather than firing a
     * malformed send_to.
     *
     * @return array<string, array{send_to: string, value: float, currency: string}>
     */
    public static function forClient(): array
    {
        $targets = [];

        foreach (config('conversions.events', []) as $event => $def) {
            if (($def['mode'] ?? 'client') !== 'client') {
                continue;
            }

            $sendTo = self::sendTo($event, $def);
            if (! $sendTo) {
                continue;
            }

            $targets[$event] = [
                'send_to' => $sendTo,
                'value' => (float) ($def['value'] ?? 0),
                'currency' => $def['currency'] ?? 'USD',
            ];
        }

        return $targets;
    }

    /**
     * Build "AW-XXXXXXXXX/label" for one event, or null if not provisioned.
     *
     * Both halves prefer the value captured from Google's own tag snippet over
     * the config default, because config is a hand-maintained fallback and the
     * snippet is what Google will actually accept.
     */
    public static function sendTo(string $event, ?array $def = null): ?string
    {
        $def ??= config("conversions.events.{$event}", []);

        $label = Setting::get("conversion_label.{$event}", $def['label'] ?? null);
        if (! $label) {
            return null;
        }

        $awId = Setting::get("conversion_aw_id.{$event}")
            ?? $def['aw_id']
            ?? config('conversions.aw_id');

        if (! $awId) {
            return null;
        }

        return "{$awId}/{$label}";
    }
}
