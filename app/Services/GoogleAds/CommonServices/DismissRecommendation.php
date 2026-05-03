<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\DismissRecommendationRequest;
use Google\Ads\GoogleAds\V22\Services\DismissRecommendationRequest\DismissRecommendationOperation;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class DismissRecommendation extends BaseGoogleAdsService
{
    /**
     * Dismiss one or more Google Ads recommendations by resource name.
     *
     * Dismissing a recommendation removes it from the Google Ads UI immediately.
     * Use this after our agents act on a recommendation so Google knows it's handled.
     *
     * @param  string   $customerId
     * @param  string[] $resourceNames  e.g. ["customers/123/recommendations/abc", ...]
     * @return bool  true if all dismissed successfully
     */
    public function __invoke(string $customerId, array $resourceNames): bool
    {
        if (empty($resourceNames)) {
            return true;
        }

        $this->ensureClient();

        $operations = array_map(
            fn(string $name) => new DismissRecommendationOperation(['resource_name' => $name]),
            $resourceNames
        );

        try {
            $this->client->getRecommendationServiceClient()->dismissRecommendation(
                DismissRecommendationRequest::build($customerId, $operations)
            );

            $this->logInfo('DismissRecommendation: Dismissed ' . count($resourceNames) . ' recommendation(s)');
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('DismissRecommendation: ' . $e->getMessage());
            return false;
        }
    }
}
