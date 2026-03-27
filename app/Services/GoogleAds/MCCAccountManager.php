<?php

namespace App\Services\GoogleAds;

use App\Models\Customer as CustomerModel;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Resources\Customer;
use Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest;
use Illuminate\Support\Facades\Log;

class MCCAccountManager extends BaseGoogleAdsService
{
    public function __construct(CustomerModel $customer)
    {
        // Initialize with regular customer credentials (not MCC-specific)
        // The customer's refresh token has access to their MCC via Google's OAuth
        parent::__construct($customer, false);
    }

    /**
     * Check if an account is a Manager (MCC) account or Standard account.
     *
     * @param string $accountId The Google Ads account ID
     * @return ?array Returns ['is_manager' => bool, 'descriptive_name' => string] or null on error
     */
    public function getAccountInfo(string $accountId): ?array
    {
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

            $query = "SELECT 
                        customer.id,
                        customer.descriptive_name,
                        customer.manager,
                        customer.time_zone,
                        customer.currency_code
                      FROM customer 
                      LIMIT 1";

            $request = new SearchGoogleAdsRequest([
                'customer_id' => $accountId,
                'query' => $query,
            ]);

            $response = $googleAdsServiceClient->search($request);
            $row = $response->getIterator()->current();

            if (!$row) {
                Log::warning("Could not fetch account info for {$accountId}");
                return null;
            }

            $customer = $row->getCustomer();

            return [
                'account_id' => $customer->getId(),
                'descriptive_name' => $customer->getDescriptiveName(),
                'is_manager' => $customer->getManager() ?? false,
                'time_zone' => $customer->getTimeZone(),
                'currency_code' => $customer->getCurrencyCode(),
            ];
        } catch (\Exception $e) {
            Log::error("Error fetching account info for {$accountId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * If the account is an MCC, create a new Standard account under it.
     * Updates the customer record with both the MCC ID and the new Standard account ID.
     *
     * @param string $mccAccountId The MCC account ID
     * @param string $accountName The name for the new Standard account
     * @return ?array Returns ['account_id' => string, 'resource_name' => string] or null on error
     */
    public function createStandardAccountUnderMCC(
        string $mccAccountId,
        ?string $accountName = null
    ): ?array {
        try {
            // First, verify it's actually an MCC account
            $accountInfo = $this->getAccountInfo($mccAccountId);

            if (!$accountInfo) {
                Log::error("Could not verify account info before creating sub-account", [
                    'mcc_account_id' => $mccAccountId,
                ]);
                return null;
            }

            if (!$accountInfo['is_manager']) {
                Log::warning("Account {$mccAccountId} is not an MCC account, cannot create sub-account");
                return null;
            }

            // Use provided name or default to customer name + account info
            $displayName = $accountName ?: ($this->customer->name . ' - Sub-account');

            Log::info("Creating Standard account under MCC", [
                'mcc_account_id' => $mccAccountId,
                'account_name' => $displayName,
                'customer_id' => $this->customer->id,
            ]);

            // Create the new customer object
            $newCustomer = new Customer([
                'descriptive_name' => $displayName,
                'currency_code' => $accountInfo['currency_code'] ?? 'USD',
                'time_zone' => $accountInfo['time_zone'] ?? 'America/New_York',
            ]);

            // Create the request with the MCC account ID
            $request = new CreateCustomerClientRequest([
                'customer_id' => $mccAccountId,
                'customer_client' => $newCustomer,
            ]);

            // Call the API to create the sub-account
            $this->ensureClient();
            $customerServiceClient = $this->client->getCustomerServiceClient();
            $response = $customerServiceClient->createCustomerClient($request);

            if (!$response->getResourceName()) {
                Log::error("Failed to create managed account: No resource name returned", [
                    'mcc_account_id' => $mccAccountId,
                    'account_name' => $displayName,
                ]);
                return null;
            }

            $resourceName = $response->getResourceName();

            // Extract customer ID from resource name
            preg_match('/customers\/(\d+)/', $resourceName, $matches);
            $newAccountId = $matches[1] ?? null;

            if (!$newAccountId) {
                Log::error("Failed to extract account ID from resource name", [
                    'resource_name' => $resourceName,
                ]);
                return null;
            }

            // Update the customer record with both MCC and Standard account IDs
            $this->customer->update([
                'google_ads_manager_customer_id' => $mccAccountId,
                'google_ads_customer_id' => $newAccountId,
                'google_ads_customer_is_manager' => false,
            ]);

            Log::info("Successfully created and linked Standard account under MCC", [
                'mcc_account_id' => $mccAccountId,
                'new_account_id' => $newAccountId,
                'resource_name' => $resourceName,
                'customer_id' => $this->customer->id,
            ]);

            return [
                'account_id' => $newAccountId,
                'resource_name' => $resourceName,
                'mcc_account_id' => $mccAccountId,
            ];
        } catch (\Exception $e) {
            Log::error("Error creating Standard account under MCC: " . $e->getMessage(), [
                'exception' => $e,
                'mcc_account_id' => $mccAccountId,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Handle account selection: if MCC, create Standard account; if Standard, just store the ID.
     *
     * @param string $selectedAccountId The account ID selected by the user
     * @return ?array Returns ['account_id' => string, 'is_new_account' => bool, 'mcc_account_id' => ?string]
     */
    public function handleAccountSelection(string $selectedAccountId): ?array
    {
        Log::info("Handling Google Ads account selection", [
            'selected_account_id' => $selectedAccountId,
            'customer_id' => $this->customer->id,
        ]);

        // Get account info to check if it's an MCC
        $accountInfo = $this->getAccountInfo($selectedAccountId);

        if (!$accountInfo) {
            Log::error("Could not fetch account info for selected account", [
                'account_id' => $selectedAccountId,
            ]);
            return null;
        }

        if ($accountInfo['is_manager']) {
            // It's an MCC, create a Standard account under it
            Log::info("Selected account is an MCC, creating Standard account", [
                'mcc_account_id' => $selectedAccountId,
            ]);

            $result = $this->createStandardAccountUnderMCC($selectedAccountId);

            if ($result) {
                return [
                    'account_id' => $result['account_id'],
                    'is_new_account' => true,
                    'mcc_account_id' => $selectedAccountId,
                    'resource_name' => $result['resource_name'],
                ];
            }

            return null;
        } else {
            // It's a Standard account, store it directly
            Log::info("Selected account is a Standard account, storing directly", [
                'account_id' => $selectedAccountId,
                'account_name' => $accountInfo['descriptive_name'],
            ]);

            $this->customer->update([
                'google_ads_customer_id' => $selectedAccountId,
                'google_ads_customer_is_manager' => false,
            ]);

            return [
                'account_id' => $selectedAccountId,
                'is_new_account' => false,
                'mcc_account_id' => null,
            ];
        }
    }
}
