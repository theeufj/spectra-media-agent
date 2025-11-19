<?php

namespace App\Services\GoogleAds\SearchServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\AdGroup;
use Google\Ads\GoogleAds\V15\Services\AdGroupService;
use Google\Ads\GoogleAds\V15\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V15\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V15\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateSearchAdGroup extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates an ad group within an existing Search campaign.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $campaignResourceName The resource name of the parent Search campaign.
     * @param string $adGroupName The name of the ad group to create.
     * @return string|null The resource name of the created ad group, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $adGroupName): ?string
    {
        $adGroup = new AdGroup([
            'name' => $adGroupName,
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED,
            'type' => AdGroupType::SEARCH_STANDARD,
        ]);

        $adGroupOperation = new AdGroupOperation();
        $adGroupOperation->setCreate($adGroup);

        try {
            $adGroupServiceClient = $this->client->getAdGroupServiceClient();
            $response = $adGroupServiceClient->mutateAdGroups($customerId, [$adGroupOperation]);
            $newAdGroupResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Search ad group: " . $newAdGroupResourceName);
            return $newAdGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Search ad group for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
