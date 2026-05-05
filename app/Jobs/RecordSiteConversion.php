<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\MccAccount;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a server-side conversion to sitetospend.com's own Google Ads account
 * for users who arrived via a Google Ad (i.e. have a stored gclid).
 *
 * Server-side events (campaign_live, seven_day_return) cannot fire in the browser
 * because there's no page load — this job handles the Conversions API upload instead.
 *
 * The conversion action resource_name must be set in config/conversions.php before
 * uploads will occur. While it is null the job exits cleanly without error.
 */
class RecordSiteConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Customer $customer,
        protected string $event
    ) {}

    public function handle(): void
    {
        $config = config("conversions.events.{$this->event}");

        // Resource names are stored in Settings by conversions:provision, not in config.
        $resourceName = Setting::get("conversion_resource_name.{$this->event}");
        if (!$resourceName) {
            Log::debug("RecordSiteConversion: resource_name not in settings for '{$this->event}' — skipping");
            return;
        }

        $config = array_merge($config ?? [], ['resource_name' => $resourceName]);

        // Extract customer ID directly from the resource_name (customers/{id}/conversionActions/{id})
        // so this works even when SPECTRA_GOOGLE_ADS_CUSTOMER_ID is not set in env.
        $customerId = explode('/', $resourceName)[1] ?? config('conversions.google_ads_customer_id');
        if (!$customerId) {
            Log::error("RecordSiteConversion: cannot determine customer_id for '{$this->event}'");
            return;
        }

        $users = $this->customer->users()->whereNotNull('gclid')->get();
        if ($users->isEmpty()) {
            return;
        }

        $client = $this->buildGoogleAdsClient();
        if (!$client) {
            Log::error("RecordSiteConversion: could not build Google Ads client for '{$this->event}'");
            return;
        }

        foreach ($users as $user) {
            $this->upload($client, $customerId, $user->gclid, $config);
        }
    }

    private function upload($client, string $customerId, string $gclid, array $config): void
    {
        try {
            $conversion = new ClickConversion([
                'gclid'                => $gclid,
                'conversion_action'    => $config['resource_name'],
                'conversion_date_time' => now()->format('Y-m-d H:i:sP'),
                'conversion_value'     => (float) $config['value'],
                'currency_code'        => $config['currency'] ?? 'USD',
            ]);

            $client->getConversionUploadServiceClient()->uploadClickConversions(
                new UploadClickConversionsRequest([
                    'customer_id'     => $customerId,
                    'conversions'     => [$conversion],
                    'partial_failure' => true,
                ])
            );

            Log::info("RecordSiteConversion: uploaded '{$this->event}' for gclid {$gclid}");

            SpectraConversionEvent::record($this->event, $user->id ?? null, [
                'gclid'    => $gclid,
                'uploaded' => true,
            ]);
        } catch (\Exception $e) {
            Log::error("RecordSiteConversion: upload failed for '{$this->event}': " . $e->getMessage(), [
                'gclid'    => $gclid,
                'customer' => $this->customer->id,
            ]);
        }
    }

    private function buildGoogleAdsClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
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

            $refreshToken = $mcc->refresh_token;

            $oAuth2 = (new OAuth2TokenBuilder())
                ->fromFile($configPath)
                ->withRefreshToken($refreshToken)
                ->build();

            return (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2)
                ->withLoginCustomerId($mcc->google_customer_id)
                ->build();
        } catch (\Exception $e) {
            Log::error('RecordSiteConversion: failed to build client: ' . $e->getMessage());
            return null;
        }
    }
}
