<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class RemoveKeyword extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $criterionResourceName): bool
    {
        $this->ensureClient();

        $operation = new AdGroupCriterionOperation();
        $operation->setRemove($criterionResourceName);

        try {
            $this->client->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                new MutateAdGroupCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );
            $this->logInfo("Removed keyword: {$criterionResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to remove keyword: " . $e->getMessage());
            return false;
        }
    }
}
