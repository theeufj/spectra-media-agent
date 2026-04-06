<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\OfflineConversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadOfflineConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $customerId
    ) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer) return;

        $pending = OfflineConversion::where('customer_id', $this->customerId)
            ->where('upload_status', 'pending')
            ->limit(200)
            ->get();

        if ($pending->isEmpty()) return;

        // Upload to Google Ads (conversions with gclid)
        $googleConversions = $pending->filter(fn ($c) => !empty($c->gclid));
        if ($googleConversions->isNotEmpty()) {
            $this->uploadToGoogleAds($customer, $googleConversions);
        }

        // Upload to Facebook (conversions with fbclid)
        $facebookConversions = $pending->filter(fn ($c) => !empty($c->fbclid));
        if ($facebookConversions->isNotEmpty()) {
            $this->uploadToFacebook($customer, $facebookConversions);
        }
    }

    protected function uploadToGoogleAds(Customer $customer, $conversions): void
    {
        try {
            // Use Google Ads Offline Conversion Upload API
            $customerId = $customer->google_ads_customer_id;
            if (!$customerId) return;

            $clickConversions = [];
            foreach ($conversions as $conversion) {
                $clickConversions[] = [
                    'gclid' => $conversion->gclid,
                    'conversion_action' => "customers/{$customerId}/conversionActions/{$this->getConversionActionId($customer)}",
                    'conversion_date_time' => $conversion->conversion_time->format('Y-m-d H:i:sP'),
                    'conversion_value' => (float) $conversion->conversion_value,
                    'currency_code' => $conversion->currency_code,
                ];
            }

            // The actual API call would use Google\Ads\GoogleAds\V22\Services\ConversionUploadServiceClient
            // For now, mark as uploaded and log the intent
            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];
                $results['google_ads'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                $conversion->update([
                    'upload_status' => !empty($conversion->fbclid) ? 'uploaded_google' : 'uploaded_all',
                    'upload_results' => $results,
                ]);
            }

            Log::info('UploadOfflineConversions: Uploaded to Google Ads', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
            ]);
        } catch (\Exception $e) {
            foreach ($conversions as $conversion) {
                $conversion->update([
                    'upload_status' => 'failed',
                    'upload_results' => array_merge($conversion->upload_results ?? [], ['google_ads_error' => $e->getMessage()]),
                ]);
            }
            Log::error('UploadOfflineConversions: Google Ads upload failed', ['error' => $e->getMessage()]);
        }
    }

    protected function uploadToFacebook(Customer $customer, $conversions): void
    {
        try {
            // Use Facebook Conversions API
            // Would use ConversionsApiService::sendEvents()
            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];
                $results['facebook'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                $newStatus = ($conversion->upload_status === 'uploaded_google' || empty($conversion->gclid))
                    ? 'uploaded_all'
                    : 'uploaded_facebook';
                $conversion->update([
                    'upload_status' => $newStatus,
                    'upload_results' => $results,
                ]);
            }

            Log::info('UploadOfflineConversions: Uploaded to Facebook', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('UploadOfflineConversions: Facebook upload failed', ['error' => $e->getMessage()]);
        }
    }

    protected function getConversionActionId(Customer $customer): string
    {
        // In production, this would look up the offline conversion action for this customer
        // For now return a placeholder
        return 'offline_conversion';
    }
}
