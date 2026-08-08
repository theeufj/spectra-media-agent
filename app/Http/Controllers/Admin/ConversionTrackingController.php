<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
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
        $awId = config('conversions.aw_id', 'AW-16797144138');
        $events = config('conversions.events', []);

        $actions = collect($events)->map(function ($def, $key) use ($awId) {
            $label = Setting::get("conversion_label.{$key}", $def['label'] ?? null);
            $resourceName = Setting::get("conversion_resource_name.{$key}");
            $isServer = ($def['mode'] ?? 'client') === 'server';
            $eventAwId = $def['aw_id'] ?? $awId; // per-event account override

            return [
                'key' => $key,
                'name' => 'Spectra — '.ucfirst(str_replace('_', ' ', $key)),
                'label' => $label,
                'send_to' => $label ? "{$eventAwId}/{$label}" : null,
                'resource_name' => $resourceName,
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
            ->get(['id', 'event', 'user_id', 'gclid', 'fbclid', 'mode', 'value', 'uploaded_to_google', 'created_at']);

        // Platform-level signal counts — how many of our own signups came via each ad platform
        $signupsByPlatform = \App\Models\User::query()
            ->selectRaw('
                COUNT(*) FILTER (WHERE gclid IS NOT NULL) AS via_google,
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
            'signups_by_platform' => [
                'google' => (int) ($signupsByPlatform->via_google ?? 0),
                'facebook' => (int) ($signupsByPlatform->via_facebook ?? 0),
                'microsoft' => (int) ($signupsByPlatform->via_microsoft ?? 0),
            ],
        ]);
    }
}
