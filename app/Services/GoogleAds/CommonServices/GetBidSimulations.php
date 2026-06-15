<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

/**
 * GetBidSimulations
 *
 * Fetches bid simulation data from the campaign_simulation resource.
 * Supports TARGET_CPA and BUDGET simulation types.
 *
 * Each simulation point projects the expected performance at a given bid or budget level.
 */
class GetBidSimulations extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  string $campaignResourceName   e.g. "customers/123/campaigns/456"
     * @param  string $simulationType         'TARGET_CPA' or 'BUDGET'
     * @return array{type: string, method: string, start_date: string, end_date: string, points: array}
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $simulationType = 'TARGET_CPA'
    ): array {
        $this->ensureClient();

        $query = "SELECT
  campaign_simulation.campaign_id,
  campaign_simulation.type,
  campaign_simulation.modification_method,
  campaign_simulation.start_date,
  campaign_simulation.end_date,
  campaign_simulation.target_cpa_point_list.points,
  campaign_simulation.target_roas_point_list.points,
  campaign_simulation.budget_point_list.points
FROM campaign_simulation
WHERE campaign.resource_name = '{$campaignResourceName}'
  AND campaign_simulation.type = '{$simulationType}'";

        $result = [
            'type'       => $simulationType,
            'method'     => '',
            'start_date' => '',
            'end_date'   => '',
            'points'     => [],
        ];

        try {
            $response = $this->searchQuery($customerId, $query);

            foreach ($response->getIterator() as $row) {
                $simulation = $row->getCampaignSimulation();

                $result['method']     = $this->formatModificationMethod($simulation->getModificationMethod());
                $result['start_date'] = $simulation->getStartDate();
                $result['end_date']   = $simulation->getEndDate();

                // Parse TARGET_CPA points
                $cpaPontList = $simulation->getTargetCpaPointList();
                if ($cpaPontList && $simulationType === 'TARGET_CPA') {
                    foreach ($cpaPontList->getPoints() as $point) {
                        $result['points'][] = [
                            'target_cpa'             => $point->getTargetCpaMicros() / 1_000_000,
                            'budget'                 => null,
                            'projected_conversions'  => (float) $point->getBiddableConversions(),
                            'projected_clicks'       => (int) $point->getClicks(),
                            'projected_cost'         => $point->getCostMicros() / 1_000_000,
                            'projected_impressions'  => (int) $point->getImpressions(),
                        ];
                    }
                }

                // Parse BUDGET points
                $budgetPointList = $simulation->getBudgetPointList();
                if ($budgetPointList && $simulationType === 'BUDGET') {
                    foreach ($budgetPointList->getPoints() as $point) {
                        $result['points'][] = [
                            'target_cpa'             => null,
                            'budget'                 => $point->getBudgetAmountMicros() / 1_000_000,
                            'projected_conversions'  => (float) $point->getBiddableConversions(),
                            'projected_clicks'       => (int) $point->getClicks(),
                            'projected_cost'         => $point->getCostMicros() / 1_000_000,
                            'projected_impressions'  => (int) $point->getImpressions(),
                        ];
                    }
                }

                // Only process first matching simulation row
                break;
            }
        } catch (GoogleAdsException $e) {
            Log::error('GetBidSimulations: Query failed', [
                'customer_id'     => $customerId,
                'campaign'        => $campaignResourceName,
                'simulation_type' => $simulationType,
                'error'           => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('GetBidSimulations: Unexpected error', [
                'customer_id'     => $customerId,
                'campaign'        => $campaignResourceName,
                'simulation_type' => $simulationType,
                'error'           => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Convenience method to fetch BUDGET simulations.
     */
    public function getBudgetSimulations(string $customerId, string $campaignResourceName): array
    {
        return $this($customerId, $campaignResourceName, 'BUDGET');
    }

    private function formatModificationMethod(int $method): string
    {
        // SimulationModificationMethod enum: 1=UNKNOWN, 2=UNIFORM, 3=DEFAULT, 4=SCALING
        return match ($method) {
            2 => 'UNIFORM',
            3 => 'DEFAULT',
            4 => 'SCALING',
            default => 'UNKNOWN',
        };
    }
}
