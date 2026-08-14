<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V22\Enums\ManagerLinkStatusEnum\ManagerLinkStatus;
use Google\Ads\GoogleAds\V22\Resources\CustomerClientLink;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkOperation;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerClientLinksRequest;

class CreateCustomerClientLink extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        // Use MCC credentials for linking sub-accounts
        parent::__construct($customer);
    }

    public function __invoke(string $managerAccountId, string $clientAccountId): ?array
    {
        $customerClientLink = new CustomerClientLink([
            'client_customer' => "customers/{$clientAccountId}",
            // ManagerLinkStatus, not CustomerClientLinkStatus — the latter does
            // not exist in the SDK, so this service raised a fatal "class not
            // found" on every call and had never successfully run.
            'status' => ManagerLinkStatus::PENDING,
        ]);

        $customerClientLinkOperation = new CustomerClientLinkOperation;
        $customerClientLinkOperation->setCreate($customerClientLink);

        /** @var CustomerClientLinkServiceClient $customerClientLinkServiceClient */
        $customerClientLinkServiceClient = $this->googleAdsClient->getCustomerClientLinkServiceClient();
        $request = new MutateCustomerClientLinksRequest([
            'validate_only' => $this->dryRun,
            'customer_id' => $managerAccountId,
            'operations' => [$customerClientLinkOperation],
        ]);
        $response = $customerClientLinkServiceClient->mutateCustomerClientLinks($request);

        return $response->getResults() ? ['resourceName' => $response->getResults()[0]->getResourceName()] : null;
    }
}
