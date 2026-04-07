<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\OfflineConversion;
use App\Services\FacebookAds\ConversionsApiService;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

            $clickConversions = [];
            foreach ($conversions as $conversion) {
                $clickConversions[] = new ClickConversion([
                    'gclid' => $conversion->gclid,
                    'conversion_action' => $conversionActionResourceName,
                    'conversion_date_time' => $conversion->conversion_time->format('Y-m-d H:i:sP'),
                    'conversion_value' => (float) $conversion->conversion_value,
                    'currency_code' => $conversion->currency_code ?? 'USD',
                ]);
            }

            $conversionUploadServiceClient = $client->getConversionUploadServiceClient();
            $response = $conversionUploadServiceClient->uploadClickConversions(
                new UploadClickConversionsRequest([
                    'customer_id' => $customerId,
                    'conversions' => $clickConversions,
                    'partial_failure' => true,
                ])
            );

            // Handle partial failures
            $partialFailure = $response->getPartialFailureError();
            $conversionArray = $conversions->values();

            foreach ($conversionArray as $index => $conversion) {
                $results = $conversion->upload_results ?? [];

                if ($partialFailure && $this->hasOperationError($partialFailure, $index)) {
                    $errorMsg = $this->getOperationError($partialFailure, $index);
                    $results['google_ads'] = ['status' => 'failed', 'error' => $errorMsg, 'attempted_at' => now()->toDateTimeString()];
                    $conversion->update([
                        'upload_status' => 'failed',
                        'upload_results' => $results,
                    ]);
                } else {
                    $results['google_ads'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                    $conversion->update([
                        'upload_status' => !empty($conversion->fbclid) ? 'uploaded_google' : 'uploaded_all',
                        'upload_results' => $results,
                    ]);
                }
            }

            Log::info('UploadOfflineConversions: Uploaded to Google Ads', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
                'had_partial_failures' => $partialFailure !== null,
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

            $mccRefreshToken = $mccAccount->exists
                ? Crypt::decryptString($mccAccount->refresh_token)
                : $mccAccount->refresh_token;

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

    protected function hasOperationError($partialFailure, int $operationIndex): bool
    {
        if (!$partialFailure || !$partialFailure->getDetails()) {
            return false;
        }
        foreach ($partialFailure->getDetails() as $detail) {
            $errors = $detail->unpack();
            if (method_exists($errors, 'getErrors')) {
                foreach ($errors->getErrors() as $error) {
                    $location = $error->getLocation();
                    if ($location) {
                        foreach ($location->getFieldPathElements() as $element) {
                            if ($element->getFieldName() === 'operations' && $element->getIndex() === $operationIndex) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

    protected function getOperationError($partialFailure, int $operationIndex): string
    {
        if (!$partialFailure || !$partialFailure->getDetails()) {
            return 'Unknown error';
        }
        foreach ($partialFailure->getDetails() as $detail) {
            $errors = $detail->unpack();
            if (method_exists($errors, 'getErrors')) {
                foreach ($errors->getErrors() as $error) {
                    $location = $error->getLocation();
                    if ($location) {
                        foreach ($location->getFieldPathElements() as $element) {
                            if ($element->getFieldName() === 'operations' && $element->getIndex() === $operationIndex) {
                                return $error->getMessage() ?? 'Operation failed';
                            }
                        }
                    }
                }
            }
        }
        return 'Unknown error';
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
