<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateKeywordStatus extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $criterionResourceName, int $status): bool
    {
        $this->ensureClient();

        $criterion = new AdGroupCriterion([
            'resource_name' => $criterionResourceName,
            'status' => $status,
        ]);

        $operation = new AdGroupCriterionOperation();
        $operation->setUpdate($criterion);
        $operation->setUpdateMask(new FieldMask(['paths' => ['status']]));

        try {
            $this->client->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                new MutateAdGroupCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );
            $this->logInfo("Updated keyword status to {$status}: {$criterionResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to update keyword status: " . $e->getMessage());
            return false;
        }
    }

    public function pause(string $customerId, string $criterionResourceName): bool
    {
        return $this($customerId, $criterionResourceName, AdGroupCriterionStatus::PAUSED);
    }

    public function enable(string $customerId, string $criterionResourceName): bool
    {
        return $this($customerId, $criterionResourceName, AdGroupCriterionStatus::ENABLED);
    }
}
