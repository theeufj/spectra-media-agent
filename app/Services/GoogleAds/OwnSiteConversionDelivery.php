<?php

namespace App\Services\GoogleAds;

use App\Models\SpectraConversionEvent;

/** Local delivery evidence for our own website; makes no Google API calls. */
class OwnSiteConversionDelivery
{
    public function summary(int $days = 30): array
    {
        $summary = [];
        foreach (['signup', 'paid_subscription'] as $event) {
            $counts = ['registrations_or_payments' => 0, 'provider_accepted' => 0, 'processed' => 0,
                'processed_with_warnings' => 0, 'processing' => 0, 'processing_failed' => 0, 'processing_unknown' => 0, 'legacy_unverified' => 0,
                'upload_failed' => 0, 'awaiting_upload' => 0, 'no_click_identifier' => 0];
            $records = SpectraConversionEvent::where('event', $event)->where('mode', 'server_google')
                ->where('created_at', '>=', now()->subDays($days))
                ->orderBy('id')->lazyById(200);
            foreach ($records as $record) {
                $counts['registrations_or_payments']++;
                $status = $record->googleDeliveryStatus();
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $summary[$event] = $counts;
        }

        return ['days' => $days, 'events' => $summary, 'reported_in_google_ads' => 'not_verified'];
    }

    /** This is processing evidence for the current destination, never an Ads conversion count. */
    public function hasProcessedSignup(int $days = 30): bool
    {
        $resource = \App\Models\Setting::get('conversion_resource_name.signup_import');

        return is_string($resource) && SpectraConversionEvent::where('event', 'signup')
            ->where('mode', 'server_google')->where('google_conversion_resource', $resource)
            ->where('google_processing_status', 'SUCCESS')->where('uploaded_to_google', true)
            ->whereNotNull('google_request_id')->whereNotNull('occurred_at')->whereNotNull('ad_identifiers')
            ->where('google_accepted_at', '>=', now()->subDays($days))->exists();
    }
}
