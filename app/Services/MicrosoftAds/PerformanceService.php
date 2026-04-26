<?php

namespace App\Services\MicrosoftAds;

use App\Models\Campaign;
use App\Models\MicrosoftAdsPerformanceData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PerformanceService extends BaseMicrosoftAdsService
{
    /**
     * Fetch and store campaign performance data.
     */
    public function syncPerformance(Campaign $campaign, int $days = 30): array
    {
        $campaignId = $campaign->microsoft_ads_campaign_id;
        if (!$campaignId) return ['error' => 'No Microsoft Ads campaign ID'];

        $startDate = now()->subDays($days)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $result = $this->reportingCall('SubmitGenerateReport', [
            'ReportRequest' => [
                'Type' => 'CampaignPerformanceReportRequest',
                'Format' => 'Csv',
                'ReportName' => "Campaign Performance {$startDate} to {$endDate}",
                'Time' => [
                    'CustomDateRangeStart' => $this->formatDate($startDate),
                    'CustomDateRangeEnd' => $this->formatDate($endDate),
                ],
                'Columns' => [
                    'CampaignPerformanceReportColumn' => [
                        'TimePeriod', 'CampaignId', 'Impressions', 'Clicks',
                        'Spend', 'Conversions', 'Revenue', 'Ctr', 'AverageCpc', 'CostPerConversion',
                    ],
                ],
                'Filter' => ['CampaignIds' => ['long' => [$campaignId]]],
                'Scope' => [
                    'AccountIds' => ['long' => [$this->customer->microsoft_ads_account_id]],
                ],
                'Aggregation' => 'Daily',
            ],
        ]);

        // In production, we'd poll for report completion then download
        // For now, return the request result
        if ($result) {
            Log::info('Microsoft Ads: Performance report submitted', ['campaign_id' => $campaign->id]);
        }

        return $result ?? ['error' => 'Report submission failed'];
    }

    /**
     * Store performance data from report results.
     */
    public function storePerformanceData(Campaign $campaign, array $rows): int
    {
        $stored = 0;
        foreach ($rows as $row) {
            MicrosoftAdsPerformanceData::updateOrCreate(
                ['campaign_id' => $campaign->id, 'date' => $row['date']],
                [
                    'impressions' => $row['impressions'] ?? 0,
                    'clicks' => $row['clicks'] ?? 0,
                    'cost' => $row['cost'] ?? 0,
                    'conversions' => $row['conversions'] ?? 0,
                    'conversion_value' => $row['conversion_value'] ?? 0,
                    'ctr' => $row['ctr'] ?? 0,
                    'cpc' => $row['cpc'] ?? 0,
                    'cpa' => $row['cpa'] ?? 0,
                ]
            );
            $stored++;
        }
        return $stored;
    }

    protected function formatDate(string $date): array
    {
        $d = Carbon::parse($date);
        return ['Day' => $d->day, 'Month' => $d->month, 'Year' => $d->year];
    }

    /**
     * Get search terms report (simulated/mapped to internal format)
     */
    public function getSearchTerms(string $campaignId, int $days = 30): array
    {
        Log::info("Microsoft Ads: Fetching search terms for campaign {$campaignId}");
        
        $startDate = now()->subDays($days)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        // This would call SubmitGenerateReport for SearchQueryPerformanceReportRequest
        // For the sake of feature parity simulation, we'll return an array structure identical to Google's.
        $request = [
            'ReportRequest' => [
                'Type' => 'SearchQueryPerformanceReportRequest',
                'Format' => 'Csv',
                'ReportName' => "Search Query Performance {$startDate} to {$endDate}",
                'Time' => [
                    'CustomDateRangeStart' => $this->formatDate($startDate),
                    'CustomDateRangeEnd' => $this->formatDate($endDate),
                ],
                'Columns' => [
                    'SearchQueryPerformanceReportColumn' => [
                        'SearchQuery', 'AdGroupId', 'CampaignId', 'Impressions', 'Clicks',
                        'Spend', 'Conversions', 'Ctr'
                    ],
                ],
                'Filter' => ['CampaignIds' => ['long' => [$campaignId]]],
            ],
        ];

        try {
            $this->reportingCall('SubmitGenerateReport', $request);
            // Simulating downloaded parsed data:
            return [];
        } catch (\Exception $e) {
            Log::error("Microsoft Ads: Failed to get search terms", ['error' => $e->getMessage()]);
            return [];
        }
    }
}
