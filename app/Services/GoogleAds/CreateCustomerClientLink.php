<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V22\Enums\ManagerLinkStatusEnum\ManagerLinkStatus;
use Google\Ads\GoogleAds\V22\Resources\CustomerClientLink;
use Google\Ads\GoogleAds\V22\Services\Client\CustomerClientLinkServiceClient;
use Google\Ads\GoogleAds\V22\Services\CustomerClientLinkOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerClientLinkRequest;

/**
 * Invite a customer's existing Google Ads account to be managed by the MCC.
 *
 * The link is created as PENDING on the manager side and does nothing until the
 * customer accepts it in their own Google Ads interface, which is the correct
 * shape: access to someone's advertising account should require them to say yes.
 *
 * This service is singular throughout — mutateCustomerClientLink,
 * MutateCustomerClientLinkRequest, setOperation, getResult. Most Google Ads
 * services take a batch of operations and this one does not, so every plural
 * spelling names a class or method the SDK never defines.
 */
class CreateCustomerClientLink extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        // Use MCC credentials for linking sub-accounts
        parent::__construct($customer);
    }

    /**
     * @return array{resourceName: string}|null
     */
    public function __invoke(string $managerAccountId, string $clientAccountId): ?array
    {
        // The base class builds the client lazily; without this $this->client
        // is null.
        $this->ensureClient();

        $operation = new CustomerClientLinkOperation;
        $operation->setCreate(new CustomerClientLink([
            'client_customer' => "customers/{$clientAccountId}",
            'status' => ManagerLinkStatus::PENDING,
        ]));

        /** @var CustomerClientLinkServiceClient $service */
        $service = $this->client->getCustomerClientLinkServiceClient();

        $response = $service->mutateCustomerClientLink(new MutateCustomerClientLinkRequest([
            'customer_id' => $managerAccountId,
            'operation' => $operation,
            'validate_only' => $this->dryRun,
        ]));

        $result = $response->getResult();

        return $result ? ['resourceName' => $result->getResourceName()] : null;
    }
}
