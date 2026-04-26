<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;

class GetExtensionPerformance extends BaseGoogleAdsService
{
    /**
     * Fetch performance metrics for campaign-linked assets (extensions).
     *
     * Returns an array of assets keyed by resource name with CTR, clicks, impressions,
     * asset type, and field type so the AdExtensionAgent can identify underperformers.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @return array
     */
    public function __invoke(string $customerId, string $campaignResourceName): array
    {
        $this->ensureClient();

        $query = "SELECT
                    campaign_asset.asset,
                    campaign_asset.field_type,
                    asset.type,
                    asset.name,
                    metrics.clicks,
                    metrics.impressions,
                    metrics.ctr
                  FROM campaign_asset
                  WHERE campaign_asset.campaign = '{$campaignResourceName}'
                    AND segments.date DURING LAST_30_DAYS
                    AND metrics.impressions > 0";

        $results = [];

        try {
            $stream = $this->client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest(['customer_id' => $customerId, 'query' => $query])
            );

            foreach ($stream->iterateAllElements() as $row) {
                $assetResource = $row->getCampaignAsset()->getAsset();
                $results[$assetResource] = [
                    'asset_resource'   => $assetResource,
                    'field_type'       => $row->getCampaignAsset()->getFieldType(),
                    'asset_type'       => $row->getAsset()->getType(),
                    'asset_name'       => $row->getAsset()->getName(),
                    'clicks'           => $row->getMetrics()->getClicks(),
                    'impressions'      => $row->getMetrics()->getImpressions(),
                    'ctr'              => $row->getMetrics()->getCtr(),
                ];
            }
        } catch (\Exception $e) {
            $this->logError('GetExtensionPerformance failed: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Count how many assets of a given field type are linked to the campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param int    $fieldType  AssetFieldType enum value
     * @return int
     */
    public function countByFieldType(string $customerId, string $campaignResourceName, int $fieldType): int
    {
        $this->ensureClient();

        $query = "SELECT campaign_asset.asset
                  FROM campaign_asset
                  WHERE campaign_asset.campaign = '{$campaignResourceName}'
                    AND campaign_asset.field_type = {$fieldType}";

        $count = 0;

        try {
            $stream = $this->client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest(['customer_id' => $customerId, 'query' => $query])
            );

            foreach ($stream->iterateAllElements() as $_) {
                $count++;
            }
        } catch (\Exception $e) {
            $this->logError('GetExtensionPerformance::countByFieldType failed: ' . $e->getMessage());
        }

        return $count;
    }
}
