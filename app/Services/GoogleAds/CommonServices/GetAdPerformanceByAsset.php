<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\AssetPerformanceSource;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Illuminate\Support\Facades\Log;

/**
 * GetAdPerformanceByAsset Service
 *
 * Fetches performance metrics broken down by ad asset (headline, description, image).
 * Used for A/B test analysis and creative optimization.
 */
class GetAdPerformanceByAsset extends BaseGoogleAdsService implements AssetPerformanceSource
{
    public function treatmentMetrics(string $account, string $campaign, string $action, string $from, string $to, bool $pmax): array
    {
        if (! ctype_digit($account) || ! preg_match("~^customers/{$account}/campaigns/\d+$~D", $campaign)
            || ! preg_match("~^customers/{$account}/conversionActions/\d+$~D", $action)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)) {
            return [];
        }
        $view = $pmax ? 'asset_group_asset' : 'ad_group_ad_asset_view';
        $enabled = $pmax ? "asset_group_asset.status = 'ENABLED' AND asset_group.status = 'ENABLED'" : "ad_group_ad_asset_view.enabled = TRUE AND ad_group_ad.status = 'ENABLED'";
        $filter = " FROM {$view} WHERE campaign.resource_name = '{$campaign}' AND {$view}.field_type = 'HEADLINE' AND {$enabled} AND segments.date BETWEEN '{$from}' AND '{$to}'";
        $results = [];
        $traffic = $this->searchQuery($account, "SELECT {$view}.resource_name, asset.text_asset.text, metrics.impressions, metrics.cost_micros, metrics.clicks".$filter);
        foreach ($traffic->iterateAllElements() as $row) {
            $id = ($pmax ? $row->getAssetGroupAsset() : $row->getAdGroupAdAssetView())->getResourceName();
            $results[$id] = ['treatment_id' => $id, 'content' => $row->getAsset()->getTextAsset()->getText(),
                'cost' => $row->getMetrics()->getCostMicros() / 1000000, 'impressions' => $row->getMetrics()->getImpressions(),
                'clicks' => $row->getMetrics()->getClicks(), 'conversions' => 0];
        }
        $outcomes = $this->searchQuery($account, "SELECT {$view}.resource_name, segments.conversion_action, metrics.conversions".$filter." AND segments.conversion_action = '{$action}'");
        foreach ($outcomes->iterateAllElements() as $row) {
            $id = ($pmax ? $row->getAssetGroupAsset() : $row->getAdGroupAdAssetView())->getResourceName();
            if (isset($results[$id])) {
                $results[$id]['conversions'] = $row->getMetrics()->getConversions();
            }
        }

        return $results;
    }

    /**
     * Get performance metrics for Responsive Search Ad assets.
     *
     * @return array Performance data by asset
     */
    public function getResponsiveSearchAdAssets(
        string $customerId,
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        // Query for headline performance
        $query = 'SELECT '.
                 'ad_group_ad.ad.responsive_search_ad.headlines, '.
                 'ad_group_ad.ad.responsive_search_ad.descriptions, '.
                 'ad_group_ad.ad.id, '.
                 'ad_group_ad.ad.final_urls, '.
                 'ad_group_ad.status, '.
                 'ad_group_ad.policy_summary.approval_status, '.
                 'ad_group_ad_asset_view.field_type, '.
                 'ad_group_ad_asset_view.performance_label, '.
                 'asset.text_asset.text, '.
                 'asset.type, '.
                 'metrics.impressions, '.
                 'metrics.clicks, '.
                 'metrics.conversions, '.
                 'metrics.cost_micros, '.
                 'metrics.ctr, '.
                 'metrics.conversions_value, '.
                 'campaign.resource_name '.
                 'FROM ad_group_ad_asset_view '.
                 "WHERE campaign.resource_name = '$campaignResourceName' ".
                 "AND segments.date DURING $dateRange ".
                 "AND ad_group_ad_asset_view.field_type IN ('HEADLINE', 'DESCRIPTION')";

        try {
            $response = $this->searchQuery($customerId, $query);

            $assets = [
                'headlines' => [],
                'descriptions' => [],
            ];

            foreach ($response->getIterator() as $googleAdsRow) {
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
            usort($assets['headlines'], fn ($a, $b) => $b['conversions'] <=> $a['conversions'] ?: $b['ctr'] <=> $a['ctr']
            );
            usort($assets['descriptions'], fn ($a, $b) => $b['conversions'] <=> $a['conversions'] ?: $b['ctr'] <=> $a['ctr']
            );

            return $assets;

        } catch (\Throwable $e) {
            Log::error('GetAdPerformanceByAsset: Query failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            report($e);
            throw $e;
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

        $query = 'SELECT '.
                 'campaign.resource_name, '.
                 'asset.image_asset.full_size.url, '.
                 'asset.name, '.
                 'asset.type, '.
                 'campaign_asset.status, '.
                 'metrics.impressions, '.
                 'metrics.clicks, '.
                 'metrics.conversions, '.
                 'metrics.cost_micros '.
                 'FROM campaign_asset '.
                 "WHERE campaign.resource_name = '$campaignResourceName' ".
                 "AND segments.date DURING $dateRange ".
                 "AND asset.type = 'IMAGE'";

        try {
            $response = $this->searchQuery($customerId, $query);

            $images = [];

            foreach ($response->getIterator() as $googleAdsRow) {
                $asset = $googleAdsRow->getAsset();
                $metrics = $googleAdsRow->getMetrics();

                $images[] = [
                    'name' => $asset->getName(),
                    'url' => $asset->getImageAsset()?->getFullSize()?->getUrl() ?? '',
                    // CampaignAsset has no performance_label field in the V22 API.
                    'performance_label' => 'UNKNOWN',
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'ctr' => $metrics->getImpressions() > 0
                        ? round(($metrics->getClicks() / $metrics->getImpressions()) * 100, 2)
                        : 0,
                    'conversions' => $metrics->getConversions(),
                    'cost' => $metrics->getCostMicros() / 1000000,
                ];
            }

            // Sort by conversions
            usort($images, fn ($a, $b) => $b['conversions'] <=> $a['conversions']);

            return $images;

        } catch (\Throwable $e) {
            Log::error('GetAdPerformanceByAsset: Image query failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            report($e);
            throw $e;
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
