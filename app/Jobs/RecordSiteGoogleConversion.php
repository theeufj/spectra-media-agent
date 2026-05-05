<?php

namespace App\Jobs;

use App\Models\MccAccount;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fires a server-side Google Ads offline conversion for a sitetospend.com
 * own-site conversion event tied to a specific user (signup, etc.).
 *
 * Requires the user to have a stored gclid (captured by CaptureClickIds middleware).
 * Resource names are stored in Settings as conversion_resource_name.{event}.
 */
class RecordSiteGoogleConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected User   $user,
        protected string $event
    ) {}

    public function handle(): void
    {
        if (!$this->user->gclid) {
            return;
        }

        $resourceName = Setting::get("conversion_resource_name.{$this->event}");
        if (!$resourceName) {
            Log::debug("RecordSiteGoogleConversion: no resource_name in settings for '{$this->event}'");
            return;
        }

        $customerId = explode('/', $resourceName)[1] ?? null;
        if (!$customerId) {
            Log::error("RecordSiteGoogleConversion: could not parse customer_id from resource_name '{$resourceName}'");
            return;
        }

        $eventConfig = config("conversions.events.{$this->event}", []);

        $client = $this->buildClient();
        if (!$client) {
            Log::error("RecordSiteGoogleConversion: could not build Google Ads client for '{$this->event}'");
            return;
        }

        try {
            $conversion = new ClickConversion([
                'gclid'                => $this->user->gclid,
                'conversion_action'    => $resourceName,
                'conversion_date_time' => ($this->user->created_at ?? now())->format('Y-m-d H:i:sP'),
                'conversion_value'     => (float) ($eventConfig['value'] ?? 0),
                'currency_code'        => $eventConfig['currency'] ?? 'USD',
            ]);

            $response = $client->getConversionUploadServiceClient()->uploadClickConversions(
                new UploadClickConversionsRequest([
                    'customer_id'     => $customerId,
                    'conversions'     => [$conversion],
                    'partial_failure' => true,
                ])
            );

            $uploaded = $response->getPartialFailureError() === null;

            SpectraConversionEvent::record($this->event, $this->user->id, [
                'gclid'    => $this->user->gclid,
                'mode'     => 'server_google',
                'uploaded' => $uploaded,
            ]);

            if ($uploaded) {
                Log::info("RecordSiteGoogleConversion: uploaded '{$this->event}' for user {$this->user->id}");
            } else {
                Log::warning("RecordSiteGoogleConversion: partial failure for '{$this->event}' user {$this->user->id}", [
                    'error' => $response->getPartialFailureError()?->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("RecordSiteGoogleConversion: failed for '{$this->event}': " . $e->getMessage(), [
                'user_id' => $this->user->id,
            ]);
        }
    }

    private function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $configPath = storage_path('app/google_ads_php.ini');
            if (!file_exists($configPath)) {
                return null;
            }

            $mcc = MccAccount::getActive();
            if (!$mcc) {
                return null;
            }

            $oAuth2 = (new OAuth2TokenBuilder())
                ->fromFile($configPath)
                ->withRefreshToken($mcc->refresh_token)
                ->build();

            return (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2)
                ->withLoginCustomerId($mcc->google_customer_id)
                ->build();
        } catch (\Exception $e) {
            Log::error('RecordSiteGoogleConversion: failed to build client: ' . $e->getMessage());
            return null;
        }
    }
}
