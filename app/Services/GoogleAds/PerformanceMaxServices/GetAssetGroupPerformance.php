<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

/**
 * GetAssetGroupPerformance
 *
 * Queries the asset_group_asset view to retrieve PMax asset performance labels,
 * then buckets them by label (BEST, GOOD, LOW, PENDING, LEARNING).
 */
class GetAssetGroupPerformance extends BaseGoogleAdsService
{
    /**
     * Get performance data for all assets in a PMax campaign's asset groups.
     *
     * @param  string $customerId
     * @param  string $campaignResourceName  e.g. "customers/123/campaigns/456"
     * @param  string $dateRange             GAQL date range constant (unused in filter but kept for API consistency)
     * @return array{best: array, good: array, low: array, pending: array, learning: array}
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        $query = "SELECT
  asset_group_asset.asset,
  asset_group_asset.field_type,
  asset_group_asset.performance_label,
  asset_group_asset.asset_group,
  asset_group.name,
  asset.name,
  asset.type,
  asset.text_asset.text,
  asset.image_asset.full_size.url,
  asset.youtube_video_asset.youtube_video_id
FROM asset_group_asset
WHERE asset_group.campaign = '{$campaignResourceName}'
  AND asset_group_asset.status = 'ENABLED'";

        $buckets = [
            'best'     => [],
            'good'     => [],
            'low'      => [],
            'pending'  => [],
            'learning' => [],
        ];

        try {
            $response = $this->searchQuery($customerId, $query);

            foreach ($response->getIterator() as $row) {
                $assetGroupAsset = $row->getAssetGroupAsset();
                $asset           = $row->getAsset();

                $fieldTypeInt       = $assetGroupAsset->getFieldType();
                $performanceLabelInt = $assetGroupAsset->getPerformanceLabel();

                $performanceLabelStr = $this->formatPerformanceLabel($performanceLabelInt);
                $fieldTypeStr        = $this->formatFieldType($fieldTypeInt);

                $entry = [
                    'asset_resource'    => $assetGroupAsset->getAsset(),
                    'asset_group'       => $assetGroupAsset->getAssetGroup(),
                    'field_type'        => $fieldTypeStr,
                    'performance_label' => $performanceLabelStr,
                    'text'              => $asset->getTextAsset()?->getText() ?? null,
                    'image_url'         => $asset->getImageAsset()?->getFullSize()?->getUrl() ?? null,
                    'youtube_video_id'  => $asset->getYoutubeVideoAsset()?->getYoutubeVideoId() ?? null,
                ];

                $bucket = match ($performanceLabelStr) {
                    'BEST'     => 'best',
                    'GOOD'     => 'good',
                    'LOW'      => 'low',
                    'PENDING'  => 'pending',
                    'LEARNING' => 'learning',
                    default    => 'pending',
                };

                $buckets[$bucket][] = $entry;
            }
        } catch (GoogleAdsException $e) {
            Log::error('GetAssetGroupPerformance: Query failed', [
                'customer_id'   => $customerId,
                'campaign'      => $campaignResourceName,
                'error'         => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('GetAssetGroupPerformance: Unexpected error', [
                'customer_id' => $customerId,
                'campaign'    => $campaignResourceName,
                'error'       => $e->getMessage(),
            ]);
        }

        return $buckets;
    }

    /**
     * Convenience method that returns only the LOW-performing assets for a campaign.
     */
    public function getLowPerformingAssets(string $customerId, string $campaignResourceName): array
    {
        $all = $this($customerId, $campaignResourceName);
        return $all['low'] ?? [];
    }

    /**
     * Map the AssetFieldType proto enum integer to a human-readable string.
     */
    protected function formatFieldType(int $fieldType): string
    {
        return match ($fieldType) {
            0  => 'UNSPECIFIED',
            1  => 'UNKNOWN',
            2  => 'HEADLINE',
            3  => 'DESCRIPTION',
            4  => 'MANDATORY_AD_TEXT',
            5  => 'MARKETING_IMAGE',
            6  => 'MEDIA_BUNDLE',
            7  => 'YOUTUBE_VIDEO',
            8  => 'BOOK_ON_GOOGLE',
            9  => 'LEAD_FORM',
            10 => 'PROMOTION',
            11 => 'CALLOUT',
            12 => 'STRUCTURED_SNIPPET',
            13 => 'SITELINK',
            14 => 'MOBILE_APP',
            15 => 'HOTEL_CALLOUT',
            16 => 'CALL',
            17 => 'PRICE',
            18 => 'LONG_HEADLINE',
            19 => 'BUSINESS_NAME',
            20 => 'SQUARE_MARKETING_IMAGE',
            21 => 'PORTRAIT_MARKETING_IMAGE',
            22 => 'LOGO',
            23 => 'LANDSCAPE_LOGO',
            24 => 'VIDEO',
            25 => 'CALL_TO_ACTION_SELECTION',
            26 => 'AD_IMAGE',
            27 => 'BUSINESS_LOGO',
            default => 'UNKNOWN',
        };
    }

    /**
     * Map the AssetPerformanceLabel proto enum integer to a human-readable string.
     *
     * 0 = UNSPECIFIED, 1 = UNKNOWN, 2 = PENDING, 3 = LEARNING, 4 = LOW, 5 = GOOD, 6 = BEST
     */
    protected function formatPerformanceLabel(int $label): string
    {
        return match ($label) {
            0 => 'UNSPECIFIED',
            1 => 'UNKNOWN',
            2 => 'PENDING',
            3 => 'LEARNING',
            4 => 'LOW',
            5 => 'GOOD',
            6 => 'BEST',
            default => 'UNKNOWN',
        };
    }
}
