<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

abstract class BaseGoogleAdsService
{
    protected ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient $googleAdsClient = null;
    protected ?Customer $customer = null;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->googleAdsClient = $this->buildClient();
    }

    protected function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $oAuth2CredentialBuilder = (new OAuth2TokenBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'));

            if ($this->customer->google_ads_refresh_token) {
                $oAuth2CredentialBuilder->withRefreshToken(Crypt::decryptString($this->customer->google_ads_refresh_token));
            }

            $oAuth2Credential = $oAuth2CredentialBuilder->build();

            return (new GoogleAdsClientBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'))
                ->withOAuth2Credential($oAuth2Credential)
                ->withLoginCustomerId($this->customer->google_ads_customer_id)
                ->build();
        } catch (\Exception $e) {
            Log::error("Failed to build Google Ads client for customer {$this->customer->id}: " . $e->getMessage());
            return null;
        }
    }
}