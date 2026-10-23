<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V26\Services\Client\CustomerServiceClient;
use Google\Ads\GoogleAds\V26\Services\ListAccessibleCustomersRequest;

class ListAccessibleCustomers extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function __invoke(): ?array
    {
        /** @var CustomerServiceClient $customerServiceClient */
        $customerServiceClient = $this->client->getCustomerServiceClient();
        $response = $customerServiceClient->listAccessibleCustomers(new ListAccessibleCustomersRequest);

        $customerResourceNames = [];
        foreach ($response->getResourceNames() as $resourceName) {
            $customerResourceNames[] = $resourceName;
        }

        return $customerResourceNames;
    }
}
