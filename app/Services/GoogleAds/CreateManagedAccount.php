<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Resources\Customer;
use Google\Ads\GoogleAds\V22\Services\CustomerOperation;
use Google\Ads\GoogleAds\V22\Services\CustomerServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer as CustomerModel;

class CreateManagedAccount extends BaseGoogleAdsService
{
    public function __construct(CustomerModel $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new managed account under the MCC account.
     *
     * @param string $managerCustomerId The MCC account ID
     * @param string $accountName The name of the new managed account
     * @param string $currencyCode Currency code (e.g., 'USD')
     * @param string $timeZone Timezone (e.g., 'America/New_York')
     * @return ?string The resource name of the created customer (e.g., 'customers/1234567890')
     */
    public function __invoke(
        string $managerCustomerId,
        string $accountName,
        string $currencyCode = 'USD',
        string $timeZone = 'America/New_York'
    ): ?string {
        try {
            $newCustomer = new Customer([
                'descriptive_name' => $accountName,
                'currency_code' => $currencyCode,
                'time_zone' => $timeZone,
            ]);

            $customerOperation = new CustomerOperation();
            $customerOperation->setCreate($newCustomer);

            /** @var CustomerServiceClient $customerServiceClient */
            $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
            $response = $customerServiceClient->mutateCustomer(
                $managerCustomerId,
                $customerOperation
            );

            if ($response->getResult()) {
                $resourceName = $response->getResult()->getResourceName();
                Log::info("Created new managed account under MCC", [
                    'manager_customer_id' => $managerCustomerId,
                    'account_name' => $accountName,
                    'resource_name' => $resourceName,
                    'customer_id' => $this->customer->id,
                ]);
                return $resourceName;
            }

            Log::error("Failed to create managed account: No result returned", [
                'manager_customer_id' => $managerCustomerId,
                'account_name' => $accountName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating managed account: " . $e->getMessage(), [
                'exception' => $e,
                'manager_customer_id' => $managerCustomerId,
                'account_name' => $accountName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }
}
