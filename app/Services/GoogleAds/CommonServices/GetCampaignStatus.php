<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignServiceClient;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetCampaignStatus extends BaseGoogleAdsService
{
    /**
     * Get the status of a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @return array|null Array containing 'status', 'primary_status', 'primary_status_reasons'
     */
    public function __invoke(string $customerId, string $campaignResourceName): ?array
    {
        $this->ensureClient();

        $query = "SELECT campaign.status, campaign.primary_status, campaign.primary_status_reasons " .
                 "FROM campaign " .
                 "WHERE campaign.resource_name = '$campaignResourceName'";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var Campaign $campaign */
                $campaign = $googleAdsRow->getCampaign();
                
                return [
                    'status' => $campaign->getStatus(), // Enum int
                    'primary_status' => $campaign->getPrimaryStatus(), // Enum int
                    'primary_status_reasons' => $campaign->getPrimaryStatusReasons(), // Repeated field
                ];
            }

            return null; // Not found
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to get campaign status: " . $e->getMessage());
            return null;
        }
    }
}
