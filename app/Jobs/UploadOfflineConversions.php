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
        if (! $customer) {
            return;
        }

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

        if ($pending->isEmpty()) {
            return;
        }

        // Per-platform idempotency: a retried row (upload_status='failed') may already
        // have succeeded on one platform, so gate each platform on its own recorded
        // status in upload_results — never re-upload a platform that already succeeded
        // (which would double-count the conversion). (JOB-3)
        $alreadyUploaded = fn ($c, $platform) => ($c->upload_results[$platform]['status'] ?? null) === 'uploaded';

        // Rows settled this run — uploaded, or charged an attempt. The
        // re-dispatch below hangs off it, so every path that leaves the batch
        // untouched must return 0.
        $settled = 0;

        // Upload to Google Ads (conversions with gclid)
        $googleConversions = $pending->filter(fn ($c) => ! empty($c->gclid) && ! $alreadyUploaded($c, 'google_ads'));
        if ($googleConversions->isNotEmpty()) {
            $settled += $this->uploadToGoogleAds($customer, $googleConversions);
        }

        // Upload to Facebook (conversions with fbclid)
        $facebookConversions = $pending->filter(fn ($c) => ! empty($c->fbclid) && ! $alreadyUploaded($c, 'facebook'));
        if ($facebookConversions->isNotEmpty()) {
            $settled += $this->uploadToFacebook($customer, $facebookConversions);
        }

        // Upload to Microsoft (conversions with msclkid)
        $microsoftConversions = $pending->filter(fn ($c) => ! empty($c->msclid) && ! $alreadyUploaded($c, 'microsoft'));
        if ($microsoftConversions->isNotEmpty()) {
            $settled += $this->uploadToMicrosoft($customer, $microsoftConversions);
        }

        // Rows carrying no click identifier at all. No platform can attribute
        // them, so none of the three branches above ever sees them: left alone
        // they stay `pending` for good, refill every batch ahead of newer rows
        // and keep the run looking like it has work to do.
        $unattributable = $pending->filter(
            fn ($c) => empty($c->gclid) && empty($c->fbclid) && empty($c->msclid)
        );
        if ($unattributable->isNotEmpty()) {
            $settled += $this->chargeAttempt(
                $unattributable,
                'unattributable',
                'No gclid, fbclid or msclid to attribute the conversion against'
            );
        }

        // A full batch means more rows are likely waiting — self-redispatch for them.
        //
        // Gated on having settled something. An account that is simply not
        // configured for any platform (no Google customer id, no pixel, no UET
        // tag) returns from every branch above without touching a row, so the
        // next run selects the same full batch and re-dispatches again: the
        // unguarded version re-queued this job every minute, for ever, and the
        // hourly RetryOfflineConversions started a fresh chain alongside it.
        if ($settled > 0 && $pending->count() >= self::BATCH_SIZE) {
            self::dispatch($this->customerId)->delay(now()->addMinute());
        }
    }

    /**
     * Record a platform's refusal against rows it never got as far as sending.
     *
     * The early returns below (no account id, no client, no conversion action)
     * left every row exactly as they found it — still `pending`, attempts still
     * 0 — so MAX_ATTEMPTS could never retire a row no configuration will ever
     * make uploadable. Charging the attempt is what lets one eventually drop out
     * of the pending set instead of blocking the batch behind it.
     *
     * @return int rows touched
     */
    protected function chargeAttempt($conversions, string $platform, string $error): int
    {
        $touched = 0;

        foreach ($conversions as $conversion) {
            $results = $conversion->upload_results ?? [];

            // Never overwrite a success recorded earlier in this same run: the
            // per-platform marker is the only thing stopping a re-upload, and a
            // re-upload double-counts the conversion.
            if (($results[$platform]['status'] ?? null) === 'uploaded') {
                continue;
            }

            $results[$platform] = [
                'status' => 'failed',
                'error' => $error,
                'attempted_at' => now()->toDateTimeString(),
            ];

            $conversion->update([
                'upload_status' => 'failed',
                'upload_results' => $results,
                'upload_attempts' => $conversion->upload_attempts + 1,
            ]);

            $touched++;
        }

        return $touched;
    }

    /** @return int rows settled — uploaded or charged an attempt */
    protected function uploadToGoogleAds(Customer $customer, $conversions): int
    {
        try {
            $customerId = $customer->google_ads_customer_id;
            if (! $customerId) {
                Log::warning('UploadOfflineConversions: Customer has no Google Ads ID', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'google_ads', 'Customer has no Google Ads customer id');
            }

            $client = $this->buildGoogleAdsClient();
            if (! $client) {
                Log::error('UploadOfflineConversions: Failed to build Google Ads client');

                return $this->chargeAttempt($conversions, 'google_ads', 'Could not build a Google Ads client');
            }

            $conversionActionResourceName = $this->getConversionActionResourceName($client, $customerId);
            if (! $conversionActionResourceName) {
                Log::error('UploadOfflineConversions: Could not resolve conversion action', ['customer_id' => $customerId]);

                return $this->chargeAttempt($conversions, 'google_ads', 'Could not resolve an UPLOAD_CLICKS conversion action');
            }

            // Data Manager ingests into the conversion action by its numeric id
            // (productDestinationId), not the full customers/{cid}/conversionActions/{id}.
            $conversionActionId = explode('/', $conversionActionResourceName)[3] ?? null;
            if (! $conversionActionId) {
                Log::error('UploadOfflineConversions: Could not parse conversion action id', ['resource' => $conversionActionResourceName]);

                return $this->chargeAttempt($conversions, 'google_ads', "Unparseable conversion action resource name {$conversionActionResourceName}");
            }

            // Upload each conversion via the Data Manager API (the legacy
            // UploadClickConversions endpoint is closed to new integrations).
            $dataManager = new DataManagerService;
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
                        'upload_status' => ! empty($conversion->fbclid) ? 'uploaded_google' : 'uploaded_all',
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

            return $uploaded + $failed;
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            foreach ($conversions as $conversion) {
                $conversion->update([
                    'upload_status' => 'failed',
                    'upload_results' => array_merge($conversion->upload_results ?? [], ['google_ads_error' => $e->getMessage()]),
                    'upload_attempts' => $conversion->upload_attempts + 1,
                ]);
            }
            Log::error('UploadOfflineConversions: Google Ads upload failed', ['error' => $e->getMessage()]);

            return $conversions->count();
        }
    }

    /** @return int rows settled — uploaded or charged an attempt */
    protected function uploadToFacebook(Customer $customer, $conversions): int
    {
        try {
            $pixelId = $customer->facebook_pixel_id;
            if (! $pixelId) {
                Log::warning('UploadOfflineConversions: Customer has no Facebook Pixel ID', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'facebook', 'Customer has no Facebook pixel id');
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

            return $conversions->count();
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::error('UploadOfflineConversions: Facebook upload failed', ['error' => $e->getMessage()]);

            return $this->chargeAttempt($conversions, 'facebook', $e->getMessage());
        }
    }

    /** @return int rows settled — uploaded or charged an attempt */
    protected function uploadToMicrosoft(Customer $customer, $conversions): int
    {
        try {
            if (! $customer->microsoft_ads_account_id) {
                Log::warning('UploadOfflineConversions: Customer has no Microsoft Ads account', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'microsoft', 'Customer has no Microsoft Ads account id');
            }

            $msService = new MicrosoftConversionTrackingService($customer);
            $uetTagId = $customer->microsoft_uet_tag_id ?? $msService->resolveUetTagId();

            if (! $uetTagId) {
                Log::warning('UploadOfflineConversions: Could not resolve UET tag ID', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'microsoft', 'Could not resolve a UET tag id');
            }

            $settled = 0;

            // One ApplyOfflineConversions call per conversion.
            //
            // This used to call createEventConversionGoal() per row, which
            // defines a brand new conversion *goal* rather than uploading a
            // conversion — so a busy account accumulated a goal per lead and
            // not one conversion was ever recorded against a click.
            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];

                // Microsoft attributes an offline conversion by click id; there
                // is nothing to attach it to without one.
                if (empty($conversion->msclid)) {
                    continue;
                }

                $settled++;

                try {
                    $result = $msService->applyOfflineConversion(
                        msclid: $conversion->msclid,
                        goalName: $conversion->conversion_name ?? 'Offline Conversion',
                        conversionTime: $conversion->conversion_time ?? $conversion->created_at ?? now(),
                        value: (float) $conversion->conversion_value,
                        currencyCode: $conversion->currency_code ?? 'USD',
                    );

                    if ($result) {
                        $results['microsoft'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                        $allDone = empty($conversion->gclid) && empty($conversion->fbclid);
                        $conversion->update([
                            'upload_status' => $allDone ? 'uploaded_all' : 'uploaded_microsoft',
                            'upload_results' => $results,
                        ]);
                    } else {
                        $results['microsoft'] = ['status' => 'failed', 'attempted_at' => now()->toDateTimeString()];
                        $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $results['microsoft'] = ['status' => 'failed', 'error' => $e->getMessage()];
                    $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                }
            }

            Log::info('UploadOfflineConversions: Microsoft upload complete', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
            ]);

            return $settled;
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::error('UploadOfflineConversions: Microsoft upload failed', ['error' => $e->getMessage()]);

            return $this->chargeAttempt($conversions, 'microsoft', $e->getMessage());
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
            $query = 'SELECT conversion_action.resource_name, conversion_action.name '
                .'FROM conversion_action '
                ."WHERE conversion_action.type = 'UPLOAD_CLICKS' "
                ."AND conversion_action.status = 'ENABLED' "
                .'LIMIT 1';

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
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::error('UploadOfflineConversions: Failed to resolve conversion action', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function buildGoogleAdsClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $configPath = storage_path('app/google_ads_php.ini');
            if (! file_exists($configPath)) {
                return null;
            }

            $mccAccount = MccAccount::getActive();
            if (! $mccAccount) {
                return null;
            }

            $mccRefreshToken = $mccAccount->getDecryptedRefreshToken();

            $oAuth2Credential = (new OAuth2TokenBuilder)
                ->fromFile($configPath)
                ->withRefreshToken($mccRefreshToken)
                ->build();

            return (new GoogleAdsClientBuilder)
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2Credential)
                ->withLoginCustomerId($mccAccount->google_customer_id)
                ->build();
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::error('UploadOfflineConversions: Failed to build client', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('UploadOfflineConversions failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
