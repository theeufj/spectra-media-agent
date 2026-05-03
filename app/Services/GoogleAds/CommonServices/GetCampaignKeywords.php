<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetCampaignKeywords extends BaseGoogleAdsService
{
    /**
     * Return all enabled keywords for a campaign.
     *
     * $segmented = true  → uses segments.date DURING $dateRange; keywords with zero
     *                      activity in the window are excluded. Useful for performance reports.
     * $segmented = false → no date segment; every keyword is returned (including zero-click
     *                      ones) with all-time aggregated metrics and creation_time. Useful
     *                      for expansion/pruning logic.
     *
     * Each row contains:
     *   keyword_text, match_type (BROAD|PHRASE|EXACT), criterion_resource,
     *   ad_group_resource, clicks, conversions, creation_time (only when $segmented=false)
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
            $query = "SELECT
                        ad_group_criterion.keyword.text,
                        ad_group_criterion.keyword.match_type,
                        ad_group_criterion.resource_name,
                        ad_group.resource_name,
                        ad_group_criterion.creation_time,
                        metrics.clicks,
                        metrics.conversions
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
                $kw      = $row->getAdGroupCriterion()->getKeyword();
                $entry   = [
                    'keyword_text'       => $kw->getText(),
                    'match_type'         => $kw->getMatchType(),
                    'criterion_resource' => $row->getAdGroupCriterion()->getResourceName(),
                    'ad_group_resource'  => $row->getAdGroup()->getResourceName(),
                    'clicks'             => $row->getMetrics()->getClicks(),
                    'conversions'        => $row->getMetrics()->getConversions(),
                ];

                if (!$segmented) {
                    $entry['creation_time'] = $row->getAdGroupCriterion()->getCreationTime();
                }

                $keywords[] = $entry;
            }

            return $keywords;
        } catch (GoogleAdsException $e) {
            $this->logError('GetCampaignKeywords: ' . $e->getMessage());
            return [];
        }
    }
}
