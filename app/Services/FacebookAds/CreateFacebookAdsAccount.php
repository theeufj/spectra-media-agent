<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreateFacebookAdsAccount extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Create a new Facebook Ads account under the platform Business Manager.
     *
     * @param string $accountName Name for the new ad account
     * @param string $currency Currency code (e.g., 'USD', 'AUD')
     * @param int $timezoneId Facebook timezone ID (e.g., 37 = Australia/Sydney, 1 = America/New_York)
     * @return ?array Array with account ID or null on failure
     */
    public function __invoke(
        string $accountName,
        string $currency = 'USD',
        int $timezoneId = 1
    ): ?array {
        try {
            $businessId = config('services.facebook.business_manager_id');

            if (!$businessId) {
                Log::error("FACEBOOK_BUSINESS_MANAGER_ID not configured");
                return null;
            }

            // POST /{business_id}/adaccount — requires business admin access
            $response = $this->post("/{$businessId}/adaccount", [
                'name'           => $accountName,
                'currency'       => $currency,
                'timezone_id'    => $timezoneId,
                'end_advertiser' => $businessId,
                'media_agency'   => 'NONE',
                'partner'        => 'NONE',
            ]);

            if ($response && isset($response['id'])) {
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

            Log::error("Failed to create Facebook Ads account", [
                'customer_id' => $this->customer->id,
                'account_name' => $accountName,
                'response' => $response,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Error creating Facebook Ads account: " . $e->getMessage(), [
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
     * @param string $accountName Name for the account if creating new
     * @param string $currency Currency code
     * @param string $timezone Timezone
     * @return ?array
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
        } catch (\Exception $e) {
            Log::error("Error in getOrCreate: " . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }
}
