<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
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
    public const REVIEW_PIXEL_PREFIX = 'Site to Spend review pixel ';

    protected string $apiVersion;

    protected string $graphApiUrl = 'https://graph.facebook.com';

    protected string $systemUserToken;

    protected string $businessManagerId;

    public function __construct()
    {
        $this->apiVersion = config('services.facebook.graph_version', 'v22.0');
        $this->systemUserToken = (string) config('services.facebook.system_user_token', '');
        $this->businessManagerId = (string) config('services.facebook.business_manager_id', '');
    }

    /**
     * Returns true if the platform Business Manager is configured with valid credentials.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->systemUserToken) && ! empty($this->businessManagerId);
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
     * @param  string  $adAccountId  Numeric account ID (with or without 'act_' prefix)
     * @return array{success: bool, account_id?: string, name?: string, error?: string}
     */
    public function assignAdAccount(Customer $customer, string $adAccountId): array
    {
        if (! $this->isConfigured()) {
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
        if (! $verify['success']) {
            return $verify;
        }

        $customer->update([
            'facebook_ads_account_id' => $accountId,
            'facebook_bm_owned' => true,
            'facebook_token_is_long_lived' => false,
        ]);

        Log::info('FacebookBusinessManagerService: Ad account assigned to customer', [
            'customer_id' => $customer->id,
            'account_id' => $accountId,
            'account_name' => $verify['name'] ?? null,
        ]);

        return ['success' => true, 'account_id' => $accountId, 'name' => $verify['name'] ?? null];
    }

    /**
     * Verify that the platform System User token has access to a given ad account.
     *
     * @param  string  $adAccountId  Numeric account ID (without 'act_' prefix)
     * @return array{success: bool, name?: string, currency?: string, error?: string}
     */
    public function verifyAdAccountAccess(string $adAccountId): array
    {
        $accountId = ltrim($adAccountId, 'act_');

        try {
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/act_{$accountId}", [
                'fields' => 'id,name,account_status,currency',
                'access_token' => $this->systemUserToken,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'name' => $response->json('name'),
                    'currency' => $response->json('currency'),
                ];
            }

            return ['success' => false, 'error' => $response->json('error.message', 'Cannot access this ad account. Ensure the System User is assigned as Admin on it.')];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{code: string, message: string}|null */
    public function launchAccountIssue(string $adAccountId, ?array &$payerEvidence = null): ?array
    {
        $accountId = preg_replace('/^act_/', '', $adAccountId);
        $unverified = ['code' => 'facebook_account_unverified',
            'message' => 'Could not verify Meta ad account access. Check the account in Meta Business Settings.'];
        if (! $this->isConfigured() || ! ctype_digit($accountId ?? '')) {
            return $unverified;
        }

        try {
            $response = Http::withToken($this->systemUserToken)->timeout(8)
                ->get("{$this->graphApiUrl}/{$this->apiVersion}/act_{$accountId}", [
                    'fields' => 'id,business,account_status,user_tasks,funding_source,currency',
                ]);
            if (! $response->successful() || $response->json('id') !== 'act_'.$accountId) {
                return $unverified;
            }
            if ((string) $response->json('business.id') !== $this->businessManagerId) {
                return ['code' => 'facebook_account_not_owned', 'message' => 'Link an ad account owned by the Site to Spend Meta business portfolio.'];
            }
            $tasks = $response->json('user_tasks');
            if (! is_array($tasks) || array_intersect($tasks, ['ADVERTISE', 'MANAGE']) === []) {
                return ['code' => 'facebook_account_role_missing', 'message' => 'Give the Site to Spend system user permission to manage campaigns on this Meta ad account.'];
            }
            $status = (int) $response->json('account_status');
            if ($status === 3) {
                return ['code' => 'facebook_account_unsettled', 'message' => 'Meta marked the ad account as unsettled. Resolve the balance in Meta Billing and payments before ads can run.'];
            }
            if (! in_array($status, [1, 8, 9], true)) {
                return ['code' => 'facebook_account_cannot_serve', 'message' => 'Meta reports that the ad account cannot currently serve ads. Check its status in Meta Business Settings.'];
            }
            if (blank($response->json('funding_source')) || (string) $response->json('funding_source') === '0') {
                return ['code' => 'facebook_account_funding_missing', 'message' => 'Add a funding source to the Meta ad account in Billing and payments before ads can run.'];
            }

            $payerEvidence = ['currency' => (string) $response->json('currency'), 'source' => (string) $response->json('funding_source')];

            return null;
        } catch (\Throwable $e) {
            report($e);

            return $unverified;
        }
    }

    /**
     * Verify the platform System User token is valid and return basic info.
     *
     * @return array{success: bool, name?: string, error?: string}
     */
    public function verifySystemUserToken(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured'];
        }

        try {
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/me", [
                'access_token' => $this->systemUserToken,
                'fields' => 'id,name',
            ]);

            if ($response->successful()) {
                return ['success' => true, 'name' => $response->json('name'), 'id' => $response->json('id')];
            }

            return ['success' => false, 'error' => $response->json('error.message', 'Unknown error')];
        } catch (\Throwable $e) {
            report($e);

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

    /**
     * Provision an isolated pixel on an ad account owned by our portfolio.
     * This never changes the workspace pixel, publishes tags or sends events.
     *
     * @return array{success: bool, pixel_id?: string, name?: string, created?: bool, linked?: bool, error?: string}
     */
    public function provisionReviewPixel(string $businessId, string $adAccountId): array
    {
        if (! $this->isConfigured() || $businessId !== $this->businessManagerId) {
            return ['success' => false, 'error' => 'Select the Site to Spend business portfolio.'];
        }

        $lock = Cache::lock('meta-review-pixel:'.$adAccountId, 75);
        if (! $lock->get()) {
            return ['success' => false, 'error' => 'A pixel request is already running for this ad account.'];
        }

        try {
            $api = Http::baseUrl("{$this->graphApiUrl}/{$this->apiVersion}")->withToken($this->systemUserToken)->asForm()->timeout(8);
            $account = $api->get($adAccountId, ['fields' => 'business']);
            if (! $account->successful() || (string) $account->json('business.id') !== $businessId) {
                return ['success' => false, 'error' => 'The selected ad account is not owned by this business portfolio.'];
            }

            $name = self::REVIEW_PIXEL_PREFIX.substr($adAccountId, 4);
            $assets = $api->get("{$businessId}/owned_pixels", ['fields' => 'id,name', 'limit' => 100]);
            if (! $assets->successful()) {
                return ['success' => false, 'error' => 'Meta did not let the app list the portfolio pixels.'];
            }
            $existing = collect($assets->json('data', []))->firstWhere('name', $name);
            $pixelId = (string) ($existing['id'] ?? '');
            $created = false;
            if ($pixelId === '') {
                if ($assets->json('paging.next')) {
                    return ['success' => false, 'error' => 'The portfolio pixel list is incomplete. No pixel was created.'];
                }
                $create = $api->post("{$businessId}/adspixels", ['name' => $name]);
                $pixelId = (string) $create->json('id');
                if (! $create->successful() || ! ctype_digit($pixelId)) {
                    $code = (int) $create->json('error.code');

                    return ['success' => false, 'error' => "Meta did not create the review pixel (code {$code})."];
                }
                $created = true;
            }

            $result = ['pixel_id' => $pixelId, 'name' => $name, 'created' => $created];
            $linkedPixels = $api->get("{$adAccountId}/adspixels", ['fields' => 'id', 'limit' => 100]);
            $alreadyLinked = $linkedPixels->successful() && collect($linkedPixels->json('data', []))->contains('id', $pixelId);
            if (! $alreadyLinked) {
                $share = $api->post("{$pixelId}/shared_accounts", [
                    'business' => $businessId,
                    'account_id' => substr($adAccountId, 4),
                ]);
                if (! $share->successful() || ! $share->json('success')) {
                    $code = (int) $share->json('error.code');

                    return $result + ['success' => false, 'error' => "Meta did not link the review pixel to the ad account (code {$code})."];
                }
                $linkedPixels = $api->get("{$adAccountId}/adspixels", ['fields' => 'id', 'limit' => 100]);
                if (! $linkedPixels->successful() || ! collect($linkedPixels->json('data', []))->contains('id', $pixelId)) {
                    return $result + ['success' => false, 'error' => 'Meta accepted the link, but it is not visible on the ad account yet. Try again to verify it.'];
                }
            }

            return $result + ['success' => true, 'linked' => true];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => 'Could not verify pixel provisioning with Meta.'];
        } finally {
            $lock->release();
        }
    }
}
