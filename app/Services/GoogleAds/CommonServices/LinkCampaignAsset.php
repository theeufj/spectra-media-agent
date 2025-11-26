<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Resources\CampaignAsset;
use Google\Ads\GoogleAds\V22\Services\CampaignAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class LinkCampaignAsset extends BaseGoogleAdsService
{
    /**
     * Link an Asset to a Campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $assetResourceName
     * @param int $fieldType The AssetFieldType enum value (e.g., SITELINK, CALLOUT)
     * @return string|null Resource name of the created CampaignAsset link
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $assetResourceName, int $fieldType): ?string
    {
        $this->ensureClient();

        $campaignAsset = new CampaignAsset([
            'campaign' => $campaignResourceName,
            'asset' => $assetResourceName,
            'field_type' => $fieldType,
        ]);

        $operation = new CampaignAssetOperation();
        $operation->setCreate($campaignAsset);

        try {
            $campaignAssetServiceClient = $this->client->getCampaignAssetServiceClient();
            $response = $campaignAssetServiceClient->mutateCampaignAssets(new MutateCampaignAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Linked Asset to Campaign: $resourceName");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to link Asset to Campaign: " . $e->getMessage());
            return null;
        }
    }
}
