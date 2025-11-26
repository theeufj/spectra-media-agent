<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetCampaignPerformance extends BaseGoogleAdsService
{
    /**
     * Get performance metrics for a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $dateRange 'LAST_30_DAYS', 'YESTERDAY', 'LAST_7_DAYS', etc.
     * @return array|null
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): ?array
    {
        $this->ensureClient();

        $query = "SELECT " .
                 "metrics.impressions, " .
                 "metrics.clicks, " .
                 "metrics.cost_micros, " .
                 "metrics.conversions, " .
                 "metrics.ctr, " .
                 "metrics.average_cpc, " .
                 "metrics.cost_per_conversion " .
                 "FROM campaign " .
                 "WHERE campaign.resource_name = '$campaignResourceName' " .
                 "AND segments.date DURING $dateRange";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            // Aggregating metrics if multiple rows returned (though for campaign level without other segments, should be one row)
            $metrics = [
                'impressions' => 0,
                'clicks' => 0,
                'cost_micros' => 0,
                'conversions' => 0.0,
                'ctr' => 0.0,
                'average_cpc' => 0.0,
                'cost_per_conversion' => 0.0,
            ];

            $count = 0;
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var GoogleAdsRow $googleAdsRow */
                $rowMetrics = $googleAdsRow->getMetrics();
                
                $metrics['impressions'] += $rowMetrics->getImpressions();
                $metrics['clicks'] += $rowMetrics->getClicks();
                $metrics['cost_micros'] += $rowMetrics->getCostMicros();
                $metrics['conversions'] += $rowMetrics->getConversions();
                
                // CTR, CPC, CPA are calculated fields or averages, but API returns them for the row.
                // If we have multiple rows (e.g. if we segmented by date), we'd need to recalculate.
                // For this query, we expect one row.
                $metrics['ctr'] = $rowMetrics->getCtr();
                $metrics['average_cpc'] = $rowMetrics->getAverageCpc();
                $metrics['cost_per_conversion'] = $rowMetrics->getCostPerConversion();
                
                $count++;
            }

            if ($count === 0) {
                return null;
            }

            return $metrics;

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to get campaign performance: " . $e->getMessage());
            return null;
        }
    }
}
