<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\ApplyRecommendationOperation;
use Google\Ads\GoogleAds\V22\Services\ApplyRecommendationRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class ApplyRecommendation extends BaseGoogleAdsService
{
    /**
     * Apply one or more Google Ads recommendations by resource name,
     * using Google's own suggested values (no override parameters).
     *
     * @param  string   $customerId
     * @param  string[] $resourceNames
     * @return bool
     */
    public function __invoke(string $customerId, array $resourceNames): bool
    {
        if (empty($resourceNames)) {
            return true;
        }

        $this->ensureClient();

        $operations = array_map(
            fn(string $name) => new ApplyRecommendationOperation(['resource_name' => $name]),
            $resourceNames
        );

        try {
            $this->client->getRecommendationServiceClient()->applyRecommendation(
                new ApplyRecommendationRequest([
                    'customer_id' => $customerId,
                    'operations'  => $operations,
                ])
            );

            $this->logInfo('ApplyRecommendation: Applied ' . count($resourceNames) . ' recommendation(s)');
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('ApplyRecommendation: ' . $e->getMessage());
            return false;
        }
    }
}
