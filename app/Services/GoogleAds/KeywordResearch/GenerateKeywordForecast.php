<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V22\Services\BiddableKeyword;
use Google\Ads\GoogleAds\V22\Services\CampaignToForecast;
use Google\Ads\GoogleAds\V22\Services\CampaignToForecast\CampaignBiddingStrategy;
use Google\Ads\GoogleAds\V22\Services\ForecastAdGroup;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V22\Services\ManualCpcBiddingStrategy;

/**
 * Asks Google what a keyword set would actually deliver before it is launched.
 *
 * Step 2 of Google's own keyword-planning workflow, and the one this platform
 * skipped. GenerateKeywordIdeas already supplies historical volume, competition
 * and top-of-page bid ranges, and SearchKeywordBuilder filters on them — but
 * nothing ever asked the question that decides whether a campaign is viable:
 * at this budget and this bid, how many clicks and conversions is this?
 *
 * That question is worth asking before spending. A budget too small to win the
 * auctions for its own keywords produces exactly what happened on this account:
 * spend without conversions, and Smart Bidding left with no signal to learn
 * from.
 *
 * Read-only. Forecasts create nothing in the account.
 */
class GenerateKeywordForecast extends BaseGoogleAdsService
{
    /**
     * Forecast a keyword set at a given max CPC.
     *
     * @param  list<string>  $keywords
     * @param  float  $maxCpc  Max CPC bid in account currency
     * @return array{
     *     success: bool,
     *     impressions?: float, clicks?: float, cost?: float, conversions?: float,
     *     average_cpc?: float, ctr?: float, error?: string
     * }
     */
    public function __invoke(string $customerId, array $keywords, float $maxCpc, ?float $conversionRate = null): array
    {
        $this->ensureClient();

        $keywords = array_values(array_filter(array_unique(array_map('trim', $keywords))));

        if ($keywords === []) {
            return ['success' => false, 'error' => 'No keywords supplied to forecast'];
        }

        try {
            $biddable = array_map(fn ($text) => new BiddableKeyword([
                'keyword' => new KeywordInfo([
                    'text' => $text,
                    // Forecast on the match type the campaign will actually use.
                    // Broad would overstate reach against a phrase-match build.
                    'match_type' => KeywordMatchType::PHRASE,
                ]),
            ]), $keywords);

            $adGroup = new ForecastAdGroup([
                'max_cpc_bid_micros' => (int) round($maxCpc * 1_000_000),
                'biddable_keywords' => $biddable,
            ]);

            $campaign = new CampaignToForecast([
                'keyword_plan_network' => KeywordPlanNetwork::GOOGLE_SEARCH,
                // Manual CPC deliberately: the forecast is being used to judge
                // whether a given bid can buy meaningful volume, so the bid has
                // to be the input rather than something Google chooses.
                'bidding_strategy' => new CampaignBiddingStrategy([
                    'manual_cpc_bidding_strategy' => new ManualCpcBiddingStrategy([
                        'max_cpc_bid_micros' => (int) round($maxCpc * 1_000_000),
                    ]),
                ]),
                'ad_groups' => [$adGroup],
            ]);

            // Google only returns conversion figures when given a rate to apply;
            // without one the response carries impressions, clicks and cost only.
            if ($conversionRate !== null) {
                $campaign->setConversionRate($conversionRate);
            }

            $response = $this->client->getKeywordPlanIdeaServiceClient()
                ->generateKeywordForecastMetrics(new GenerateKeywordForecastMetricsRequest([
                    'customer_id' => $customerId,
                    'campaign' => $campaign,
                ]));

            $m = $response->getCampaignForecastMetrics();

            return [
                'success' => true,
                'impressions' => $m->getImpressions(),
                'clicks' => $m->getClicks(),
                'cost' => $m->getCostMicros() / 1_000_000,
                'conversions' => $m->getConversions(),
                'average_cpc' => $m->getAverageCpcMicros() / 1_000_000,
                'ctr' => $m->getClickThroughRate(),
            ];
        } catch (\Throwable $e) {
            $this->logError('GenerateKeywordForecast failed for customer '.$customerId.': '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
