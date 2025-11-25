<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Services\AdGroupService;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateVideoAdGroup extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates an ad group within an existing Video campaign.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $campaignResourceName The resource name of the parent Video campaign.
     * @param string $adGroupName The name of the ad group to create.
     * @return string|null The resource name of the created ad group, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $adGroupName): ?string
    {
        $adGroup = new AdGroup([
            'name' => $adGroupName,
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED,
            'type' => AdGroupType::VIDEO_RESPONSIVE_AD_GROUP, // Specific type for video campaigns
        ]);

        $adGroupOperation = new AdGroupOperation();
        $adGroupOperation->create = $adGroup;

        try {
            $adGroupServiceClient = $this->client->getAdGroupServiceClient();
            $response = $adGroupServiceClient->mutateAdGroups($customerId, [$adGroupOperation]);
            $newAdGroupResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Video ad group: " . $newAdGroupResourceName);
            return $newAdGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Video ad group for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
