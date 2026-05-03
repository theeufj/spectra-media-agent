<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetGoogleAdsRecommendations extends BaseGoogleAdsService
{
    /**
     * Fetch all active (non-dismissed) recommendations for a customer.
     *
     * Returns an array of recommendation rows, each with:
     *   resource_name, type (string label), campaign_resource
     *
     * Optionally filtered to a single campaign resource name.
     */
    public function __invoke(string $customerId, ?string $campaignResourceName = null): array
    {
        $this->ensureClient();

        $where = "recommendation.dismissed = FALSE";
        if ($campaignResourceName) {
            $where .= " AND recommendation.campaign = '$campaignResourceName'";
        }

        $query = "SELECT
                    recommendation.resource_name,
                    recommendation.type,
                    recommendation.campaign
                  FROM recommendation
                  WHERE {$where}";

        try {
            $response        = $this->searchQuery($customerId, $query);
            $recommendations = [];

            foreach ($response->getIterator() as $row) {
                $rec               = $row->getRecommendation();
                $recommendations[] = [
                    'resource_name'     => $rec->getResourceName(),
                    'type'              => $rec->getType(),
                    'campaign_resource' => $rec->getCampaign(),
                ];
            }

            return $recommendations;
        } catch (GoogleAdsException $e) {
            $this->logError('GetGoogleAdsRecommendations: ' . $e->getMessage());
            return [];
        }
    }
}
