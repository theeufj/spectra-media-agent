<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Protobuf\FieldMask;

class UpdateCampaignStatus extends BaseGoogleAdsService
{
    /**
     * Update the status of a Google Ads campaign.
     *
     * @param string $campaignResourceName The resource name of the campaign (e.g., "customers/123/campaigns/456")
     * @param string $status The new status: 'ENABLED', 'PAUSED', or 'REMOVED'
     * @return array Result with success status and message
     */
    public function execute(string $campaignResourceName, string $status): array
    {
        try {
            $statusEnum = match (strtoupper($status)) {
                'ENABLED' => CampaignStatus::ENABLED,
                'PAUSED' => CampaignStatus::PAUSED,
                'REMOVED' => CampaignStatus::REMOVED,
                default => throw new \InvalidArgumentException("Invalid status: {$status}. Must be ENABLED, PAUSED, or REMOVED."),
            };

            // Create campaign with new status
            $campaign = new Campaign([
                'resource_name' => $campaignResourceName,
                'status' => $statusEnum,
            ]);

            // Create field mask for update
            $fieldMask = new FieldMask([
                'paths' => ['status']
            ]);

            // Create operation
            $operation = new CampaignOperation();
            $operation->setUpdate($campaign);
            $operation->setUpdateMask($fieldMask);

            // Execute the mutation
            $campaignServiceClient = $this->googleAdsClient->getCampaignServiceClient();
            $response = $campaignServiceClient->mutateCampaigns(
                $this->customerId,
                [$operation]
            );

            $updatedCampaign = $response->getResults()[0];
            
            $this->logInfo("Campaign status updated: {$campaignResourceName} -> {$status}");
            
            return [
                'success' => true,
                'resource_name' => $updatedCampaign->getResourceName(),
                'new_status' => $status,
                'message' => "Campaign status updated to {$status}",
            ];

        } catch (\Exception $e) {
            $this->logError("Failed to update campaign status: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignResourceName): array
    {
        return $this->execute($campaignResourceName, 'PAUSED');
    }

    /**
     * Enable/start a campaign.
     */
    public function enable(string $campaignResourceName): array
    {
        return $this->execute($campaignResourceName, 'ENABLED');
    }
}
