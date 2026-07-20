<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateAdGroupStatus extends BaseGoogleAdsService
{
    /**
     * Update the status of a Google Ads ad group.
     *
     * @param string $customerId          Google Ads customer ID (no dashes)
     * @param string $adGroupResourceName e.g. "customers/123/adGroups/456"
     * @param string $status              'ENABLED', 'PAUSED', or 'REMOVED'
     * @return array{success: bool, resource_name?: string, new_status?: string, error?: string}
     */
    public function execute(string $customerId, string $adGroupResourceName, string $status): array
    {
        $this->ensureClient();

        try {
            $statusEnum = match (strtoupper($status)) {
                'ENABLED' => AdGroupStatus::ENABLED,
                'PAUSED'  => AdGroupStatus::PAUSED,
                'REMOVED' => AdGroupStatus::REMOVED,
                default   => throw new \InvalidArgumentException("Invalid status: {$status}."),
            };

            $adGroup = new AdGroup([
                'resource_name' => $adGroupResourceName,
                'status'        => $statusEnum,
            ]);

            $operation = new AdGroupOperation();
            $operation->setUpdate($adGroup);
            $operation->setUpdateMask(new FieldMask(['paths' => ['status']]));

            $this->client->getAdGroupServiceClient()->mutateAdGroups(
                new MutateAdGroupsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$operation],
                ])
            );

            $this->logInfo("Ad group status updated: {$adGroupResourceName} -> {$status}");

            return ['success' => true, 'resource_name' => $adGroupResourceName, 'new_status' => $status];
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to update ad group status: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function pause(string $customerId, string $adGroupResourceName): array
    {
        return $this->execute($customerId, $adGroupResourceName, 'PAUSED');
    }
}
