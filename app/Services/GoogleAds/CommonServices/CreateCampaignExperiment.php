<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Experiment;
use Google\Ads\GoogleAds\V22\Resources\ExperimentArm;
use Google\Ads\GoogleAds\V22\Services\ExperimentOperation;
use Google\Ads\GoogleAds\V22\Services\ExperimentArmOperation;
use Google\Ads\GoogleAds\V22\Enums\ExperimentTypeEnum\ExperimentType;
use Google\Ads\GoogleAds\V22\Enums\ExperimentStatusEnum\ExperimentStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * CreateCampaignExperiment
 *
 * Creates a Google Ads Campaign Experiment (A/B test) with control and treatment arms.
 * Schedules the experiment to start automatically.
 *
 * Returns ['experiment_resource' => string, 'treatment_arm_resource' => string] or null on failure.
 */
class CreateCampaignExperiment extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  string $baseCampaignResourceName   e.g. "customers/123/campaigns/456"
     * @param  string $experimentName
     * @param  array  $config
     *   'traffic_split' => int (% to treatment, default 50)
     *   'start_date'    => string "Y-m-d" (default tomorrow)
     *   'end_date'      => string "Y-m-d" (default +30 days)
     * @return array{experiment_resource: string, treatment_arm_resource: string}|null
     */
    public function __invoke(
        string $customerId,
        string $baseCampaignResourceName,
        string $experimentName,
        array $config = []
    ): ?array {
        $this->ensureClient();

        $trafficSplit = (int) ($config['traffic_split'] ?? 50);
        $startDate    = $config['start_date'] ?? now()->addDay()->format('Y-m-d');
        $endDate      = $config['end_date'] ?? now()->addDays(30)->format('Y-m-d');

        try {
            // 1. Create the Experiment resource
            $experiment = new Experiment([
                'name'                  => $experimentName,
                'type'                  => ExperimentType::SEARCH_CUSTOM,
                'traffic_split_percent' => $trafficSplit,
                'start_date'            => $startDate,
                'end_date'              => $endDate,
            ]);

            $experimentOperation = new ExperimentOperation();
            $experimentOperation->setCreate($experiment);

            $experimentServiceClient = $this->client->getExperimentServiceClient();
            $experimentResponse = $experimentServiceClient->mutateExperiments($customerId, [$experimentOperation]);
            $experimentResource = $experimentResponse->getResults()[0]->getResourceName();

            Log::info("CreateCampaignExperiment: Created experiment {$experimentResource}");

            // 2. Create the control arm (base campaign)
            $controlArm = new ExperimentArm([
                'experiment'    => $experimentResource,
                'control'       => true,
                'campaigns'     => [$baseCampaignResourceName],
                'traffic_split' => 100 - $trafficSplit,
                'name'          => 'Control',
            ]);

            $controlArmOperation = new ExperimentArmOperation();
            $controlArmOperation->setCreate($controlArm);

            // 3. Create the treatment arm
            $treatmentArm = new ExperimentArm([
                'experiment'    => $experimentResource,
                'control'       => false,
                'traffic_split' => $trafficSplit,
                'name'          => 'Treatment',
            ]);

            $treatmentArmOperation = new ExperimentArmOperation();
            $treatmentArmOperation->setCreate($treatmentArm);

            $experimentArmServiceClient = $this->client->getExperimentArmServiceClient();
            $armResponse = $experimentArmServiceClient->mutateExperimentArms(
                $customerId,
                [$controlArmOperation, $treatmentArmOperation]
            );

            $treatmentArmResource = null;
            foreach ($armResponse->getResults() as $armResult) {
                // The treatment arm is the second operation result
                $treatmentArmResource = $armResult->getResourceName();
            }

            // 4. Schedule the experiment to start
            $experimentServiceClient->scheduleExperiment($experimentResource);

            Log::info("CreateCampaignExperiment: Scheduled experiment {$experimentResource}", [
                'treatment_arm' => $treatmentArmResource,
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'traffic_split' => $trafficSplit,
            ]);

            return [
                'experiment_resource'    => $experimentResource,
                'treatment_arm_resource' => $treatmentArmResource,
            ];
        } catch (GoogleAdsException|ApiException $e) {
            Log::error('CreateCampaignExperiment: Failed to create experiment', [
                'customer_id' => $customerId,
                'campaign'    => $baseCampaignResourceName,
                'name'        => $experimentName,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }
}
