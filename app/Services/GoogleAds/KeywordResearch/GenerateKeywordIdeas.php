<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordIdeasRequest;
use Google\Ads\GoogleAds\V22\Services\KeywordSeed;
use Google\Ads\GoogleAds\V22\Services\KeywordAndUrlSeed;
use Google\Ads\GoogleAds\V22\Services\UrlSeed;
use Google\Ads\GoogleAds\V22\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\ApiCore\ApiException;
use App\Models\Customer;

class GenerateKeywordIdeas extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Generate keyword ideas from seed keywords and/or a URL.
     *
     * @param string $customerId Google Ads customer ID
     * @param array $seedKeywords Array of seed keyword strings (1-20)
     * @param string|null $url Optional URL to extract keyword ideas from
     * @param string|null $language Language resource name (e.g. 'languageConstants/1000' for English)
     * @param array $geoTargets Geo target constant resource names (e.g. ['geoTargetConstants/2036' for AU])
     * @param int $pageSize Max results to return (default 50)
     * @return array Array of keyword ideas with metrics
     */
    public function __invoke(
        string $customerId,
        array $seedKeywords = [],
        ?string $url = null,
        ?string $language = null,
        array $geoTargets = [],
        int $pageSize = 50
    ): array {
        $this->ensureClient();

        $request = new GenerateKeywordIdeasRequest([
            'customer_id' => $customerId,
            'keyword_plan_network' => KeywordPlanNetwork::GOOGLE_SEARCH,
            'page_size' => $pageSize,
        ]);

        if ($language) {
            $request->setLanguage($language);
        }

        if (!empty($geoTargets)) {
            $request->setGeoTargetConstants($geoTargets);
        }

        // Set seed type based on inputs
        if (!empty($seedKeywords) && $url) {
            $request->setKeywordAndUrlSeed(new KeywordAndUrlSeed([
                'keywords' => array_slice($seedKeywords, 0, 20),
                'url' => $url,
            ]));
        } elseif (!empty($seedKeywords)) {
            $request->setKeywordSeed(new KeywordSeed([
                'keywords' => array_slice($seedKeywords, 0, 20),
            ]));
        } elseif ($url) {
            $request->setUrlSeed(new UrlSeed([
                'url' => $url,
            ]));
        } else {
            $this->logError('GenerateKeywordIdeas: No seed keywords or URL provided');
            return [];
        }

        try {
            $keywordPlanIdeaService = $this->client->getKeywordPlanIdeaServiceClient();
            $response = $keywordPlanIdeaService->generateKeywordIdeas($request);

            $results = [];
            foreach ($response->iterateAllElements() as $result) {
                $metrics = $result->getKeywordIdeaMetrics();

                $idea = [
                    'keyword' => $result->getText(),
                    'avg_monthly_searches' => $metrics ? $metrics->getAvgMonthlySearches() : null,
                    'competition' => $metrics ? $metrics->getCompetition() : null,
                    'competition_index' => $metrics ? $metrics->getCompetitionIndex() : null,
                    'low_top_of_page_bid_micros' => $metrics ? $metrics->getLowTopOfPageBidMicros() : null,
                    'high_top_of_page_bid_micros' => $metrics ? $metrics->getHighTopOfPageBidMicros() : null,
                    'average_cpc_micros' => $metrics ? $metrics->getAverageCpcMicros() : null,
                ];

                $results[] = $idea;

                if (count($results) >= $pageSize) {
                    break;
                }
            }

            $this->logInfo("GenerateKeywordIdeas: Got " . count($results) . " ideas for customer $customerId");
            return $results;

        } catch (ApiException $e) {
            $this->logError("GenerateKeywordIdeas failed for customer $customerId: " . $e->getMessage());
            return [];
        }
    }
}
