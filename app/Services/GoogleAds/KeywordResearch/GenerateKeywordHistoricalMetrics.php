<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordHistoricalMetricsRequest;

/** Historical estimates for the selected keywords, under the campaign's actual targeting. */
class GenerateKeywordHistoricalMetrics extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, array $keywords, array $geoTargets = [], ?string $language = null): array
    {
        $this->ensureClient();
        $texts = array_values(array_unique(array_filter(array_map('trim', $keywords))));
        if ($texts === []) {
            return ['success' => true, 'keywords' => []];
        }

        $request = new GenerateKeywordHistoricalMetricsRequest([
            'customer_id' => $customerId,
            'keywords' => array_slice($texts, 0, 100),
            'geo_target_constants' => $geoTargets,
            'keyword_plan_network' => KeywordPlanNetwork::GOOGLE_SEARCH,
        ]);
        if ($language) {
            $request->setLanguage($language);
        }

        // Exceptions reach the watchdog. Missing estimates remain unknown, never zero demand.
        $response = $this->client->getKeywordPlanIdeaServiceClient()->generateKeywordHistoricalMetrics($request);
        $rows = [];
        foreach ($response->getResults() as $result) {
            $metrics = $result->getKeywordMetrics();
            $entry = [
                'text' => $result->getText(),
                'avg_monthly_searches' => $metrics?->hasAvgMonthlySearches() ? $metrics->getAvgMonthlySearches() : null,
                'low_top_of_page_bid_micros' => $metrics?->hasLowTopOfPageBidMicros() ? $metrics->getLowTopOfPageBidMicros() : null,
                'high_top_of_page_bid_micros' => $metrics?->hasHighTopOfPageBidMicros() ? $metrics->getHighTopOfPageBidMicros() : null,
            ];
            foreach ([$result->getText(), ...iterator_to_array($result->getCloseVariants())] as $text) {
                $rows[mb_strtolower(trim($text))] = $entry;
            }
        }

        return ['success' => true, 'keywords' => $rows, 'checked_at' => now()->toIso8601String(),
            'note' => 'Historical search demand and top-of-page bids are estimates, not minimum bids or guaranteed traffic.'];
    }
}
