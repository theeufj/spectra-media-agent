<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Resources\CampaignAsset;
use Google\Ads\GoogleAds\V26\Services\CampaignAssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetsRequest;

class LinkCampaignAsset extends BaseGoogleAdsService
{
    /**
     * Link an Asset to a Campaign.
     *
     * @param  int  $fieldType  The AssetFieldType enum value (e.g., SITELINK, CALLOUT)
     * @return string|null Resource name of the created CampaignAsset link
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $assetResourceName, int $fieldType): ?string
    {
        if (! in_array($fieldType, [AssetFieldType::CALLOUT, AssetFieldType::STRUCTURED_SNIPPET], true)) {
            $campaign = \App\Services\Campaigns\CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
            if ($campaign && \App\Services\Campaigns\SingleGoogleSearchLandingPage::protectsResource($campaign, $campaignResourceName)) {
                return null;
            }
        }
        $this->ensureClient();

        $campaignAsset = new CampaignAsset([
            'campaign' => $campaignResourceName,
            'asset' => $assetResourceName,
            'field_type' => $fieldType,
        ]);

        $operation = new CampaignAssetOperation;
        $operation->setCreate($campaignAsset);

        try {
            $campaignAssetServiceClient = $this->client->getCampaignAssetServiceClient();
            $response = $campaignAssetServiceClient->mutateCampaignAssets(new MutateCampaignAssetsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Linked Asset to Campaign: $resourceName");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError('Failed to link Asset to Campaign: '.$e->getMessage());

            return null;
        }
    }

    /** Include zero-impression links so a submitted replacement is never uploaded twice. */
    public function calloutLinks(string $account, string $campaign): array
    {
        if (! ctype_digit($account) || ! preg_match("~^customers/{$account}/campaigns/\d+$~D", $campaign)) {
            return [];
        }
        $rows = $this->searchQuery($account, "SELECT campaign_asset.resource_name, campaign_asset.source, campaign_asset.status, campaign_asset.primary_status, asset.callout_asset.callout_text FROM campaign_asset WHERE campaign_asset.campaign = '{$campaign}' AND campaign_asset.field_type = 'CALLOUT'");
        $links = [];
        foreach ($rows->iterateAllElements() as $row) {
            $link = $row->getCampaignAsset();
            $links[$link->getResourceName()] = [
                'status' => \Google\Ads\GoogleAds\V26\Enums\AssetLinkStatusEnum\AssetLinkStatus::name($link->getStatus()),
                'primary' => \Google\Ads\GoogleAds\V26\Enums\AssetLinkPrimaryStatusEnum\AssetLinkPrimaryStatus::name($link->getPrimaryStatus()),
                'text' => $row->getAsset()->getCalloutAsset()->getCalloutText(),
            ];
        }

        return $links;
    }

    public function retireCallout(string $account, string $campaign, string $old, string $replacement): bool
    {
        $links = $this->calloutLinks($account, $campaign);
        if ($old === $replacement || ! isset($links[$old], $links[$replacement])
            || $links[$replacement]['primary'] !== 'ELIGIBLE' || $links[$replacement]['status'] !== 'ENABLED' || $this->dryRun) {
            return false;
        }
        if ($links[$old]['status'] === 'ENABLED') {
            $operation = new CampaignAssetOperation(['update' => new CampaignAsset(['resource_name' => $old,
                'status' => \Google\Ads\GoogleAds\V26\Enums\AssetLinkStatusEnum\AssetLinkStatus::PAUSED]),
                'update_mask' => new \Google\Protobuf\FieldMask(['paths' => ['status']])]);
            $this->client->getCampaignAssetServiceClient()->mutateCampaignAssets(new MutateCampaignAssetsRequest([
                'validate_only' => $this->dryRun, 'customer_id' => $account, 'operations' => [$operation],
            ]));
            $links = $this->calloutLinks($account, $campaign);
        }

        return in_array($links[$old]['status'] ?? null, ['REMOVED', 'PAUSED'], true) && ($links[$replacement]['primary'] ?? null) === 'ELIGIBLE';
    }
}
