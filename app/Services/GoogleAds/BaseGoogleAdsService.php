<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

abstract class BaseGoogleAdsService
{
    protected ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient $client = null;
    protected ?Customer $customer = null;
    protected bool $useMccCredentials = false;

    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        $this->customer = $customer;
        $this->useMccCredentials = $useMccCredentials;
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
                "Please check: 1) MCC Customer ID is configured, 2) OAuth credentials are valid, " .
                "3) Customer has valid refresh token (if not using MCC credentials)"
            );
        }
    }

    protected function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $oAuth2CredentialBuilder = (new OAuth2TokenBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'));

            // For MCC operations (creating sub-accounts), use MCC credentials from ini file
            // For customer operations, use customer's refresh token
            if (!$this->useMccCredentials && $this->customer->google_ads_refresh_token) {
                $refreshToken = $this->decryptRefreshToken($this->customer->google_ads_refresh_token);
                $oAuth2CredentialBuilder->withRefreshToken($refreshToken);
            }

            $oAuth2Credential = $oAuth2CredentialBuilder->build();

            $builder = (new GoogleAdsClientBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'))
                ->withOAuth2Credential($oAuth2Credential);

            // Use MCC ID as login-customer-id ONLY if we are using MCC credentials
            if ($this->useMccCredentials) {
                $loginCustomerId = config('googleads.mcc_customer_id');
                
                if (!$loginCustomerId) {
                    Log::error("MCC Customer ID not configured for Google Ads client");
                    return null;
                }
                $builder->withLoginCustomerId($loginCustomerId);
            }

            return $builder->build();
        } catch (\Exception $e) {
            Log::error("Failed to build Google Ads client for customer {$this->customer->id}: " . $e->getMessage(), [
                'customer_id' => $this->customer->id,
                'has_refresh_token' => !empty($this->customer->google_ads_refresh_token),
                'use_mcc_credentials' => $this->useMccCredentials,
                'mcc_customer_id' => $loginCustomerId ?? 'not_configured',
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Decrypt refresh token, handling both encrypted and plain text tokens
     * 
     * @param string $token The stored token (may be encrypted or plain text)
     * @return string The decrypted/plain refresh token
     */
    protected function decryptRefreshToken(string $token): string
    {
        // Google OAuth refresh tokens start with "1//" 
        // If the token starts with this pattern, it's stored in plain text
        if (str_starts_with($token, '1//')) {
            Log::warning("Google Ads refresh token for customer {$this->customer->id} is stored in plain text. Consider encrypting it.");
            return $token;
        }

        // Otherwise, try to decrypt it
        try {
            return Crypt::decryptString($token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // If decryption fails, the token might be plain text in a different format
            // or corrupted. Log the issue and return the token as-is to let the 
            // Google Ads API validate it
            Log::warning("Failed to decrypt Google Ads refresh token for customer {$this->customer->id}. Using as plain text.", [
                'error' => $e->getMessage()
            ]);
            return $token;
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
}