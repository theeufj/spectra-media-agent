<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateCampaignStatus extends BaseGoogleAdsService
{
    /**
     * Update the status of a Google Ads campaign.
     *
     * @param string $customerId Google Ads customer ID (no dashes)
     * @param string $campaignResourceName e.g. "customers/1234567890/campaigns/9876543210"
     * @param string $status 'ENABLED', 'PAUSED', or 'REMOVED'
     * @return array{success: bool, resource_name?: string, new_status?: string, error?: string}
     */
    public function execute(string $customerId, string $campaignResourceName, string $status): array
    {
        $this->ensureClient();

        try {
            $statusEnum = match (strtoupper($status)) {
                'ENABLED' => CampaignStatus::ENABLED,
                'PAUSED' => CampaignStatus::PAUSED,
                'REMOVED' => CampaignStatus::REMOVED,
                default => throw new \InvalidArgumentException("Invalid status: {$status}. Must be ENABLED, PAUSED, or REMOVED."),
            };

            $campaign = new Campaign([
                'resource_name' => $campaignResourceName,
                'status' => $statusEnum,
            ]);

            $operation = new CampaignOperation();
            $operation->setUpdate($campaign);
            $operation->setUpdateMask(new FieldMask(['paths' => ['status']]));

            $this->client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $this->logInfo("Campaign status updated: {$campaignResourceName} -> {$status}");

            return [
                'success' => true,
                'resource_name' => $campaignResourceName,
                'new_status' => $status,
            ];
        } catch (GoogleAdsException|ApiException $e) {
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
    public function pause(string $customerId, string $campaignResourceName): array
    {
        return $this->execute($customerId, $campaignResourceName, 'PAUSED');
    }

    /**
     * Enable/start a campaign.
     */
    public function enable(string $customerId, string $campaignResourceName): array
    {
        return $this->execute($customerId, $campaignResourceName, 'ENABLED');
    }
}
