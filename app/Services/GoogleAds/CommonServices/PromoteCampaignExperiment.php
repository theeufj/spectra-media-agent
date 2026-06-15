<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * PromoteCampaignExperiment
 *
 * Promotes a winning experiment arm to the base campaign.
 * Uses promoteExperimentAsync for non-blocking promotion.
 */
class PromoteCampaignExperiment extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  string $experimentResourceName  e.g. "customers/123/experiments/456"
     * @return bool
     */
    public function __invoke(string $customerId, string $experimentResourceName): bool
    {
        $this->ensureClient();

        try {
            $this->client->getExperimentServiceClient()->promoteExperimentAsync(
                $experimentResourceName
            );

            $this->logInfo("PromoteCampaignExperiment: Promotion initiated for {$experimentResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            Log::error('PromoteCampaignExperiment: Failed to promote experiment', [
                'customer_id' => $customerId,
                'experiment'  => $experimentResourceName,
                'error'       => $e->getMessage(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('PromoteCampaignExperiment: Unexpected error', [
                'customer_id' => $customerId,
                'experiment'  => $experimentResourceName,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }
}
