<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\LocationInfo;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class SetLocationBidAdjustment extends BaseGoogleAdsService
{
    /**
     * Set a bid modifier for a geographic location on a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $geoTargetConstant e.g. 'geoTargetConstants/2036' for Australia
     * @param float $bidModifier 1.0=no change, 1.2=+20%, 0.8=-20%
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $geoTargetConstant, float $bidModifier): ?string
    {
        $this->ensureClient();

        $locationInfo = new LocationInfo([
            'geo_target_constant' => $geoTargetConstant,
        ]);

        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'location' => $locationInfo,
            'bid_modifier' => $bidModifier,
        ]);

        $operation = new CampaignCriterionOperation();
        $operation->setCreate($campaignCriterion);

        try {
            $response = $this->client->getCampaignCriterionServiceClient()->mutateCampaignCriteria(
                new MutateCampaignCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Set location bid adjustment ({$bidModifier}x) for {$geoTargetConstant}: {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to set location bid adjustment: " . $e->getMessage());
            return null;
        }
    }
}
