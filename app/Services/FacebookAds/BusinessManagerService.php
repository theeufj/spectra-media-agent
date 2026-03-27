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
     * Verify the System User token has access to a specific ad account,
     * then assign it to the customer as a BM-owned account.
     *
     * Facebook's programmatic ad-account CREATION via API requires Marketing
     * Partner approval from Facebook (their review process). As a workaround,
     * the platform admin creates the account manually in the Business Manager UI
     * and then calls this method to associate it with a customer.
     *
     * How to create the account manually:
     *   1. Go to business.facebook.com → Business Settings → Accounts → Ad Accounts
     *   2. Click Add → Create a new ad account
     *   3. Assign the System User as Admin on that account
     *   4. Copy the numeric account ID (strip 'act_' prefix) and call this method.
     *
     * @param  Customer $customer
     * @param  string   $adAccountId  Numeric account ID (with or without 'act_' prefix)
     * @return array{success: bool, account_id?: string, name?: string, error?: string}
     */
    public function assignAdAccount(Customer $customer, string $adAccountId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Facebook Business Manager not configured.'];
        }

        // Normalise the ID
        $accountId = ltrim($adAccountId, 'act_');

        // If already assigned, just return success
        if ($customer->facebook_ads_account_id === $accountId && $customer->facebook_bm_owned) {
            return ['success' => true, 'account_id' => $accountId];
        }

        // Verify the System User can actually access this account
        $verify = $this->verifyAdAccountAccess($accountId);
        if (!$verify['success']) {
            return $verify;
        }

        $customer->update([
            'facebook_ads_account_id'      => $accountId,
            'facebook_bm_owned'            => true,
            'facebook_ads_access_token'    => null,
            'facebook_token_expires_at'    => null,
            'facebook_token_refreshed_at'  => null,
            'facebook_token_is_long_lived' => false,
        ]);

        Log::info('FacebookBusinessManagerService: Ad account assigned to customer', [
            'customer_id' => $customer->id,
            'account_id'  => $accountId,
            'account_name' => $verify['name'] ?? null,
        ]);

        return ['success' => true, 'account_id' => $accountId, 'name' => $verify['name'] ?? null];
    }

    /**
     * Verify that the platform System User token has access to a given ad account.
     *
     * @param  string $adAccountId  Numeric account ID (without 'act_' prefix)
     * @return array{success: bool, name?: string, currency?: string, error?: string}
     */
    public function verifyAdAccountAccess(string $adAccountId): array
    {
        $accountId = ltrim($adAccountId, 'act_');

        try {
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/act_{$accountId}", [
                'fields'       => 'id,name,account_status,currency',
                'access_token' => $this->systemUserToken,
            ]);

            if ($response->successful()) {
                return [
                    'success'  => true,
                    'name'     => $response->json('name'),
                    'currency' => $response->json('currency'),
                ];
            }

            return ['success' => false, 'error' => $response->json('error.message', 'Cannot access this ad account. Ensure the System User is assigned as Admin on it.')];
        } catch (\Exception $e) {
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
