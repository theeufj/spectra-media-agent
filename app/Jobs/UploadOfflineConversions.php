<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\OfflineConversion;
use App\Services\FacebookAds\ConversionsApiService;
use App\Services\GoogleAds\DataManagerService;
use App\Services\MicrosoftAds\ConversionTrackingService as MicrosoftConversionTrackingService;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UploadOfflineConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Rows processed per run; a full batch self-redispatches for the remainder. */
    protected const BATCH_SIZE = 200;

    /** Max upload attempts before a failed row is left alone (avoids hammering a bad row). */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected int $customerId
    ) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer) return;

        // Include previously-failed rows that still have retries left, so a transient
        // ad-network outage doesn't cause permanent conversion loss. (JOB-3)
        $pending = OfflineConversion::where('customer_id', $this->customerId)
            ->where(function ($q) {
                $q->where('upload_status', 'pending')
                  ->orWhere(function ($q) {
                      $q->where('upload_status', 'failed')
                        ->where('upload_attempts', '<', self::MAX_ATTEMPTS);
                  });
            })
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($pending->isEmpty()) return;

        // Per-platform idempotency: a retried row (upload_status='failed') may already
        // have succeeded on one platform, so gate each platform on its own recorded
        // status in upload_results — never re-upload a platform that already succeeded
        // (which would double-count the conversion). (JOB-3)
        $alreadyUploaded = fn ($c, $platform) => ($c->upload_results[$platform]['status'] ?? null) === 'uploaded';

        // Upload to Google Ads (conversions with gclid)
        $googleConversions = $pending->filter(fn ($c) => !empty($c->gclid) && !$alreadyUploaded($c, 'google_ads'));
        if ($googleConversions->isNotEmpty()) {
            $this->uploadToGoogleAds($customer, $googleConversions);
        }

        // Upload to Facebook (conversions with fbclid)
        $facebookConversions = $pending->filter(fn ($c) => !empty($c->fbclid) && !$alreadyUploaded($c, 'facebook'));
        if ($facebookConversions->isNotEmpty()) {
            $this->uploadToFacebook($customer, $facebookConversions);
        }

        // Upload to Microsoft (conversions with msclkid)
        $microsoftConversions = $pending->filter(fn ($c) => !empty($c->msclid) && !$alreadyUploaded($c, 'microsoft'));
        if ($microsoftConversions->isNotEmpty()) {
            $this->uploadToMicrosoft($customer, $microsoftConversions);
        }

        // A full batch means more rows are likely waiting — self-redispatch for them.
        if ($pending->count() >= self::BATCH_SIZE) {
            self::dispatch($this->customerId)->delay(now()->addMinute());
        }
    }

    protected function uploadToGoogleAds(Customer $customer, $conversions): void
    {
        try {
            $customerId = $customer->google_ads_customer_id;
            if (!$customerId) {
                Log::warning('UploadOfflineConversions: Customer has no Google Ads ID', ['customer_id' => $customer->id]);
                return;
            }

            $client = $this->buildGoogleAdsClient();
            if (!$client) {
                Log::error('UploadOfflineConversions: Failed to build Google Ads client');
                return;
            }

            $conversionActionResourceName = $this->getConversionActionResourceName($client, $customerId);
            if (!$conversionActionResourceName) {
                Log::error('UploadOfflineConversions: Could not resolve conversion action', ['customer_id' => $customerId]);
                return;
            }

            // Data Manager ingests into the conversion action by its numeric id
            // (productDestinationId), not the full customers/{cid}/conversionActions/{id}.
            $conversionActionId = explode('/', $conversionActionResourceName)[3] ?? null;
            if (!$conversionActionId) {
                Log::error('UploadOfflineConversions: Could not parse conversion action id', ['resource' => $conversionActionResourceName]);
                return;
            }

            // Upload each conversion via the Data Manager API (the legacy
            // UploadClickConversions endpoint is closed to new integrations).
            $dataManager = new DataManagerService();
            $uploaded = 0;
            $failed = 0;

            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];

                $result = $dataManager->ingestGclidConversion(
                    operatingAccountId: (string) $customerId,
                    conversionActionId: (string) $conversionActionId,
                    gclid: $conversion->gclid,
                    value: (float) $conversion->conversion_value,
                    currency: $conversion->currency_code ?? 'USD',
                    occurredAt: $conversion->conversion_time,
                    email: $conversion->email ?? null,
                );

                if ($result['success']) {
                    $uploaded++;
                    $results['google_ads'] = ['status' => 'uploaded', 'request_id' => $result['requestId'] ?? null, 'uploaded_at' => now()->toDateTimeString()];
                    $conversion->update([
                        'upload_status' => !empty($conversion->fbclid) ? 'uploaded_google' : 'uploaded_all',
                        'upload_results' => $results,
                    ]);
                } else {
                    $failed++;
                    $results['google_ads'] = ['status' => 'failed', 'error' => $result['error'] ?? 'unknown', 'attempted_at' => now()->toDateTimeString()];
                    $conversion->update([
                        'upload_status' => 'failed',
                        'upload_results' => $results,
                        'upload_attempts' => $conversion->upload_attempts + 1,
                    ]);
                }
            }

            Log::info('UploadOfflineConversions: Uploaded to Google Ads via Data Manager', [
                'customer_id' => $customer->id,
                'uploaded' => $uploaded,
                'failed' => $failed,
            ]);
        } catch (\Exception $e) {
            foreach ($conversions as $conversion) {
                $conversion->update([
                    'upload_status' => 'failed',
                    'upload_results' => array_merge($conversion->upload_results ?? [], ['google_ads_error' => $e->getMessage()]),
                    'upload_attempts' => $conversion->upload_attempts + 1,
                ]);
            }
            Log::error('UploadOfflineConversions: Google Ads upload failed', ['error' => $e->getMessage()]);
        }
    }

    protected function uploadToFacebook(Customer $customer, $conversions): void
    {
        try {
            $pixelId = $customer->facebook_pixel_id;
            if (!$pixelId) {
                Log::warning('UploadOfflineConversions: Customer has no Facebook Pixel ID', ['customer_id' => $customer->id]);
                return;
            }

            $capiService = new ConversionsApiService($customer);

            $events = [];
            foreach ($conversions as $conversion) {
                $events[] = [
                    'event_name' => $conversion->conversion_name ?? 'OfflineConversion',
                    'event_time' => $conversion->conversion_time->timestamp,
                    'action_source' => 'system_generated',
                    'user_data' => [
                        'fbc' => "fb.1.{$conversion->conversion_time->timestamp}.{$conversion->fbclid}",
                    ],
                    'custom_data' => [
                        'value' => (float) $conversion->conversion_value,
                        'currency' => $conversion->currency_code ?? 'USD',
                    ],
                ];
            }

            $result = $capiService->sendEvents($pixelId, $events);

            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];

                if ($result) {
                    $results['facebook'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                    $newStatus = ($conversion->upload_status === 'uploaded_google' || empty($conversion->gclid))
                        ? 'uploaded_all'
                        : 'uploaded_facebook';
                    $conversion->update([
                        'upload_status' => $newStatus,
                        'upload_results' => $results,
                    ]);
                } else {
                    $results['facebook'] = ['status' => 'failed', 'attempted_at' => now()->toDateTimeString()];
                    $conversion->update([
                        'upload_status' => 'failed',
                        'upload_results' => $results,
                        'upload_attempts' => $conversion->upload_attempts + 1,
                    ]);
                }
            }

            Log::info('UploadOfflineConversions: Uploaded to Facebook', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('UploadOfflineConversions: Facebook upload failed', ['error' => $e->getMessage()]);
        }
    }

    protected function uploadToMicrosoft(Customer $customer, $conversions): void
    {
        try {
            if (!$customer->microsoft_ads_account_id) {
                Log::warning('UploadOfflineConversions: Customer has no Microsoft Ads account', ['customer_id' => $customer->id]);
                return;
            }

            $msService = new MicrosoftConversionTrackingService($customer);
            $uetTagId  = $customer->microsoft_uet_tag_id ?? $msService->resolveUetTagId();

            if (!$uetTagId) {
                Log::warning('UploadOfflineConversions: Could not resolve UET tag ID', ['customer_id' => $customer->id]);
                return;
            }

            // Microsoft uses offline conversion goals; each upload is one SOAP call per conversion
            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];

                try {
                    $result = $msService->createEventConversionGoal([
                        'name'                      => $conversion->conversion_name ?? 'Offline Conversion',
                        'uet_tag_id'                => $uetTagId,
                        'action_expression'         => 'offline_conversion',
                        'conversion_window_minutes' => 43200, // 30 days
                        'revenue_type'              => 'FixedValue',
                        'revenue_value'             => (float) $conversion->conversion_value,
                        'currency_code'             => $conversion->currency_code ?? 'USD',
                    ]);

                    if ($result) {
                        $results['microsoft'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                        $allDone = empty($conversion->gclid) && empty($conversion->fbclid);
                        $conversion->update([
                            'upload_status'  => $allDone ? 'uploaded_all' : 'uploaded_microsoft',
                            'upload_results' => $results,
                        ]);
                    } else {
                        $results['microsoft'] = ['status' => 'failed', 'attempted_at' => now()->toDateTimeString()];
                        $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                    }
                } catch (\Exception $e) {
                    $results['microsoft'] = ['status' => 'failed', 'error' => $e->getMessage()];
                    $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                }
            }

            Log::info('UploadOfflineConversions: Microsoft upload complete', [
                'customer_id' => $customer->id,
                'count'       => $conversions->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('UploadOfflineConversions: Microsoft upload failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get or create the offline conversion action resource name.
     */
    protected function getConversionActionResourceName($client, string $customerId): ?string
    {
        $cacheKey = "offline_conversion_action:{$customerId}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            // Query for existing offline conversion action
            $googleAdsServiceClient = $client->getGoogleAdsServiceClient();
            $query = "SELECT conversion_action.resource_name, conversion_action.name "
                . "FROM conversion_action "
                . "WHERE conversion_action.type = 'UPLOAD_CLICKS' "
                . "AND conversion_action.status = 'ENABLED' "
                . "LIMIT 1";

            $response = $googleAdsServiceClient->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $query,
                ])
            );

            foreach ($response->iterateAllElements() as $row) {
                $resourceName = $row->getConversionAction()->getResourceName();
                Cache::put($cacheKey, $resourceName, now()->addHours(24));
                return $resourceName;
            }

            // No existing action found — create one
            $conversionActionService = new \App\Services\GoogleAds\CommonServices\CreateConversionAction(
                Customer::find($this->customerId)
            );
            $resourceName = $conversionActionService(
                $customerId,
                'Offline Conversion',
                \Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory::DEFAULT
            );

            if ($resourceName) {
                Cache::put($cacheKey, $resourceName, now()->addHours(24));
            }

            return $resourceName;
        } catch (\Exception $e) {
            Log::error('UploadOfflineConversions: Failed to resolve conversion action', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function buildGoogleAdsClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $configPath = storage_path('app/google_ads_php.ini');
            if (!file_exists($configPath)) {
                return null;
            }

            $mccAccount = MccAccount::getActive();
            if (!$mccAccount) {
                return null;
            }

            $mccRefreshToken = $mccAccount->getDecryptedRefreshToken();

            $oAuth2Credential = (new OAuth2TokenBuilder())
                ->fromFile($configPath)
                ->withRefreshToken($mccRefreshToken)
                ->build();

            return (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2Credential)
                ->withLoginCustomerId($mccAccount->google_customer_id)
                ->build();
        } catch (\Exception $e) {
            Log::error('UploadOfflineConversions: Failed to build client', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('UploadOfflineConversions failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
