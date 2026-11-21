<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\DeviceInfo;
use Google\Ads\GoogleAds\V26\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V26\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignCriteriaRequest;
use Google\ApiCore\ApiException;

class SetDeviceBidAdjustment extends BaseGoogleAdsService
{
    /**
     * Set a bid modifier for a device type on a campaign.
     *
     * @param  int  $deviceType  DeviceEnum\Device value (MOBILE=2, DESKTOP=4, TABLET=6)
     * @param  float  $bidModifier  1.0=no change, 1.2=+20%, 0.8=-20%, 0.0=exclude
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(string $customerId, string $campaignResourceName, int $deviceType, float $bidModifier): ?string
    {
        $campaign = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
        if ($campaign && ! CampaignSpendGuardrails::permitsBidModifier($campaign, $bidModifier)) {
            return null;
        }
        $this->ensureClient();

        $deviceInfo = new DeviceInfo([
            'type' => $deviceType,
        ]);

        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'device' => $deviceInfo,
            'bid_modifier' => $bidModifier,
        ]);

        $operation = new CampaignCriterionOperation;
        $operation->setCreate($campaignCriterion);

        try {
            $response = $this->client->getCampaignCriterionServiceClient()->mutateCampaignCriteria(
                new MutateCampaignCriteriaRequest([
                    'validate_only' => $this->dryRun,
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $resourceName = $response->getResults()[0]->getResourceName();
            if ($this->dryRun || ! preg_match('~^customers/\d+/campaignCriteria/\d+~', $resourceName)) {
                return null;
            }
            $readback = $this->client->getGoogleAdsServiceClient()->search(new \Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => "SELECT campaign_criterion.resource_name, campaign_criterion.bid_modifier FROM campaign_criterion WHERE campaign_criterion.resource_name = '{$resourceName}' AND campaign_criterion.status = 'ENABLED'",
            ]));
            foreach ($readback->iterateAllElements() as $row) {
                $criterion = $row->getCampaignCriterion();
                if ($criterion->getResourceName() === $resourceName && abs($criterion->getBidModifier() - $bidModifier) < 0.0001) {
                    return $resourceName;
                }
            }

            return null;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('Failed to set device bid adjustment: '.$e->getMessage());

            return null;
        }
    }
}
