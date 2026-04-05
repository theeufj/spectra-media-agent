<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\DeviceInfo;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class SetDeviceBidAdjustment extends BaseGoogleAdsService
{
    /**
     * Set a bid modifier for a device type on a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param int $deviceType DeviceEnum\Device value (MOBILE=2, DESKTOP=4, TABLET=6)
     * @param float $bidModifier 1.0=no change, 1.2=+20%, 0.8=-20%, 0.0=exclude
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(string $customerId, string $campaignResourceName, int $deviceType, float $bidModifier): ?string
    {
        $this->ensureClient();

        $deviceInfo = new DeviceInfo([
            'type' => $deviceType,
        ]);

        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'device' => $deviceInfo,
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
            $this->logInfo("Set device bid adjustment ({$bidModifier}x) for device type {$deviceType}: {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to set device bid adjustment: " . $e->getMessage());
            return null;
        }
    }
}
