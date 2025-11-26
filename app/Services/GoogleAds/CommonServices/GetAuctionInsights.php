<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

/**
 * GetAuctionInsights Service
 * 
 * Fetches Auction Insights data from Google Ads to understand
 * competitive positioning: impression share, overlap rate, position above rate, etc.
 */
class GetAuctionInsights extends BaseGoogleAdsService
{
    /**
     * Get Auction Insights for a campaign.
     *
     * @param string $customerId The Google Ads customer ID
     * @param string $campaignResourceName The campaign resource name
     * @param string $dateRange Date range for insights (LAST_30_DAYS, LAST_7_DAYS, etc.)
     * @return array Auction insights data with competitor domains
     */
    public function __invoke(
        string $customerId, 
        string $campaignResourceName, 
        string $dateRange = 'LAST_30_DAYS'
    ): array {
        $this->ensureClient();

        // Auction Insights query
        $query = "SELECT " .
                 "auction_insight.domain, " .
                 "auction_insight.impression_share, " .
                 "auction_insight.overlap_rate, " .
                 "auction_insight.position_above_rate, " .
                 "auction_insight.top_of_page_rate, " .
                 "auction_insight.abs_top_of_page_rate, " .
                 "auction_insight.outranking_share, " .
                 "campaign.name, " .
                 "campaign.resource_name " .
                 "FROM campaign_auction_insight_result " .
                 "WHERE campaign.resource_name = '$campaignResourceName' " .
                 "AND segments.date DURING $dateRange";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $insights = [
                'campaign_name' => null,
                'date_range' => $dateRange,
                'our_metrics' => null,
                'competitors' => [],
            ];

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $auctionInsight = $googleAdsRow->getAuctionInsight();
                $campaign = $googleAdsRow->getCampaign();
                
                $domain = $auctionInsight->getDomain();
                
                // Campaign info (same for all rows)
                if (!$insights['campaign_name']) {
                    $insights['campaign_name'] = $campaign->getName();
                }

                $metricsData = [
                    'domain' => $domain,
                    'impression_share' => $this->formatPercentage($auctionInsight->getImpressionShare()),
                    'overlap_rate' => $this->formatPercentage($auctionInsight->getOverlapRate()),
                    'position_above_rate' => $this->formatPercentage($auctionInsight->getPositionAboveRate()),
                    'top_of_page_rate' => $this->formatPercentage($auctionInsight->getTopOfPageRate()),
                    'abs_top_of_page_rate' => $this->formatPercentage($auctionInsight->getAbsTopOfPageRate()),
                    'outranking_share' => $this->formatPercentage($auctionInsight->getOutrankingShare()),
                ];

                // Identify if this is our domain or a competitor
                if ($this->isOurDomain($domain, $customerId)) {
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

            return $insights;

        } catch (GoogleAdsException $e) {
            $errorMessage = $e->getMessage();
            
            // Check if it's a "no data" error (common for new campaigns)
            if (str_contains($errorMessage, 'INVALID_ARGUMENT') || 
                str_contains($errorMessage, 'insufficient data')) {
                Log::info('GetAuctionInsights: No auction data available yet', [
                    'customer_id' => $customerId,
                    'campaign' => $campaignResourceName,
                ]);
                return [
                    'campaign_name' => null,
                    'date_range' => $dateRange,
                    'our_metrics' => null,
                    'competitors' => [],
                    'error' => 'Insufficient data for auction insights',
                ];
            }

            Log::error('GetAuctionInsights: Failed to fetch insights', [
                'customer_id' => $customerId,
                'campaign' => $campaignResourceName,
                'error' => $errorMessage,
            ]);
            
            return [
                'error' => $errorMessage,
                'competitors' => [],
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
        $campaignQuery = "SELECT campaign.resource_name, campaign.name " .
                        "FROM campaign " .
                        "WHERE campaign.status = 'ENABLED' " .
                        "AND campaign.advertising_channel_type IN ('SEARCH', 'SHOPPING')";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $campaignQuery);

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $campaignResourceName = $googleAdsRow->getCampaign()->getResourceName();
                $insights = $this($customerId, $campaignResourceName, $dateRange);
                
                if (!isset($insights['error']) || empty($insights['error'])) {
                    $allInsights[] = $insights;
                }
            }

            return $allInsights;

        } catch (GoogleAdsException $e) {
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
     * Check if this is our domain (vs competitor).
     * The auction insights API includes "You" as a special domain indicator.
     */
    protected function isOurDomain(string $domain, string $customerId): bool
    {
        // Google Ads returns "You" or similar for the advertiser's own metrics
        return strtolower($domain) === 'you' || 
               strtolower($domain) === 'your domain';
    }
}
