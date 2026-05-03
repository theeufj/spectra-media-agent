<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetCampaignKeywords extends BaseGoogleAdsService
{
    /**
     * Return all enabled keywords for a campaign.
     *
     * $segmented = true  → includes metrics.clicks/conversions via segments.date DURING
     *                      $dateRange. Keywords with zero activity in the window are excluded.
     *                      Useful for performance reports.
     * $segmented = false → no metrics, no date filter. Every keyword is returned regardless
     *                      of activity. clicks/conversions will be null. Useful when you need
     *                      the full keyword list for expansion logic.
     *
     * Each row contains:
     *   keyword_text, match_type (BROAD|PHRASE|EXACT), criterion_resource,
     *   ad_group_resource, clicks (null when not segmented), conversions (null when not segmented)
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS',
        bool $segmented = true
    ): array {
        $this->ensureClient();

        if ($segmented) {
            $query = "SELECT
                        ad_group_criterion.keyword.text,
                        ad_group_criterion.keyword.match_type,
                        ad_group_criterion.resource_name,
                        ad_group.resource_name,
                        metrics.clicks,
                        metrics.conversions
                      FROM ad_group_criterion
                      WHERE campaign.resource_name = '$campaignResourceName'
                        AND ad_group_criterion.type = 'KEYWORD'
                        AND ad_group_criterion.status = 'ENABLED'
                        AND ad_group.status = 'ENABLED'
                        AND segments.date DURING $dateRange";
        } else {
            // Metrics are not allowed on ad_group_criterion without date segmentation.
            // Return structure only — callers that need click data should use $segmented=true
            // with a wide date range, or use GetKeywordPerformance separately.
            $query = "SELECT
                        ad_group_criterion.keyword.text,
                        ad_group_criterion.keyword.match_type,
                        ad_group_criterion.resource_name,
                        ad_group.resource_name
                      FROM ad_group_criterion
                      WHERE campaign.resource_name = '$campaignResourceName'
                        AND ad_group_criterion.type = 'KEYWORD'
                        AND ad_group_criterion.status = 'ENABLED'
                        AND ad_group.status = 'ENABLED'";
        }

        try {
            $response = $this->searchQuery($customerId, $query);
            $keywords = [];

            foreach ($response->getIterator() as $row) {
                $kw        = $row->getAdGroupCriterion()->getKeyword();
                $metrics   = $segmented ? $row->getMetrics() : null;
                $keywords[] = [
                    'keyword_text'       => $kw->getText(),
                    'match_type'         => $kw->getMatchType(),
                    'criterion_resource' => $row->getAdGroupCriterion()->getResourceName(),
                    'ad_group_resource'  => $row->getAdGroup()->getResourceName(),
                    'clicks'             => $metrics?->getClicks(),
                    'conversions'        => $metrics?->getConversions(),
                ];
            }

            return $keywords;
        } catch (GoogleAdsException $e) {
            $this->logError('GetCampaignKeywords: ' . $e->getMessage());
            return [];
        }
    }
}
