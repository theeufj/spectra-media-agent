<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

/**
 * GetCampaignExperimentResults
 *
 * Queries the experiment resource to get status and aggregate performance data.
 */
class GetCampaignExperimentResults extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  string $experimentResourceName  e.g. "customers/123/experiments/456"
     * @return array
     */
    public function __invoke(string $customerId, string $experimentResourceName): array
    {
        $this->ensureClient();

        $query = "SELECT
  experiment.resource_name,
  experiment.name,
  experiment.status,
  experiment.start_date,
  experiment.end_date,
  experiment.traffic_split_percent,
  metrics.impressions,
  metrics.clicks,
  metrics.conversions,
  metrics.cost_micros,
  metrics.conversions_value
FROM experiment
WHERE experiment.resource_name = '{$experimentResourceName}'";

        $result = [
            'experiment_resource' => $experimentResourceName,
            'experiment_status'   => 'UNKNOWN',
            'start_date'          => null,
            'end_date'            => null,
            'traffic_split'       => 50,
            'arms'                => [],
        ];

        try {
            $response = $this->searchQuery($customerId, $query);

            foreach ($response->getIterator() as $row) {
                $experiment = $row->getExperiment();
                $metrics    = $row->getMetrics();

                $result['experiment_status'] = $this->formatExperimentStatus($experiment->getStatus());
                $result['start_date']        = $experiment->getStartDate();
                $result['end_date']          = $experiment->getEndDate();
                $result['traffic_split']     = $experiment->getTrafficSplitPercent();
                $result['name']              = $experiment->getName();

                $result['arms'][] = [
                    'impressions'       => $metrics->getImpressions(),
                    'clicks'            => $metrics->getClicks(),
                    'conversions'       => $metrics->getConversions(),
                    'cost'              => $metrics->getCostMicros() / 1_000_000,
                    'conversions_value' => $metrics->getConversionsValue(),
                ];
            }
        } catch (GoogleAdsException $e) {
            Log::error('GetCampaignExperimentResults: Query failed', [
                'customer_id' => $customerId,
                'experiment'  => $experimentResourceName,
                'error'       => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('GetCampaignExperimentResults: Unexpected error', [
                'customer_id' => $customerId,
                'experiment'  => $experimentResourceName,
                'error'       => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function formatExperimentStatus(int $status): string
    {
        // ExperimentStatus enum values
        return match ($status) {
            0  => 'UNSPECIFIED',
            1  => 'UNKNOWN',
            2  => 'ENABLED',
            3  => 'REMOVED',
            4  => 'HALTED',
            5  => 'PROMOTED',
            6  => 'SETUP',
            7  => 'INITIATED',
            8  => 'GRADUATED',
            default => 'UNKNOWN',
        };
    }
}
