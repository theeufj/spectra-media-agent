<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Services\CustomerServiceClient;
use Google\Ads\GoogleAds\V22\Services\ListAccessibleCustomersRequest;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class ListAccessibleCustomers extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function __invoke(): ?array
    {
        /** @var CustomerServiceClient $customerServiceClient */
        $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
        $response = $customerServiceClient->listAccessibleCustomers(new ListAccessibleCustomersRequest());

        $customerResourceNames = [];
        foreach ($response->getResourceNames() as $resourceName) {
            $customerResourceNames[] = $resourceName;
        }

        return $customerResourceNames;
    }
}
