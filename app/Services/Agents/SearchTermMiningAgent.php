<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use Illuminate\Support\Facades\Log;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;

class SearchTermMiningAgent
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('budget_rules.search_term_mining', []);
    }

    /**
     * Mine search terms for a campaign and make keyword changes.
     *
     * @param Campaign $campaign
     * @return array Results of mining actions
     */
    public function mine(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'keywords_added' => [],
            'negatives_added' => [],
            'terms_analyzed' => 0,
            'errors' => [],
        ];

        if (!$campaign->google_ads_campaign_id || !$campaign->customer) {
            return $results;
        }

        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            $getSearchTerms = new GetSearchTermsReport($customer, true);
            $searchTerms = ($getSearchTerms)($customerId, $campaignResourceName, 'LAST_30_DAYS');

            $results['terms_analyzed'] = count($searchTerms);

            foreach ($searchTerms as $term) {
                $this->evaluateSearchTerm($customer, $customerId, $campaignResourceName, $term, $results);
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Failed to get search terms: " . $e->getMessage();
            Log::error("SearchTermMiningAgent: Failed to mine search terms", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Evaluate a single search term and take action if needed.
     */
    protected function evaluateSearchTerm(
        Customer $customer,
        string $customerId,
        string $campaignResourceName,
        array $term,
        array &$results
    ): void {
        $minImpressions = $this->config['min_impressions'] ?? 100;
        $minClicks = $this->config['min_clicks'] ?? 5;
        $promoteCtrThreshold = $this->config['promote_ctr_threshold'] ?? 0.05;
        $negativeCostThreshold = $this->config['negative_cost_threshold'] ?? 20.00;
        $negativeCtrThreshold = $this->config['negative_ctr_threshold'] ?? 0.002;
        $negativeMinImpressions = $this->config['negative_min_impressions'] ?? 500;

        $searchTerm = $term['search_term'];
        $impressions = $term['impressions'];
        $clicks = $term['clicks'];
        $cost = $term['cost'];
        $conversions = $term['conversions'];
        $ctr = $term['ctr'];

        // Skip if not enough data
        if ($impressions < $minImpressions) {
            return;
        }

        // Check if this is a HIGH PERFORMER → Add as exact match keyword
        if ($clicks >= $minClicks && $ctr >= $promoteCtrThreshold && $conversions > 0) {
            $this->addAsKeyword($customer, $customerId, $term['ad_group_resource_name'], $searchTerm, $results);
            return;
        }

        // Check if this is WASTING MONEY → Add as negative
        if ($cost >= $negativeCostThreshold && $conversions == 0) {
            $this->addAsNegative($customer, $customerId, $campaignResourceName, $searchTerm, 'High cost, no conversions', $results);
            return;
        }

        // Check if this has LOW CTR with high impressions → Add as negative
        if ($impressions >= $negativeMinImpressions && $ctr < $negativeCtrThreshold && $conversions == 0) {
            $this->addAsNegative($customer, $customerId, $campaignResourceName, $searchTerm, 'Low CTR, no conversions', $results);
            return;
        }
    }

    /**
     * Add a search term as an exact match keyword.
     */
    protected function addAsKeyword(Customer $customer, string $customerId, string $adGroupResourceName, string $keyword, array &$results): void
    {
        try {
            $addKeyword = new AddKeyword($customer, true);
            $resourceName = ($addKeyword)($customerId, $adGroupResourceName, $keyword, KeywordMatchType::EXACT);

            if ($resourceName) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => 'EXACT',
                    'resource_name' => $resourceName,
                ];

                Log::info("SearchTermMiningAgent: Added keyword", [
                    'keyword' => $keyword,
                    'ad_group' => $adGroupResourceName,
                ]);
            }
        } catch (\Exception $e) {
            // Might fail if keyword already exists, which is fine
            Log::debug("SearchTermMiningAgent: Could not add keyword (may already exist)", [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a negative keyword.
     */
    protected function addAsNegative(Customer $customer, string $customerId, string $campaignResourceName, string $keyword, string $reason, array &$results): void
    {
        try {
            $addNegative = new AddNegativeKeyword($customer, true);
            $resourceName = ($addNegative)($customerId, $campaignResourceName, $keyword, KeywordMatchType::EXACT);

            if ($resourceName) {
                $results['negatives_added'][] = [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'resource_name' => $resourceName,
                ];

                Log::info("SearchTermMiningAgent: Added negative keyword", [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'campaign' => $campaignResourceName,
                ]);
            }
        } catch (\Exception $e) {
            // Might fail if negative already exists
            Log::debug("SearchTermMiningAgent: Could not add negative (may already exist)", [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
