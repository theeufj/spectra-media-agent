<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Keyword;
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
        // Merge legacy budget_rules config with new optimization config (optimization takes precedence)
        $this->config = array_merge(
            config('budget_rules.search_term_mining', []),
            config('optimization.search_terms', [])
        );
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

        if (!$campaign->customer) {
            return $results;
        }

        if ($campaign->google_ads_campaign_id) {
            $this->mineGoogle($campaign, $results);
        }

        if ($campaign->microsoft_ads_campaign_id) {
            $this->mineMicrosoft($campaign, $results);
        }

        return $results;
    }

    /**
     * Mine search terms for Google Ads
     */
    protected function mineGoogle(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            $getSearchTerms = new GetSearchTermsReport($customer, true);
            $searchTerms = ($getSearchTerms)($customerId, $campaignResourceName, 'LAST_30_DAYS');

            $results['terms_analyzed'] += count($searchTerms);

            foreach ($searchTerms as $term) {
                $this->evaluateSearchTerm($customer, $customerId, $campaignResourceName, $term, $results, 'google');
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Failed to get google search terms: " . $e->getMessage();
            Log::error("SearchTermMiningAgent: Failed to mine google search terms", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mine search terms for Microsoft Ads
     */
    protected function mineMicrosoft(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer->microsoft_ads_account_id) {
            return;
        }

        try {
            $perfService = new \App\Services\MicrosoftAds\PerformanceService($customer);
            $searchTerms = $perfService->getSearchTerms($campaign->microsoft_ads_campaign_id);

            $results['terms_analyzed'] += count($searchTerms);

            foreach ($searchTerms as $term) {
                // Map Microsoft structure if needed, or assume it matches Google's exactly via getSearchTerms
                $this->evaluateSearchTerm($customer, $customer->microsoft_ads_account_id, $campaign->microsoft_ads_campaign_id, $term, $results, 'microsoft');
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Failed to get microsoft search terms: " . $e->getMessage();
            Log::error("SearchTermMiningAgent: Failed to mine microsoft search terms", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate a single search term and take action if needed.
     */
    protected function evaluateSearchTerm(
        Customer $customer,
        string $customerId,
        string $campaignResourceName,
        array $term,
        array &$results,
        string $platform = 'google'
    ): void {
        $minImpressions        = $this->config['min_impressions']          ?? 300;
        $minClicks             = $this->config['min_clicks']               ?? 5;
        $promoteCtrThreshold   = $this->config['promote_ctr_threshold']    ?? 0.05;
        $negativeCostThreshold = $this->config['negative_cost_threshold']  ?? 50.00;
        $negativeCtrThreshold  = $this->config['negative_ctr_threshold']   ?? 0.002;
        $negativeMinImpressions = $this->config['negative_min_impressions'] ?? 500;
        $negativeMatchType     = $this->config['negative_match_type']      ?? 'PHRASE';

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

        // Check if this is a HIGH PERFORMER → Add as keyword with match type based on conversion volume
        if ($clicks >= $minClicks && $ctr >= $promoteCtrThreshold && $conversions > 0) {
            $matchType = $this->determineMatchType($conversions, $platform);
            if ($platform === 'google') {
                $this->addAsKeyword($customer, $customerId, $term['ad_group_resource_name'], $searchTerm, $matchType, $results);
            } else {
                $this->addAsMicrosoftKeyword($customer, $term['ad_group_resource_name'], $searchTerm, $matchType, $results);
            }
            return;
        }

        // Check if this is WASTING MONEY → Add as negative
        // Safety: never auto-negative a term that has converted, regardless of other signals
        if ($cost >= $negativeCostThreshold && $conversions == 0) {
            if ($platform === 'google') {
                $this->addAsNegative($customer, $customerId, $campaignResourceName, $searchTerm, 'High cost, no conversions', $results, $negativeMatchType);
            } else {
                $this->addAsMicrosoftNegative($customer, $campaignResourceName, $searchTerm, 'High cost, no conversions', $results);
            }
            return;
        }

        // Check if this has LOW CTR with high impressions → Add as negative
        if ($impressions >= $negativeMinImpressions && $ctr < $negativeCtrThreshold && $conversions == 0) {
            if ($platform === 'google') {
                $this->addAsNegative($customer, $customerId, $campaignResourceName, $searchTerm, 'Low CTR, no conversions', $results, $negativeMatchType);
            } else {
                $this->addAsMicrosoftNegative($customer, $campaignResourceName, $searchTerm, 'Low CTR, no conversions', $results);
            }
            return;
        }
    }

    /**
     * Determine match type based on conversion volume.
     * High-confidence terms (10+ conversions) → EXACT
     * Medium-confidence (5-9 conversions) → PHRASE
     * Low-confidence (1-4 conversions) → BROAD
     */
    protected function determineMatchType(float $conversions, string $platform = 'google'): int|string
    {
        if ($platform === 'google') {
            if ($conversions >= 10) {
                return KeywordMatchType::EXACT;
            }
            if ($conversions >= 5) {
                return KeywordMatchType::PHRASE;
            }
            return KeywordMatchType::BROAD;
        } else {
            // Microsoft uses strings instead of enums
            if ($conversions >= 10) {
                return 'Exact';
            }
            if ($conversions >= 5) {
                return 'Phrase';
            }
            return 'Broad';
        }
    }

    /**
     * Add a search term as a keyword with the specified match type.
     */
    protected function addAsKeyword(Customer $customer, string $customerId, string $adGroupResourceName, string $keyword, int $matchType, array &$results): void
    {
        try {
            $addKeyword = new AddKeyword($customer, true);
            $resourceName = ($addKeyword)($customerId, $adGroupResourceName, $keyword, $matchType);

            $matchTypeName = match ($matchType) {
                KeywordMatchType::EXACT => 'EXACT',
                KeywordMatchType::PHRASE => 'PHRASE',
                KeywordMatchType::BROAD => 'BROAD',
                default => 'UNKNOWN',
            };

            if ($resourceName) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => $matchTypeName,
                    'resource_name' => $resourceName,
                ];

                Log::info("SearchTermMiningAgent: Added keyword", [
                    'keyword' => $keyword,
                    'match_type' => $matchTypeName,
                    'ad_group' => $adGroupResourceName,
                ]);

                // Track in Keyword model for portfolio visibility
                try {
                    Keyword::updateOrCreate(
                        ['customer_id' => $customer->id, 'keyword_text' => $keyword, 'ad_group_resource_name' => $adGroupResourceName],
                        ['match_type' => $matchTypeName, 'status' => 'active', 'source' => 'mined', 'criterion_resource_name' => $resourceName, 'added_by_agent' => 'SearchTermMiningAgent']
                    );
                } catch (\Exception $trackingError) {
                    Log::debug('SearchTermMiningAgent: Could not track keyword in model', ['error' => $trackingError->getMessage()]);
                }
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
     * Add a search term as a keyword with the specified match type for Microsoft Ads.
     */
    protected function addAsMicrosoftKeyword(Customer $customer, string $adGroupId, string $keyword, string $matchType, array &$results): void
    {
        try {
            $service = new \App\Services\MicrosoftAds\AdGroupService($customer);
            $resourceName = $service->addKeyword($adGroupId, $keyword, $matchType);

            if ($resourceName) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => strtoupper($matchType),
                    'resource_name' => $resourceName,
                ];

                Log::info("SearchTermMiningAgent: Added keyword to Microsoft Ads", [
                    'keyword' => $keyword,
                    'match_type' => strtoupper($matchType),
                    'ad_group' => $adGroupId,
                ]);

                Keyword::updateOrCreate(
                    ['customer_id' => $customer->id, 'keyword_text' => $keyword, 'ad_group_resource_name' => $adGroupId],
                    ['match_type' => strtoupper($matchType), 'status' => 'active', 'source' => 'mined', 'criterion_resource_name' => $resourceName, 'added_by_agent' => 'SearchTermMiningAgent']
                );
            }
        } catch (\Exception $e) {
            Log::debug("SearchTermMiningAgent: Could not add Microsoft keyword (may already exist)", [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a negative keyword for Microsoft Ads.
     */
    protected function addAsMicrosoftNegative(Customer $customer, string $campaignId, string $keyword, string $reason, array &$results): void
    {
        try {
            $service = new \App\Services\MicrosoftAds\AdGroupService($customer);
            // using the campaign-level association
            $resourceName = $service->addNegativeKeyword($campaignId, $keyword, 'Exact', true);

            if ($resourceName) {
                $results['negatives_added'][] = [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'resource_name' => $resourceName,
                ];

                Log::info("SearchTermMiningAgent: Added Microsoft negative keyword", [
                    'keyword' => $keyword,
                    'reason' => $reason,
                ]);
            }
        } catch (\Exception $e) {
            Log::debug("SearchTermMiningAgent: Could not add Microsoft negative keyword (may already exist)", [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a negative keyword.
     */
    protected function addAsNegative(Customer $customer, string $customerId, string $campaignResourceName, string $keyword, string $reason, array &$results, string $matchTypeName = 'PHRASE'): void
    {
        $matchType = match (strtoupper($matchTypeName)) {
            'EXACT' => KeywordMatchType::EXACT,
            'BROAD' => KeywordMatchType::BROAD,
            default => KeywordMatchType::PHRASE,
        };

        try {
            $addNegative = new AddNegativeKeyword($customer, true);
            $resourceName = ($addNegative)($customerId, $campaignResourceName, $keyword, $matchType);

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
