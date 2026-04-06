<?php

namespace App\Services\LinkedInAds;

use App\Models\Campaign;
use App\Models\LinkedInAdsPerformanceData;
use Illuminate\Support\Facades\Log;

/**
 * LinkedIn Ads Performance Reporting Service.
 *
 * Syncs campaign performance data from LinkedIn Marketing API analytics endpoints.
 */
class PerformanceService extends BaseLinkedInAdsService
{
    /**
     * Sync performance data for a campaign.
     */
    public function syncPerformance(Campaign $campaign, int $days = 30): int
    {
        if (!$campaign->linkedin_campaign_id) return 0;

        $accountId = $this->customer->linkedin_ads_account_id;
        if (!$accountId) return 0;

        $startDate = now()->subDays($days)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $result = $this->apiCall('adAnalytics', 'GET', null, [
            'q' => 'analytics',
            'pivot' => 'CAMPAIGN',
            'dateRange' => "(start:(year:" . now()->subDays($days)->year . ",month:" . now()->subDays($days)->month . ",day:" . now()->subDays($days)->day . "),end:(year:" . now()->year . ",month:" . now()->month . ",day:" . now()->day . "))",
            'timeGranularity' => 'DAILY',
            'campaigns' => "List(urn:li:sponsoredCampaign:{$campaign->linkedin_campaign_id})",
            'fields' => 'impressions,clicks,costInLocalCurrency,externalWebsiteConversions,dateRange',
        ]);

        if (!$result || empty($result['elements'])) return 0;

        return $this->storePerformanceData($campaign, $result['elements']);
    }

    /**
     * Store performance data rows.
     */
    protected function storePerformanceData(Campaign $campaign, array $rows): int
    {
        $stored = 0;

        foreach ($rows as $row) {
            $dateRange = $row['dateRange'] ?? [];
            $start = $dateRange['start'] ?? [];
            $date = sprintf('%04d-%02d-%02d', $start['year'] ?? 0, $start['month'] ?? 1, $start['day'] ?? 1);

            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $cost = (float) ($row['costInLocalCurrency'] ?? 0) / 100; // LinkedIn reports in minor currency
            $conversions = (float) ($row['externalWebsiteConversions'] ?? 0);

            LinkedInAdsPerformanceData::updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'date' => $date,
                ],
                [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'cost' => $cost,
                    'conversions' => $conversions,
                    'conversion_value' => $conversions * ($this->customer->average_order_value ?? 0),
                    'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : 0,
                    'cpc' => $clicks > 0 ? $cost / $clicks : 0,
                    'cpa' => $conversions > 0 ? $cost / $conversions : 0,
                ]
            );

            $stored++;
        }

        Log::info('LinkedIn Ads: Synced performance data', [
            'campaign_id' => $campaign->id,
            'rows_stored' => $stored,
        ]);

        return $stored;
    }

    /**
     * Get performance summary for a customer across all LinkedIn campaigns.
     */
    public function getPerformanceSummary(int $days = 30): array
    {
        $data = LinkedInAdsPerformanceData::whereHas('campaign', function ($q) {
            $q->where('customer_id', $this->customer->id);
        })
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        return [
            'impressions' => $data->sum('impressions'),
            'clicks' => $data->sum('clicks'),
            'cost' => round($data->sum('cost'), 2),
            'conversions' => $data->sum('conversions'),
            'conversion_value' => round($data->sum('conversion_value'), 2),
            'ctr' => $data->sum('impressions') > 0 ? round($data->sum('clicks') / $data->sum('impressions') * 100, 2) : 0,
            'cpc' => $data->sum('clicks') > 0 ? round($data->sum('cost') / $data->sum('clicks'), 2) : 0,
            'cpa' => $data->sum('conversions') > 0 ? round($data->sum('cost') / $data->sum('conversions'), 2) : 0,
            'roas' => $data->sum('cost') > 0 ? round($data->sum('conversion_value') / $data->sum('cost'), 2) : 0,
            'days' => $days,
        ];
    }
}
