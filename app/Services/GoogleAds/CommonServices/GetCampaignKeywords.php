<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetCampaignKeywords extends BaseGoogleAdsService
{
    /**
     * Return all enabled keywords for a campaign, grouped by ad group.
     *
     * Each row contains:
     *   keyword_text, match_type (BROAD|PHRASE|EXACT), ad_group_resource_name,
     *   clicks, conversions (LAST_30_DAYS)
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $this->ensureClient();

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

        try {
            $response   = $this->searchQuery($customerId, $query);
            $keywords   = [];

            foreach ($response->getIterator() as $row) {
                $kw = $row->getAdGroupCriterion()->getKeyword();
                $keywords[] = [
                    'keyword_text'          => $kw->getText(),
                    'match_type'            => $kw->getMatchType(),   // KeywordMatchType int
                    'criterion_resource'    => $row->getAdGroupCriterion()->getResourceName(),
                    'ad_group_resource'     => $row->getAdGroup()->getResourceName(),
                    'clicks'                => $row->getMetrics()->getClicks(),
                    'conversions'           => $row->getMetrics()->getConversions(),
                ];
            }

            return $keywords;
        } catch (GoogleAdsException $e) {
            $this->logError('GetCampaignKeywords: ' . $e->getMessage());
            return [];
        }
    }
}
