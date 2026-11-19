<?php

namespace App\Services\FacebookAds;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateFacebookAdsAccount extends BaseFacebookAdsService
{
    /** @see https://developers.facebook.com/docs/business-management-apis/business-asset-management/guides/ad-accounts */
    private const API_ACCOUNT_LIMIT = 5;

    private const ATTEMPT_ACTION = 'meta_account_creation_attempted';

    private const MANUAL_SETUP_ACTION = 'meta_account_requires_manual_setup';

    private const MANUAL_SETUP_REASON = 'This Meta portfolio has reached its automatic ad account limit. A Site to Spend administrator must set up and link a dedicated Meta Business ad account for this workspace.';

    /** @see https://developers.facebook.com/docs/marketing-api/reference/ad-account/timezone-ids/v17.0 */
    private const TIMEZONES = [
        'America/Los_Angeles' => 1,
        'America/Denver' => 2,
        'America/Chicago' => 6,
        'America/New_York' => 7,
        'Australia/Perth' => 13,
        'Australia/Broken_Hill' => 14,
        'Australia/Sydney' => 15,
        'America/Vancouver' => 28,
        'America/Toronto' => 35,
        'Europe/Berlin' => 47,
        'Europe/Paris' => 57,
        'Europe/London' => 58,
        'Asia/Singapore' => 128,
        'Australia/Melbourne' => 144,
    ];

    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    protected function getAccessToken(): ?string
    {
        return config('services.facebook.system_user_token');
    }

    public static function canProvision(Customer $customer): bool
    {
        $brand = $customer->brandGuideline;

        return ! $customer->is_sandbox && $customer->hasPaidDeploymentAccess() && $customer->allowsAdPlatform('facebook')
            && ! $customer->facebook_ads_account_id && ctype_digit((string) $customer->facebook_page_id)
            && isset(self::TIMEZONES[$customer->timezone]) && $brand?->user_verified
            && ($brand->approved_version === null || $brand->approved_version === $brand->profile_version)
            && Setting::get('deployment_enabled', true) && EnabledPlatform::isEnabled('facebook');
    }

    public static function manualSetupReason(Customer $customer): ?string
    {
        $action = AgentActivity::where('customer_id', $customer->id)
            ->whereIn('action', [self::ATTEMPT_ACTION, self::MANUAL_SETUP_ACTION])->latest('id')->value('action');

        return $action === self::MANUAL_SETUP_ACTION ? self::MANUAL_SETUP_REASON : null;
    }

    /** Provision at paid research intent, without creating campaigns or setting up billing. */
    public function provision(): ?string
    {
        $customerId = $this->customer->id;

        return Cache::lock('provision-meta-ad-account:'.$customerId, 180)->block(5, function (): ?string {
            $customer = $this->customer->fresh();
            $business = (string) config('services.facebook.business_manager_id');
            if (! $customer || ! self::canProvision($customer) || ! ctype_digit($business) || ! $this->accessToken) {
                return $customer?->facebook_ads_account_id;
            }
            $this->customer = $customer;
            $page = (string) $customer->facebook_page_id;
            if (! $this->canAdvertiseAsPage($business, $page)) {
                Log::warning('Meta account provisioning requires advertising access to the workspace Page', ['customer_id' => $customer->id]);

                return null;
            }

            $name = 'Site to Spend workspace '.$customer->uuid;
            $currency = $customer->billingCurrency();
            $timezone = self::TIMEZONES[$customer->timezone];
            $reference = ['name' => $name, 'page' => $page, 'currency' => $currency, 'timezone_id' => $timezone, 'portfolio_id' => $business];
            $attempt = AgentActivity::where('customer_id', $customer->id)->where('action', self::ATTEMPT_ACTION)->first();
            $owned = $this->get("/{$business}/owned_ad_accounts", ['fields' => 'id,name,currency,timezone_id,end_advertiser', 'limit' => 100]);
            if (! is_array($owned['data'] ?? null) || isset($owned['paging']['next'])) {
                return null;
            }
            $matches = collect($owned['data'])->where('name', $name)->values();

            if ($attempt) {
                // A timeout can hide a successful create. Resume only when the
                // same portfolio returns the exact account for this recorded attempt.
                if ($attempt->details != $reference || $matches->count() !== 1 || ($matches[0]['currency'] ?? null) !== $currency
                    || (int) ($matches[0]['timezone_id'] ?? 0) !== $timezone || (string) ($matches[0]['end_advertiser'] ?? '') !== $page) {
                    return null;
                }
                $id = preg_replace('/^act_/', '', (string) ($matches[0]['id'] ?? ''));
            } else {
                $accountCount = collect($owned['data'])->pluck('id')->filter(fn ($id) => preg_match('/^act_\d+$/D', (string) $id))->unique()->count();
                if ($accountCount >= self::API_ACCOUNT_LIMIT) {
                    if (! self::manualSetupReason($customer)) {
                        AgentActivity::record('onboarding', self::MANUAL_SETUP_ACTION, self::MANUAL_SETUP_REASON, $customer->id,
                            details: ['portfolio_id' => $business, 'owned_account_count' => $accountCount], status: 'blocked');
                    }

                    return null;
                }
                if ($matches->isNotEmpty()) {
                    return null;
                }
                AgentActivity::record('onboarding', self::ATTEMPT_ACTION, 'Requested a dedicated Meta ad account.', $customer->id, details: $reference);
                $id = $this($name, $currency, $timezone)['account_id'] ?? null;
            }
            if (! ctype_digit((string) $id)) {
                return null;
            }

            return DB::transaction(function () use ($customer, $page, $id): ?string {
                $current = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if (! self::canProvision($current) || (string) $current->facebook_page_id !== $page
                    || $current->billingCurrency() !== $customer->billingCurrency() || $current->timezone !== $customer->timezone
                    || Customer::whereKeyNot($current->id)->whereIn('facebook_ads_account_id', [$id, 'act_'.$id])->exists()) {
                    return null;
                }
                $current->update(['facebook_ads_account_id' => $id, 'facebook_bm_owned' => true]);

                return $id;
            });
        });
    }

    private function canAdvertiseAsPage(string $business, string $page): bool
    {
        $userId = (string) ($this->get('/me', ['fields' => 'id'])['id'] ?? '');
        if (! ctype_digit($userId)) {
            return false;
        }
        $users = $this->get("/{$page}/assigned_users", ['business' => $business, 'fields' => 'id,tasks', 'limit' => 100]);
        $tasks = collect($users['data'] ?? [])->firstWhere('id', $userId)['tasks'] ?? [];

        return is_array($tasks) && array_intersect($tasks, ['ADVERTISE', 'MANAGE', 'PROFILE_PLUS_ADVERTISE', 'PROFILE_PLUS_FULL_CONTROL']) !== [];
    }

    /**
     * Create a new Facebook Ads account under the platform Business Manager.
     *
     * @param  string  $accountName  Name for the new ad account
     * @param  string  $currency  Currency code (e.g., 'USD', 'AUD')
     * @param  int  $timezoneId  Facebook timezone ID (e.g., 15 = Australia/Sydney, 1 = America/Los_Angeles)
     * @return ?array Array with account ID or null on failure
     */
    public function __invoke(
        string $accountName,
        string $currency = 'USD',
        int $timezoneId = 1
    ): ?array {
        try {
            $businessId = config('services.facebook.business_manager_id');

            if (! $businessId) {
                Log::error('FACEBOOK_BUSINESS_MANAGER_ID not configured');

                return null;
            }

            $pageId = (string) $this->customer->facebook_page_id;
            if (! ctype_digit($pageId)) {
                return null;
            }

            // The advertiser is the customer's selected Page on the new business-owned account.
            $response = $this->post("/{$businessId}/adaccount", [
                'name' => $accountName,
                'currency' => $currency,
                'timezone_id' => $timezoneId,
                'end_advertiser' => $pageId,
                'media_agency' => 'NONE',
                'partner' => 'NONE',
            ]);

            if ($response && preg_match('/^(?:act_)?\d+$/D', (string) ($response['id'] ?? ''))) {
                // Store the account ID (may include 'act_' prefix)
                $accountId = str_replace('act_', '', $response['id']);

                Log::info("Created Facebook Ads account for customer {$this->customer->id}", [
                    'account_id' => $accountId,
                    'account_name' => $accountName,
                    'response' => $response,
                ]);

                return [
                    'account_id' => $accountId,
                    'account_name' => $accountName,
                    'currency' => $currency,
                    'timezone_id' => $timezoneId,
                ];
            }

            Log::error('Failed to create Facebook Ads account', [
                'customer_id' => $this->customer->id,
                'account_name' => $accountName,
                'response' => $response,
            ]);

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error creating Facebook Ads account: '.$e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
                'account_name' => $accountName,
            ]);

            return null;
        }
    }

    /**
     * Get or create a Facebook Ads account.
     * If no accounts exist, create one. Otherwise, return the first available account.
     *
     * @param  string  $accountName  Name for the account if creating new
     * @param  string  $currency  Currency code
     * @param  string  $timezone  Timezone
     */
    public function getOrCreate(
        string $accountName,
        string $currency = 'USD',
        string $timezone = 'America/New_York'
    ): ?array {
        try {
            // First, try to list existing accounts
            $adAccountService = new AdAccountService($this->customer);
            $existingAccounts = $adAccountService->listAdAccounts();

            if ($existingAccounts && count($existingAccounts) > 0) {
                // Return the first existing account
                $account = $existingAccounts[0];
                Log::info("Using existing Facebook Ads account for customer {$this->customer->id}", [
                    'account_id' => $account['id'],
                    'account_name' => $account['name'],
                ]);

                return [
                    'account_id' => str_replace('act_', '', $account['id']),
                    'account_name' => $account['name'],
                    'currency' => $account['currency'] ?? $currency,
                    'timezone' => $account['timezone_name'] ?? $timezone,
                ];
            }

            // No existing accounts, create a new one
            return $this($accountName, $currency, $timezone);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error in getOrCreate: '.$e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);

            return null;
        }
    }
}
