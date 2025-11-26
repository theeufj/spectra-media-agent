<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

/**
 * GetAdPerformanceByAsset Service
 * 
 * Fetches performance metrics broken down by ad asset (headline, description, image).
 * Used for A/B test analysis and creative optimization.
 */
class GetAdPerformanceByAsset extends BaseGoogleAdsService
{
    /**
     * Get performance metrics for Responsive Search Ad assets.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $dateRange
     * @return array Performance data by asset
     */
    public function getResponsiveSearchAdAssets(
        string $customerId, 
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        // Query for headline performance
        $query = "SELECT " .
                 "ad_group_ad.ad.responsive_search_ad.headlines, " .
                 "ad_group_ad.ad.responsive_search_ad.descriptions, " .
                 "ad_group_ad.ad.id, " .
                 "ad_group_ad.ad.final_urls, " .
                 "ad_group_ad.status, " .
                 "ad_group_ad.policy_summary.approval_status, " .
                 "ad_group_ad_asset_view.field_type, " .
                 "ad_group_ad_asset_view.performance_label, " .
                 "asset.text_asset.text, " .
                 "asset.type, " .
                 "metrics.impressions, " .
                 "metrics.clicks, " .
                 "metrics.conversions, " .
                 "metrics.cost_micros, " .
                 "metrics.ctr, " .
                 "metrics.conversions_value, " .
                 "campaign.resource_name " .
                 "FROM ad_group_ad_asset_view " .
                 "WHERE campaign.resource_name = '$campaignResourceName' " .
                 "AND segments.date DURING $dateRange " .
                 "AND ad_group_ad_asset_view.field_type IN ('HEADLINE', 'DESCRIPTION')";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $assets = [
                'headlines' => [],
                'descriptions' => [],
            ];

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $assetView = $googleAdsRow->getAdGroupAdAssetView();
                $asset = $googleAdsRow->getAsset();
                $metrics = $googleAdsRow->getMetrics();

                $fieldType = $assetView->getFieldType();
                $performanceLabel = $assetView->getPerformanceLabel();

                $assetData = [
                    'text' => $asset->getTextAsset()?->getText() ?? '',
                    'performance_label' => $this->formatPerformanceLabel($performanceLabel),
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'ctr' => round($metrics->getCtr() * 100, 2),
                    'conversions' => $metrics->getConversions(),
                    'cost' => $metrics->getCostMicros() / 1000000,
                    'conversion_value' => $metrics->getConversionsValue(),
                ];

                // Calculate conversion rate
                $assetData['conversion_rate'] = $assetData['clicks'] > 0 
                    ? round(($assetData['conversions'] / $assetData['clicks']) * 100, 2)
                    : 0;

                // Categorize by field type
                if ($fieldType == 2) { // HEADLINE
                    $assets['headlines'][] = $assetData;
                } elseif ($fieldType == 3) { // DESCRIPTION
                    $assets['descriptions'][] = $assetData;
                }
            }

            // Sort by performance (conversions then CTR)
            usort($assets['headlines'], fn($a, $b) => 
                $b['conversions'] <=> $a['conversions'] ?: $b['ctr'] <=> $a['ctr']
            );
            usort($assets['descriptions'], fn($a, $b) => 
                $b['conversions'] <=> $a['conversions'] ?: $b['ctr'] <=> $a['ctr']
            );

            return $assets;

        } catch (GoogleAdsException $e) {
            Log::error('GetAdPerformanceByAsset: Query failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return ['headlines' => [], 'descriptions' => []];
        }
    }

    /**
     * Get image asset performance for Display/PMax campaigns.
     */
    public function getImageAssetPerformance(
        string $customerId,
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        $query = "SELECT " .
                 "asset.image_asset.full_size.url, " .
                 "asset.name, " .
                 "asset.type, " .
                 "campaign_asset.performance_label, " .
                 "metrics.impressions, " .
                 "metrics.clicks, " .
                 "metrics.conversions, " .
                 "metrics.cost_micros " .
                 "FROM campaign_asset " .
                 "WHERE campaign.resource_name = '$campaignResourceName' " .
                 "AND segments.date DURING $dateRange " .
                 "AND asset.type = 'IMAGE'";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $images = [];

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $asset = $googleAdsRow->getAsset();
                $campaignAsset = $googleAdsRow->getCampaignAsset();
                $metrics = $googleAdsRow->getMetrics();

                $images[] = [
                    'name' => $asset->getName(),
                    'url' => $asset->getImageAsset()?->getFullSize()?->getUrl() ?? '',
                    'performance_label' => $this->formatPerformanceLabel($campaignAsset->getPerformanceLabel()),
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'ctr' => $metrics->getClicks() > 0 
                        ? round(($metrics->getClicks() / $metrics->getImpressions()) * 100, 2) 
                        : 0,
                    'conversions' => $metrics->getConversions(),
                    'cost' => $metrics->getCostMicros() / 1000000,
                ];
            }

            // Sort by conversions
            usort($images, fn($a, $b) => $b['conversions'] <=> $a['conversions']);

            return $images;

        } catch (GoogleAdsException $e) {
            Log::error('GetAdPerformanceByAsset: Image query failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Format the performance label enum to human-readable string.
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
