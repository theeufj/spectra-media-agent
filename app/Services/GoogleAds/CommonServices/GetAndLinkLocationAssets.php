<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Enums\AssetSetTypeEnum\AssetSetType;
use Google\Ads\GoogleAds\V26\Resources\CampaignAssetSet;
use Google\Ads\GoogleAds\V26\Services\CampaignAssetSetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetSetsRequest;
use Illuminate\Support\Facades\Log;

class GetAndLinkLocationAssets extends BaseGoogleAdsService
{
    /**
     * Preserve inherited account locations, or link an explicitly selected
     * existing location group to the specified campaign.
     *
     * Customer LOCATION_SYNC asset sets are inherited without a campaign mutation.
     * An optional location-group asset set narrows that selection through a
     * CampaignAssetSet. Individual LOCATION assets are never CampaignAssets.
     *
     * @return int Number of new campaign asset-set links created (zero for inheritance, existing links or dry run)
     */
    public function __invoke(string $customerId, string $campaignResourceName, ?string $locationGroupResourceName = null): int
    {
        $this->ensureClient();

        try {
            if (! preg_match('/^\d+$/', $customerId) || $customerId !== $this->customer->cleanGoogleCustomerId()
                || ! preg_match('#^customers/'.$customerId.'/campaigns/\d+$#', $campaignResourceName)
                || ($locationGroupResourceName !== null && ! preg_match('#^customers/'.$customerId.'/assetSets/\d+$#', $locationGroupResourceName))) {
                throw new \InvalidArgumentException('Location assets must belong to the target customer and campaign.');
            }
            if ($locationGroupResourceName === null) {
                $query = 'SELECT customer_asset_set.asset_set FROM customer_asset_set '
                    ."WHERE customer_asset_set.status = 'ENABLED' AND asset_set.status = 'ENABLED' AND asset_set.type = 'LOCATION_SYNC'";
                foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                    Log::info('GetAndLinkLocationAssets: Account location sync is configured; preserving inherited campaign selection', [
                        'asset_set' => $row->getCustomerAssetSet()->getAssetSet(),
                        'campaign' => $campaignResourceName,
                    ]);
                }

                return 0;
            }

            $group = null;
            $query = 'SELECT asset_set.resource_name, asset_set.type FROM asset_set '
                ."WHERE asset_set.resource_name = '{$locationGroupResourceName}' AND asset_set.status = 'ENABLED'";
            foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                $group = $row->getAssetSet();
            }
            if (! $group || ! in_array($group->getType(), [AssetSetType::BUSINESS_PROFILE_DYNAMIC_LOCATION_GROUP,
                AssetSetType::CHAIN_DYNAMIC_LOCATION_GROUP, AssetSetType::STATIC_LOCATION_GROUP], true)) {
                throw new \InvalidArgumentException('The selected asset set is not an enabled location group.');
            }
            $query = 'SELECT campaign_asset_set.resource_name FROM campaign_asset_set '
                ."WHERE campaign_asset_set.campaign = '{$campaignResourceName}' "
                ."AND campaign_asset_set.asset_set = '{$locationGroupResourceName}' AND campaign_asset_set.status = 'ENABLED'";
            foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                return 0;
            }
            $response = $this->client->getCampaignAssetSetServiceClient()->mutateCampaignAssetSets(new MutateCampaignAssetSetsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [new CampaignAssetSetOperation(['create' => new CampaignAssetSet([
                    'campaign' => $campaignResourceName, 'asset_set' => $locationGroupResourceName,
                ])])],
            ]));

            return $this->dryRun ? 0 : count($response->getResults());
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GetAndLinkLocationAssets: Could not verify or link location assets', [
                'customer_id' => $customerId,
                'campaign' => $campaignResourceName,
                'asset_set' => $locationGroupResourceName,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
