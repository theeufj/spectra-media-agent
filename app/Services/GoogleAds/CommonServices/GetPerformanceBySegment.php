<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;

class GetPerformanceBySegment extends BaseGoogleAdsService
{
    /**
     * Fetch campaign performance segmented by device (MOBILE, DESKTOP, TABLET).
     *
     * @return array  Keyed by device enum value: ['clicks','impressions','cost_micros','conversions','cpa_micros']
     */
    public function byDevice(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $query = "SELECT
                    segments.device,
                    metrics.clicks,
                    metrics.impressions,
                    metrics.cost_micros,
                    metrics.conversions
                  FROM campaign
                  WHERE campaign.resource_name = '{$campaignResourceName}'
                    AND segments.date DURING {$dateRange}";

        return $this->executeSegmentQuery($customerId, $query, fn($row) => [
            'key'          => $row->getSegments()->getDevice(),
            'clicks'       => $row->getMetrics()->getClicks(),
            'impressions'  => $row->getMetrics()->getImpressions(),
            'cost_micros'  => $row->getMetrics()->getCostMicros(),
            'conversions'  => $row->getMetrics()->getConversions(),
        ]);
    }

    /**
     * Fetch campaign performance segmented by hour of day (0–23).
     *
     * @return array  Keyed by hour (0-23)
     */
    public function byHour(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $query = "SELECT
                    segments.hour,
                    metrics.clicks,
                    metrics.impressions,
                    metrics.cost_micros,
                    metrics.conversions
                  FROM campaign
                  WHERE campaign.resource_name = '{$campaignResourceName}'
                    AND segments.date DURING {$dateRange}";

        return $this->executeSegmentQuery($customerId, $query, fn($row) => [
            'key'         => $row->getSegments()->getHour(),
            'clicks'      => $row->getMetrics()->getClicks(),
            'impressions' => $row->getMetrics()->getImpressions(),
            'cost_micros' => $row->getMetrics()->getCostMicros(),
            'conversions' => $row->getMetrics()->getConversions(),
        ]);
    }

    /**
     * Fetch campaign performance segmented by day of week (MONDAY–SUNDAY).
     *
     * @return array  Keyed by DayOfWeek enum value
     */
    public function byDayOfWeek(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $query = "SELECT
                    segments.day_of_week,
                    metrics.clicks,
                    metrics.impressions,
                    metrics.cost_micros,
                    metrics.conversions
                  FROM campaign
                  WHERE campaign.resource_name = '{$campaignResourceName}'
                    AND segments.date DURING {$dateRange}";

        return $this->executeSegmentQuery($customerId, $query, fn($row) => [
            'key'         => $row->getSegments()->getDayOfWeek(),
            'clicks'      => $row->getMetrics()->getClicks(),
            'impressions' => $row->getMetrics()->getImpressions(),
            'cost_micros' => $row->getMetrics()->getCostMicros(),
            'conversions' => $row->getMetrics()->getConversions(),
        ]);
    }

    private function executeSegmentQuery(string $customerId, string $query, callable $mapper): array
    {
        $this->ensureClient();
        $results = [];

        try {
            $stream = $this->client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest(['customer_id' => $customerId, 'query' => $query])
            );

            foreach ($stream->iterateAllElements() as $row) {
                $mapped = $mapper($row);
                $key    = $mapped['key'];
                unset($mapped['key']);

                if (!isset($results[$key])) {
                    $results[$key] = array_merge($mapped, ['cpa_micros' => 0.0]);
                } else {
                    foreach (['clicks', 'impressions', 'cost_micros', 'conversions'] as $field) {
                        $results[$key][$field] += $mapped[$field];
                    }
                }

                // Recalculate CPA
                if ($results[$key]['conversions'] > 0) {
                    $results[$key]['cpa_micros'] = $results[$key]['cost_micros'] / $results[$key]['conversions'];
                }
            }
        } catch (\Exception $e) {
            $this->logError('GetPerformanceBySegment failed: ' . $e->getMessage());
        }

        return $results;
    }
}
