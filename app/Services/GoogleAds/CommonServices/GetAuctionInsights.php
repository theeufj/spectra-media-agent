<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * GetAuctionInsights Service
 *
 * Fetches Auction Insights data from Google Ads to understand
 * competitive positioning: impression share, overlap rate, position above rate, etc.
 *
 * Auction Insights is not a resource. There is no `campaign_auction_insight_result`
 * in V22 and no `GoogleAdsRow::getAuctionInsight()` — the report is the `campaign`
 * resource segmented by `segments.auction_insight_domain`, with the figures on
 * `metrics.auction_insight_search_*`. Querying the resource form failed with
 * INVALID_ARGUMENT on every call, which the catch below then reported as
 * "no auction data available yet", so CompetitorIntelligenceAgent saw an empty
 * array forever and never discovered a competitor.
 */
class GetAuctionInsights extends BaseGoogleAdsService
{
    /**
     * Get Auction Insights for a campaign.
     *
     * @param  string  $customerId  The Google Ads customer ID
     * @param  string  $campaignResourceName  The campaign resource name
     * @param  string  $dateRange  Date range for insights (LAST_30_DAYS, LAST_7_DAYS, etc.)
     * @return array Auction insights data with competitor domains
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        $query = 'SELECT '.
                 'campaign.name, '.
                 'segments.auction_insight_domain, '.
                 'metrics.auction_insight_search_impression_share, '.
                 'metrics.auction_insight_search_overlap_rate, '.
                 'metrics.auction_insight_search_position_above_rate, '.
                 'metrics.auction_insight_search_top_impression_percentage, '.
                 'metrics.auction_insight_search_absolute_top_impression_percentage, '.
                 'metrics.auction_insight_search_outranking_share '.
                 'FROM campaign '.
                 "WHERE campaign.resource_name = '$campaignResourceName' ".
                 "AND segments.date DURING $dateRange";

        try {
            $response = $this->searchQuery($customerId, $query);

            $insights = [
                'campaign_name' => null,
                'campaign_resource' => $campaignResourceName,
                'date_range' => $dateRange,
                'our_metrics' => null,
                'competitors' => [],
            ];

            foreach ($response->getIterator() as $googleAdsRow) {
                $segments = $googleAdsRow->getSegments();
                $metrics = $googleAdsRow->getMetrics();
                $campaign = $googleAdsRow->getCampaign();

                $domain = $segments?->getAuctionInsightDomain() ?? '';

                if ($domain === '') {
                    continue;
                }

                // Campaign info (same for all rows)
                if (! $insights['campaign_name']) {
                    $insights['campaign_name'] = $campaign?->getName();
                }

                $metricsData = [
                    'domain' => $domain,
                    'impression_share' => $this->formatPercentage($metrics?->getAuctionInsightSearchImpressionShare()),
                    'overlap_rate' => $this->formatPercentage($metrics?->getAuctionInsightSearchOverlapRate()),
                    'position_above_rate' => $this->formatPercentage($metrics?->getAuctionInsightSearchPositionAboveRate()),
                    'top_of_page_rate' => $this->formatPercentage($metrics?->getAuctionInsightSearchTopImpressionPercentage()),
                    'abs_top_of_page_rate' => $this->formatPercentage($metrics?->getAuctionInsightSearchAbsoluteTopImpressionPercentage()),
                    'outranking_share' => $this->formatPercentage($metrics?->getAuctionInsightSearchOutrankingShare()),
                ];

                // Identify if this is our domain or a competitor
                if ($this->isOurDomain($domain)) {
                    $insights['our_metrics'] = $metricsData;
                } else {
                    $insights['competitors'][] = $metricsData;
                }
            }

            // Sort competitors by impression share descending
            usort($insights['competitors'], function ($a, $b) {
                return $b['impression_share'] <=> $a['impression_share'];
            });

            Log::info('GetAuctionInsights: Retrieved insights', [
                'customer_id' => $customerId,
                'campaign' => $campaignResourceName,
                'competitor_count' => count($insights['competitors']),
            ]);

            // No rows is the genuine "not enough auction data yet" answer, and it
            // is a success: the caller counts the campaign as analysed rather
            // than discarding it as an error.
            return $insights;

        } catch (GoogleAdsException|ApiException $e) {
            // INVALID_ARGUMENT is a malformed query, not thin data. Reporting it
            // as "insufficient data" is what hid a report that never worked.
            $this->logError('Failed to fetch auction insights: '.$e->getMessage(), $e);

            return [
                'campaign_name' => null,
                'campaign_resource' => $campaignResourceName,
                'date_range' => $dateRange,
                'our_metrics' => null,
                'competitors' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Auction Insights for all active campaigns of a customer.
     */
    public function getAllCampaigns(string $customerId, string $dateRange = 'LAST_30_DAYS'): array
    {
        $this->ensureClient();

        $allInsights = [];

        // First, get all active search campaigns
        $campaignQuery = 'SELECT campaign.resource_name, campaign.name '.
                        'FROM campaign '.
                        "WHERE campaign.status = 'ENABLED' ".
                        "AND campaign.advertising_channel_type IN ('SEARCH', 'SHOPPING')";

        try {
            $response = $this->searchQuery($customerId, $campaignQuery);

            foreach ($response->getIterator() as $googleAdsRow) {
                $campaignResourceName = $googleAdsRow->getCampaign()->getResourceName();
                $insights = $this($customerId, $campaignResourceName, $dateRange);

                if (! isset($insights['error']) || empty($insights['error'])) {
                    $allInsights[] = $insights;
                }
            }

            return $allInsights;

        } catch (GoogleAdsException|ApiException $e) {
            Log::error('GetAuctionInsights: Failed to get all campaigns', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Format a double value as a percentage.
     */
    protected function formatPercentage(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value * 100, 2);
    }

    /**
     * Is this row the advertiser's own line rather than a competitor's?
     *
     * The UI labels it "You"; the API reports the account's own display domain,
     * so the customer's website host is the reliable test and the literal is
     * kept as a fallback.
     */
    protected function isOurDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        if ($domain === 'you' || $domain === 'your domain') {
            return true;
        }

        $website = strtolower(trim((string) $this->customer?->website));

        if ($website === '') {
            return false;
        }

        // A stored website is as often "example.com" as "https://example.com/",
        // and parse_url returns no host for the first form.
        $ourHost = parse_url($website, PHP_URL_HOST) ?: explode('/', $website)[0];
        $ourHost = preg_replace('/^www\./', '', (string) $ourHost);

        return $ourHost !== '' && $domain === $ourHost;
    }
}
