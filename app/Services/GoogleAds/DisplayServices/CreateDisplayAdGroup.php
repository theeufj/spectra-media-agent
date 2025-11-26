<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Services\AdGroupService;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateDisplayAdGroup extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Creates an ad group within an existing Display campaign.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $campaignResourceName The resource name of the parent Display campaign.
     * @param string $adGroupName The name of the ad group to create.
     * @return string|null The resource name of the created ad group, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $adGroupName): ?string
    {
        $this->ensureClient();

        // Check if ad group already exists
        $existingAdGroup = $this->getAdGroupByName($customerId, $campaignResourceName, $adGroupName);
        if ($existingAdGroup) {
            $this->logInfo("Ad Group '{$adGroupName}' already exists. Skipping creation.");
            return $existingAdGroup->getResourceName();
        }

        $adGroup = new AdGroup([
            'name' => $adGroupName,
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED,
            'type' => AdGroupType::DISPLAY_STANDARD,
        ]);

        $adGroupOperation = new AdGroupOperation();
        $adGroupOperation->setCreate($adGroup);

        try {
            $adGroupServiceClient = $this->client->getAdGroupServiceClient();
            // Fix: Use MutateAdGroupsRequest object
            $request = new \Google\Ads\GoogleAds\V22\Services\MutateAdGroupsRequest([
                'customer_id' => $customerId,
                'operations' => [$adGroupOperation],
            ]);
            $response = $adGroupServiceClient->mutateAdGroups($request);
            $newAdGroupResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Display ad group: " . $newAdGroupResourceName);
            return $newAdGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Display ad group for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    /**
     * Check if an ad group with the given name already exists in the campaign
     */
    private function getAdGroupByName(string $customerId, string $campaignResourceName, string $adGroupName): ?AdGroup
    {
        $query = "SELECT ad_group.resource_name, ad_group.name FROM ad_group WHERE ad_group.campaign = '{$campaignResourceName}' AND ad_group.name = '{$adGroupName}'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);
            $response = $googleAdsServiceClient->search($request);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getAdGroup();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching ad group by name for customer $customerId: " . $e->getMessage(), $e);
        }

        return null;
    }
}
