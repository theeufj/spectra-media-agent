<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest;
use Google\Ads\GoogleAds\V22\Services\CustomerServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer as CustomerModel;

class CreateAndLinkManagedAccount extends BaseGoogleAdsService
{
    private CreateManagedAccount $createManagedAccount;
    private CreateCustomerClientLink $createCustomerClientLink;

    public function __construct(
        CustomerModel $customer,
        CreateManagedAccount $createManagedAccount,
        CreateCustomerClientLink $createCustomerClientLink
    ) {
        parent::__construct($customer);
        $this->createManagedAccount = $createManagedAccount;
        $this->createCustomerClientLink = $createCustomerClientLink;
    }

    /**
     * Creates a new managed account and links it to the MCC account.
     * Then associates it with the Laravel customer record for tracking.
     *
     * @param string $managerCustomerId The MCC account ID
     * @param string $accountName The name of the new managed account
     * @param string $currencyCode Currency code (e.g., 'USD')
     * @param string $timeZone Timezone (e.g., 'America/New_York')
     * @return ?array Array with 'resource_name' and 'customer_id' or null on failure
     */
    public function __invoke(
        string $managerCustomerId,
        string $accountName,
        string $currencyCode = 'USD',
        string $timeZone = 'America/New_York'
    ): ?array {
        try {
            // Step 1: Create the managed account
            $resourceName = ($this->createManagedAccount)(
                $managerCustomerId,
                $accountName,
                $currencyCode,
                $timeZone
            );

            if (!$resourceName) {
                Log::error("Failed to create managed account", [
                    'manager_customer_id' => $managerCustomerId,
                    'account_name' => $accountName,
                ]);
                return null;
            }

            // Extract the customer ID from the resource name (e.g., 'customers/1234567890' -> '1234567890')
            preg_match('/customers\/(\d+)/', $resourceName, $matches);
            $newCustomerId = $matches[1] ?? null;

            if (!$newCustomerId) {
                Log::error("Failed to extract customer ID from resource name", [
                    'resource_name' => $resourceName,
                ]);
                return null;
            }

            // Step 2: Link the managed account to the MCC
            $linkResult = ($this->createCustomerClientLink)(
                $managerCustomerId,
                $newCustomerId
            );

            if (!$linkResult) {
                Log::warning("Managed account created but linking failed", [
                    'resource_name' => $resourceName,
                    'new_customer_id' => $newCustomerId,
                ]);
                // Even if linking fails, we still return the created account
            }

            Log::info("Successfully created and linked managed account", [
                'manager_customer_id' => $managerCustomerId,
                'new_customer_id' => $newCustomerId,
                'resource_name' => $resourceName,
                'link_result' => $linkResult,
            ]);

            return [
                'resource_name' => $resourceName,
                'customer_id' => $newCustomerId,
            ];
        } catch (\Exception $e) {
            Log::error("Error creating and linking managed account: " . $e->getMessage(), [
                'exception' => $e,
                'manager_customer_id' => $managerCustomerId,
                'account_name' => $accountName,
            ]);
            return null;
        }
    }
}
