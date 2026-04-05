<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateKeywordBid extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $criterionResourceName, int $cpcBidMicros): bool
    {
        $this->ensureClient();

        $criterion = new AdGroupCriterion([
            'resource_name' => $criterionResourceName,
            'cpc_bid_micros' => $cpcBidMicros,
        ]);

        $operation = new AdGroupCriterionOperation();
        $operation->setUpdate($criterion);
        $operation->setUpdateMask(new FieldMask(['paths' => ['cpc_bid_micros']]));

        try {
            $this->client->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                new MutateAdGroupCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );
            $this->logInfo("Updated keyword bid to {$cpcBidMicros} micros: {$criterionResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to update keyword bid: " . $e->getMessage());
            return false;
        }
    }
}
