<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetSearchTermsReport extends BaseGoogleAdsService
{
    /**
     * Get the Search Terms Report for a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $dateRange 'LAST_30_DAYS', 'LAST_7_DAYS', etc.
     * @return array List of search terms with performance metrics
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $this->ensureClient();

        $query = "SELECT " .
                 "search_term_view.search_term, " .
                 "search_term_view.status, " .
                 "metrics.impressions, " .
                 "metrics.clicks, " .
                 "metrics.cost_micros, " .
                 "metrics.conversions, " .
                 "metrics.ctr, " .
                 "campaign.resource_name, " .
                 "ad_group.resource_name " .
                 "FROM search_term_view " .
                 "WHERE campaign.resource_name = '$campaignResourceName' " .
                 "AND segments.date DURING $dateRange " .
                 "ORDER BY metrics.cost_micros DESC";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $searchTerms = [];
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $searchTermView = $googleAdsRow->getSearchTermView();
                $metrics = $googleAdsRow->getMetrics();

                $searchTerms[] = [
                    'search_term' => $searchTermView->getSearchTerm(),
                    'status' => $searchTermView->getStatus(),
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'cost_micros' => $metrics->getCostMicros(),
                    'cost' => $metrics->getCostMicros() / 1000000,
                    'conversions' => $metrics->getConversions(),
                    'ctr' => $metrics->getCtr(),
                    'campaign_resource_name' => $googleAdsRow->getCampaign()->getResourceName(),
                    'ad_group_resource_name' => $googleAdsRow->getAdGroup()->getResourceName(),
                ];
            }

            return $searchTerms;

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to get search terms report: " . $e->getMessage());
            return [];
        }
    }
}
