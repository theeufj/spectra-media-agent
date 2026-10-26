<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;

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
        if ($customerId !== $this->customer->cleanGoogleCustomerId()
            || $campaignResourceName !== null && ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/[0-9]+$#D', $campaignResourceName)) {
            throw new \InvalidArgumentException('Recommendation scope must match the customer account.');
        }

        $where = 'recommendation.dismissed = FALSE';
        if ($campaignResourceName) {
            $where .= " AND recommendation.campaign = '$campaignResourceName'";
        }

        $query = "SELECT
                    recommendation.resource_name,
                    recommendation.type,
                    recommendation.campaign,
                    recommendation.keyword_recommendation
                  FROM recommendation
                  WHERE {$where}";

        try {
            $response = $this->searchQuery($customerId, $query);
            $recommendations = [];

            foreach ($response->getIterator() as $row) {
                $rec = $row->getRecommendation();
                $recommendations[] = [
                    'resource_name' => $rec->getResourceName(),
                    'type' => $rec->getType(),
                    'campaign_resource' => $rec->getCampaign(),
                    'keyword' => $rec->getKeywordRecommendation() ? [
                        'text' => $rec->getKeywordRecommendation()->getKeyword()?->getText(),
                        'match_type' => $rec->getKeywordRecommendation()->getKeyword()?->getMatchType(),
                        'recommended_cpc_bid_micros' => $rec->getKeywordRecommendation()->hasRecommendedCpcBidMicros()
                            ? (int) $rec->getKeywordRecommendation()->getRecommendedCpcBidMicros() : null,
                    ] : null,
                ];
            }

            return $recommendations;
        } catch (GoogleAdsException $e) {
            $this->logError('GetGoogleAdsRecommendations: '.$e->getMessage(), $e);

            throw $e;
        }
    }
}
