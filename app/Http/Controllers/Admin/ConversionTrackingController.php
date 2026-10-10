<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RecordSiteGoogleConversion;
use App\Models\Setting;
use App\Services\GoogleAds\OwnSiteConversionDelivery;
use App\Support\ConversionTargets;
use Inertia\Inertia;

/**
 * Admin overview of per-customer conversion tracking configuration.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class ConversionTrackingController extends Controller
{
    public function conversionTrackingIndex()
    {
        // Fallback matches config/conversions.php. It previously defaulted to
        // AW-16797144138, which owns none of this account's conversion actions —
        // so an unset config would have silently reintroduced the mismatch that
        // discarded every conversion.
        $awId = config('conversions.aw_id', 'AW-18115663500');
        $events = config('conversions.events', []);

        $actions = collect($events)->reject(fn ($def, $key) => $key === 'signup_import')->map(function ($def, $key) {
            $label = Setting::get("conversion_label.{$key}", $def['label'] ?? null);
            $isServer = ($def['mode'] ?? 'client') === 'server';
            $actionKey = $isServer ? RecordSiteGoogleConversion::actionKey($key) ?? $key : $key;
            $resourceName = Setting::get("conversion_resource_name.{$actionKey}");

            return [
                'key' => $key,
                'name' => 'Spectra — '.ucfirst(str_replace('_', ' ', $key)),
                'label' => $label,
                'send_to' => ConversionTargets::sendTo($key, $def),
                'resource_name' => $resourceName,
                'event_key' => $key === 'signup_import' ? 'signup' : $key,
                'mode' => $def['mode'] ?? 'client',
                'value' => $def['value'] ?? null,
                'currency' => $def['currency'] ?? 'USD',
                'provisioned' => $isServer ? $resourceName !== null : $label !== null,
            ];
        })->values();

        // Counts from the local AttributionConversion table (grouped by type)
        $attributionCounts = \App\Models\AttributionConversion::query()
            ->selectRaw('conversion_type, COUNT(*) as total, SUM(conversion_value) as value_sum')
            ->groupBy('conversion_type')
            ->get()
            ->keyBy('conversion_type');

        $recentSignups7d = \App\Models\User::where('created_at', '>=', now()->subDays(7))->count();
        $recentSignups30d = \App\Models\User::where('created_at', '>=', now()->subDays(30))->count();

        // Per-event totals and recent log from our own conversion event table
        $eventTotals = \App\Models\SpectraConversionEvent::query()
            ->selectRaw('event, COUNT(*) as total, SUM(value) as value_sum, MAX(created_at) as last_fired')
            ->groupBy('event')
            ->get()
            ->keyBy('event');

        $recentEvents = \App\Models\SpectraConversionEvent::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'user_id', 'gclid', 'fbclid', 'mode', 'value', 'currency', 'uploaded_to_google', 'google_request_id', 'upload_error', 'occurred_at', 'created_at', 'ad_identifiers', 'google_conversion_resource', 'google_accepted_at', 'google_processing_status', 'google_processing_checked_at', 'google_processing_details'])
            ->map(function ($event) {
                $data = $event->toArray();
                $data['google_delivery_status'] = $event->googleDeliveryStatus();
                $data['has_google_click_identifier'] = $event->hasGoogleClickIdentifier();
                $data['has_facebook_click_identifier'] = (bool) $event->fbclid;
                // Counts and reason codes suffice for diagnostics; never expose
                // the visitor's raw click identifier to the admin table.
                unset($data['ad_identifiers'], $data['gclid'], $data['fbclid']);

                return $data;
            });

        // Platform-level signal counts — how many of our own signups came via each ad platform
        $signupsByPlatform = \App\Models\User::query()
            ->selectRaw('
                COUNT(*) FILTER (WHERE gclid IS NOT NULL OR gbraid IS NOT NULL OR wbraid IS NOT NULL) AS via_google,
                COUNT(*) FILTER (WHERE fbclid IS NOT NULL) AS via_facebook,
                COUNT(*) FILTER (WHERE msclid IS NOT NULL) AS via_microsoft
            ')
            ->where('created_at', '>=', now()->subDays(30))
            ->first();

        return Inertia::render('Admin/ConversionTracking', [
            'aw_id' => $awId,
            'actions' => $actions,
            'attribution' => $attributionCounts,
            'signups_7d' => $recentSignups7d,
            'signups_30d' => $recentSignups30d,
            'customer_id' => config('conversions.google_ads_customer_id'),
            'event_totals' => $eventTotals,
            'recent_events' => $recentEvents,
            'delivery' => app(OwnSiteConversionDelivery::class)->summary(),
            'signups_by_platform' => [
                'google' => (int) ($signupsByPlatform->via_google ?? 0),
                'facebook' => (int) ($signupsByPlatform->via_facebook ?? 0),
                'microsoft' => (int) ($signupsByPlatform->via_microsoft ?? 0),
            ],
        ]);
    }
}
