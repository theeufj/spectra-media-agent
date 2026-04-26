<?php

namespace App\Services\GoogleAds\DSAServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateDSAAdGroup extends BaseGoogleAdsService
{
    /**
     * Create an ad group for a DSA campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $adGroupName
     * @param int    $cpcBidMicros  Default CPC bid in micros
     * @return string|null  Ad group resource name
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $adGroupName,
        int $cpcBidMicros = 1_000_000  // Default $1.00
    ): ?string {
        $this->ensureClient();

        $adGroup = new AdGroup([
            'name'             => $adGroupName,
            'campaign'         => $campaignResourceName,
            'type'             => AdGroupType::SEARCH_DYNAMIC_ADS,
            'status'           => AdGroupStatus::ENABLED,
            'cpc_bid_micros'   => $cpcBidMicros,
        ]);

        $operation = new AdGroupOperation();
        $operation->setCreate($adGroup);

        try {
            $response = $this->client->getAdGroupServiceClient()->mutateAdGroups(
                new MutateAdGroupsRequest(['customer_id' => $customerId, 'operations' => [$operation]])
            );
            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created DSA ad group: {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('CreateDSAAdGroup failed: ' . $e->getMessage());
            return null;
        }
    }
}
