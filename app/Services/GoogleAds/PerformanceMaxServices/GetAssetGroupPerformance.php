<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\Agents\AdaptiveThresholds;
use App\Services\GoogleAds\BaseGoogleAdsService;

class GetAssetGroupPerformance extends BaseGoogleAdsService
{
    /** Google v26 reports quantitative asset metrics. Eligibility and insufficient data are separate from performance. */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        if ($this->customer->cleanGoogleCustomerId() !== $customerId || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/[0-9]+$#D', $campaignResourceName)) {
            throw new \InvalidArgumentException('The campaign must belong to the authorised Google Ads customer.');
        }
        if (! in_array($dateRange, ['TODAY', 'YESTERDAY', 'LAST_7_DAYS', 'LAST_14_DAYS', 'LAST_30_DAYS', 'THIS_MONTH', 'LAST_MONTH', 'LAST_BUSINESS_WEEK', 'LAST_WEEK_MON_SUN', 'LAST_WEEK_SUN_SAT', 'THIS_WEEK_MON_TODAY', 'THIS_WEEK_SUN_TODAY'], true)) {
            throw new \InvalidArgumentException('Unsupported Google Ads reporting range.');
        }

        $fields = 'campaign.resource_name, campaign.advertising_channel_type, asset_group.resource_name, asset_group.status, asset_group_asset.resource_name, asset_group_asset.status';
        $from = " FROM asset_group_asset WHERE campaign.resource_name='{$campaignResourceName}' AND campaign.advertising_channel_type='PERFORMANCE_MAX' AND asset_group.status='ENABLED' AND asset_group_asset.status='ENABLED'";

        // A date-filtered metrics report omits undelivered assets. Join it back to the active inventory.
        $inventory = $this->rows($customerId, "SELECT {$fields}, asset_group_asset.asset, asset_group_asset.field_type, asset_group_asset.primary_status, asset.text_asset.text, asset.image_asset.full_size.url, asset.youtube_video_asset.youtube_video_id".$from);
        $rows = $this->rows($customerId, "SELECT {$fields}, metrics.impressions, metrics.clicks, metrics.conversions, metrics.cost_micros".$from." AND segments.date DURING {$dateRange}");
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[$row['assetGroupAsset']['resourceName']] = $row['metrics'] ?? [];
        }

        $thresholds = AdaptiveThresholds::forCustomer($this->customer);
        $minimumImpressions = max(1, (int) $thresholds['min_impressions_for_decision']);
        $minimumCost = max(1, (int) round($thresholds['max_spend_no_conversion'] * 1_000_000));
        $result = ['best' => [], 'good' => [], 'low' => [], 'pending' => [], 'learning' => [], 'observed' => [],
            'date_range' => $dateRange, 'minimum_impressions' => $minimumImpressions];
        foreach ($inventory as $row) {
            $link = $row['assetGroupAsset'];
            $m = $metrics[$link['resourceName']] ?? [];
            $impressions = (int) ($m['impressions'] ?? 0);
            $clicks = (int) ($m['clicks'] ?? 0);
            $conversions = (float) ($m['conversions'] ?? 0);
            $cost = (int) ($m['costMicros'] ?? 0);
            $status = $link['primaryStatus'] ?? 'UNKNOWN';
            $field = $link['fieldType'] ?? 'UNKNOWN';
            // A local review candidate requires both sufficient delivery and the account's established spend floor.
            // A mandatory logo, business name or format with no delivery must never trigger a creative replacement.
            $reviewable = in_array($field, ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION', 'MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE'], true);
            $bucket = match (true) {
                $impressions === 0 => 'pending',
                $impressions < $minimumImpressions => 'learning',
                $reviewable && $status === 'ELIGIBLE' && $cost >= $minimumCost && $clicks === 0 && $conversions <= 0 => 'low',
                default => 'observed',
            };
            $result[$bucket][] = [
                'asset_resource' => $link['asset'], 'asset_group' => $row['assetGroup']['resourceName'],
                'field_type' => $field, 'primary_status' => $status, 'impressions' => $impressions,
                'clicks' => $clicks, 'conversions' => $conversions, 'cost_micros' => $cost,
                'text' => $row['asset']['textAsset']['text'] ?? null,
                'image_url' => $row['asset']['imageAsset']['fullSize']['url'] ?? null,
                'youtube_video_id' => $row['asset']['youtubeVideoAsset']['youtubeVideoId'] ?? null,
            ];
        }

        return $result;
    }

    public function getLowPerformingAssets(string $customerId, string $campaignResourceName): array
    {
        return $this($customerId, $campaignResourceName)['low'];
    }

    protected function rows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }
}
