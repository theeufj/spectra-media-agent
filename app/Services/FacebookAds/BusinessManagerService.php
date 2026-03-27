<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BusinessManagerService
 *
 * Creates and manages Facebook ad accounts on behalf of the platform's
 * Business Manager (Path A — no per-client OAuth required).
 *
 * Prerequisites / one-time setup:
 *   1. Create a Business Manager at business.facebook.com
 *   2. Create a System User with ADMIN role inside that BM
 *   3. Generate a System User token with ads_management + business_management scopes
 *   4. Set FACEBOOK_BUSINESS_MANAGER_ID and FACEBOOK_SYSTEM_USER_TOKEN in .env
 */
class BusinessManagerService
{
    protected string $apiVersion = 'v18.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';
    protected string $systemUserToken;
    protected string $businessManagerId;

    public function __construct()
    {
        $this->systemUserToken   = config('services.facebook.system_user_token', '');
        $this->businessManagerId = config('services.facebook.business_manager_id', '');
    }

    /**
     * Returns true if the platform Business Manager is configured with valid credentials.
     */
    public function isConfigured(): bool
    {
        return !empty($this->systemUserToken) && !empty($this->businessManagerId);
    }

    /**
     * Create a new ad account under the platform Business Manager and store
     * the resulting account ID on the customer record.
     *
     * The BM owns the account, so no client OAuth token is ever needed.
     *
     * @param  Customer $customer
     * @param  string   $currency   ISO 4217 currency code (default: USD)
     * @param  string   $timezone   IANA timezone (default: America/New_York)
     * @return array{success: bool, account_id?: string, error?: string}
     */
    public function provisionAdAccount(
        Customer $customer,
        string $currency = 'USD',
        string $timezone = 'America/New_York'
    ): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Facebook Business Manager not configured. Set FACEBOOK_BUSINESS_MANAGER_ID and FACEBOOK_SYSTEM_USER_TOKEN in .env'];
        }

        // If the customer already has a BM-owned account, skip creation.
        if ($customer->facebook_ads_account_id && $customer->facebook_bm_owned) {
            return ['success' => true, 'account_id' => $customer->facebook_ads_account_id];
        }

        $accountName = $customer->name . ' — Managed by Spectra';

        try {
            $response = Http::post(
                "{$this->graphApiUrl}/{$this->apiVersion}/{$this->businessManagerId}/adaccounts",
                [
                    'access_token'     => $this->systemUserToken,
                    'name'             => $accountName,
                    'currency'         => $currency,
                    'timezone_name'    => $timezone,
                    'end_advertiser'   => $this->businessManagerId,
                    'media_agency'     => 'NONE',
                    'partner'          => 'NONE',
                ]
            );

            if (!$response->successful()) {
                $error = $response->json('error.message', $response->body());
                Log::error('FacebookBusinessManagerService: Failed to create ad account', [
                    'customer_id' => $customer->id,
                    'status'      => $response->status(),
                    'error'       => $error,
                ]);
                return ['success' => false, 'error' => $error];
            }

            $rawId = $response->json('account_id') ?? $response->json('id');
            if (!$rawId) {
                return ['success' => false, 'error' => 'No account_id in API response'];
            }

            // Normalise: strip 'act_' prefix, store numeric ID
            $accountId = ltrim((string) $rawId, 'act_');

            $customer->update([
                'facebook_ads_account_id'   => $accountId,
                'facebook_bm_owned'         => true,
                // Clear any stale OAuth token fields
                'facebook_ads_access_token' => null,
                'facebook_token_expires_at' => null,
                'facebook_token_refreshed_at' => null,
                'facebook_token_is_long_lived' => false,
            ]);

            Log::info('FacebookBusinessManagerService: Ad account provisioned', [
                'customer_id' => $customer->id,
                'account_id'  => $accountId,
                'account_name' => $accountName,
            ]);

            return ['success' => true, 'account_id' => $accountId];

        } catch (\Exception $e) {
            Log::error('FacebookBusinessManagerService: Exception provisioning ad account', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify the platform System User token is valid and return basic info.
     *
     * @return array{success: bool, name?: string, error?: string}
     */
    public function verifySystemUserToken(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured'];
        }

        try {
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/me", [
                'access_token' => $this->systemUserToken,
                'fields'       => 'id,name',
            ]);

            if ($response->successful()) {
                return ['success' => true, 'name' => $response->json('name'), 'id' => $response->json('id')];
            }

            return ['success' => false, 'error' => $response->json('error.message', 'Unknown error')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get the platform System User token (used by ad-operation services
     * when acting on behalf of a BM-owned customer account).
     */
    public function getSystemUserToken(): ?string
    {
        return $this->systemUserToken ?: null;
    }
}
