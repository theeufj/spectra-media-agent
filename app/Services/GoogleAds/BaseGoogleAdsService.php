<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Illuminate\Support\Facades\Log;

abstract class BaseGoogleAdsService
{
    protected ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient $client = null;
    protected ?Customer $customer = null;
    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->client = $this->buildClient();
    }

    /**
     * Ensure the Google Ads client is properly initialized
     * 
     * @throws \Exception if client is not available
     */
    protected function ensureClient(): void
    {
        if ($this->client === null) {
            throw new \Exception(
                "Google Ads client not initialized for customer {$this->customer->id}. " .
                "Please check: 1) OAuth credentials are valid, " .
                "2) Customer has connected their Google Ads account via OAuth"
            );
        }
    }

    protected function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $configPath = storage_path('app/google_ads_php.ini');

            if (!file_exists($configPath)) {
                Log::warning("Google Ads config file not found at {$configPath}. Skipping client build.");
                return null;
            }

            // All API calls use the platform MCC credentials.
            // Individual customers never have their own Google Ads OAuth tokens.
            $mccRefreshToken = config('googleads.mcc_refresh_token');
            $mccCustomerId = config('googleads.mcc_customer_id');

            if (!$mccRefreshToken || !$mccCustomerId) {
                Log::error('Platform MCC credentials not configured', [
                    'has_refresh_token' => !empty($mccRefreshToken),
                    'has_customer_id' => !empty($mccCustomerId),
                ]);
                return null;
            }

            $oAuth2Credential = (new OAuth2TokenBuilder())
                ->fromFile($configPath)
                ->withRefreshToken($mccRefreshToken)
                ->build();

            $builder = (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2Credential)
                ->withLoginCustomerId($mccCustomerId);

            return $builder->build();
        } catch (\Exception $e) {
            Log::error("Failed to build Google Ads client for customer {$this->customer->id}: " . $e->getMessage(), [
                'customer_id' => $this->customer->id,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[GoogleAds] " . $message, $context);
    }

    protected function logError(string $message, $exception = null): void
    {
        $context = [];
        if ($exception instanceof \Exception) {
            $context = [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ];
        }
        Log::error("[GoogleAds] " . $message, $context);
    }

    /**
     * Execute a GAQL search query using the V22 SearchGoogleAdsRequest pattern.
     */
    protected function searchQuery(string $customerId, string $query): \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsResponse
    {
        $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
        return $googleAdsServiceClient->search(
            new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ])
        );
    }
}