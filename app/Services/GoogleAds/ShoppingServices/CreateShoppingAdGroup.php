<?php

namespace App\Services\GoogleAds\ShoppingServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateShoppingAdGroup extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates an ad group within an existing Shopping campaign.
     *
     * Shopping ad groups use SHOPPING_PRODUCT_ADS type.
     * The ads within are auto-generated from the Merchant Center product feed.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $campaignResourceName The resource name of the parent Shopping campaign.
     * @param string $adGroupName The name of the ad group to create.
     * @param int|null $cpcBidMicros Optional CPC bid in micros for the ad group.
     * @return string|null The resource name of the created ad group, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $adGroupName, ?int $cpcBidMicros = null): ?string
    {
        $this->ensureClient();

        $adGroupData = [
            'name' => $adGroupName,
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED,
            'type' => AdGroupType::SHOPPING_PRODUCT_ADS,
        ];

        if ($cpcBidMicros !== null) {
            $adGroupData['cpc_bid_micros'] = $cpcBidMicros;
        }

        $adGroup = new AdGroup($adGroupData);

        $adGroupOperation = new AdGroupOperation();
        $adGroupOperation->setCreate($adGroup);

        try {
            $adGroupServiceClient = $this->client->getAdGroupServiceClient();
            $request = new MutateAdGroupsRequest([
                'customer_id' => $customerId,
                'operations' => [$adGroupOperation],
            ]);
            $response = $adGroupServiceClient->mutateAdGroups($request);
            $newAdGroupResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Shopping ad group: " . $newAdGroupResourceName);
            return $newAdGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Shopping ad group for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
