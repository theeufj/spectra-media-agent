<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Enums\CustomerClientLinkStatusEnum\CustomerClientLinkStatus;
use Google\Ads\GoogleAds\V22\Resources\CustomerClientLink;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkOperation;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreateCustomerClientLink extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
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
        $response = $customerClientLinkServiceClient->mutateCustomerClientLinks(
            $managerAccountId,
            [$customerClientLinkOperation]
        );

        return $response->getResults() ? ['resourceName' => $response->getResults()[0]->getResourceName()] : null;
    }
}
