<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Services\PromoteExperimentRequest;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * PromoteCampaignExperiment
 *
 * Promotes a winning experiment arm to the base campaign.
 * Starts the long-running promotion without polling for its completion.
 */
class PromoteCampaignExperiment extends BaseGoogleAdsService
{
    /**
     * @param  string  $experimentResourceName  e.g. "customers/123/experiments/456"
     */
    public function __invoke(string $customerId, string $experimentResourceName): bool
    {
        $this->ensureClient();

        try {
            $this->client->getExperimentServiceClient()->promoteExperiment(new PromoteExperimentRequest([
                'validate_only' => $this->dryRun,
                'resource_name' => $experimentResourceName,
            ]));

            $this->logInfo("PromoteCampaignExperiment: Promotion initiated for {$experimentResourceName}");

            return true;
        } catch (GoogleAdsException|ApiException $e) {
            report($e);
            Log::error('PromoteCampaignExperiment: Failed to promote experiment', [
                'customer_id' => $customerId,
                'experiment' => $experimentResourceName,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            report($e);
            Log::error('PromoteCampaignExperiment: Unexpected error', [
                'customer_id' => $customerId,
                'experiment' => $experimentResourceName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
