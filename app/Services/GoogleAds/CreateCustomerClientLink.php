<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Enums\CustomerClientLinkStatusEnum\CustomerClientLinkStatus;
use Google\Ads\GoogleAds\V22\Resources\CustomerClientLink;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkOperation;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerClientLinksRequest;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreateCustomerClientLink extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        // Use MCC credentials for linking sub-accounts
        parent::__construct($customer, true);
    }

    public function __invoke(string $managerAccountId, string $clientAccountId): ?array
    {
        $customerClientLink = new CustomerClientLink([
            'client_customer' => "customers/{$clientAccountId}",
            'status' => CustomerClientLinkStatus::PENDING
        ]);

        $customerClientLinkOperation = new CustomerClientLinkOperation();
        $customerClientLinkOperation->setCreate($customerClientLink);

        /** @var CustomerClientLinkServiceClient $customerClientLinkServiceClient */
        $customerClientLinkServiceClient = $this->googleAdsClient->getCustomerClientLinkServiceClient();
        $request = new MutateCustomerClientLinksRequest([
            'customer_id' => $managerAccountId,
            'operations' => [$customerClientLinkOperation],
        ]);
        $response = $customerClientLinkServiceClient->mutateCustomerClientLinks($request);

        return $response->getResults() ? ['resourceName' => $response->getResults()[0]->getResourceName()] : null;
    }
}
