<?php

namespace App\Services\Forecasting;

use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use Illuminate\Support\Facades\Log;

/**
 * Google's own numbers for the market behind a URL.
 *
 * This is the pipeline that used to sit inside CampaignForecastPreview, where
 * it could only ever answer one question: what does an anonymous visitor's
 * market look like, in Australia, at a fixed demo budget. The same work answers
 * a far more valuable question for a customer who has signed up — what does
 * *their* market look like, in *their* country, at the budget they are being
 * asked to approve — so it is parameterised rather than reading demo config
 * directly, and both callers pass their own settings in.
 *
 * Everything it returns except the conversion rate is measured by Google rather
 * than asserted by us. That is the whole point: it is evidence about the
 * customer's business, not a claim about ours.
 *
 * Runs against the platform MCC. BaseGoogleAdsService authenticates from
 * MccAccount::getActive() and uses the Customer only to label its logs, so no
 * customer ad account is needed — Keyword Planner returns market data, not
 * account data, and forecasting creates nothing.
 */
class KeywordForecastBuilder
{
    /** Ideas below this monthly volume are noise, not a market. */
    private const MIN_MONTHLY_SEARCHES = 10;

    public function __construct(
        private readonly string $geoTarget,
        private readonly string $language,
        private readonly int $keywordCount,
        private readonly int $forecastDays,
        private readonly float $conversionRate,
        private readonly float $bidAggressiveness,
    ) {}

    /**
     * Build from the demo configuration — the anonymous public funnel.
     */
    public static function fromDemoConfig(): self
    {
        return new self(
            geoTarget: (string) config('demo.geo_target'),
            language: (string) config('demo.language'),
            keywordCount: (int) config('demo.keyword_count'),
            forecastDays: (int) config('demo.forecast_days'),
            conversionRate: (float) config('demo.conversion_rate'),
            bidAggressiveness: (float) config('demo.bid_aggressiveness'),
        );
    }

    /**
     * Google's forecast for the keywords behind a URL.
     *
     * Returns null rather than throwing. Every caller is rendering a page the
     * user is already looking at, and a slow or unavailable Keyword Planner
     * must cost them this panel rather than the whole screen.
     *
     * @return array{
     *     keywords: list<array{keyword: string, monthly_searches: int, cpc: float}>,
     *     max_cpc: float, days: int, conversion_rate: float,
     *     impressions: int, clicks: int, cost: float, conversions: float,
     *     average_cpc: float, ctr: float
     * }|null
     */
    public function forUrl(string $url): ?array
    {
        $mcc = MccAccount::getActive();

        if (! $mcc) {
            Log::warning('KeywordForecastBuilder: no active MCC, skipping forecast', ['url' => $url]);

            return null;
        }

        $mccId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        try {
            $ideas = (new GenerateKeywordIdeas($this->houseCustomer()))(
                $mccId,
                [],                        // no seed terms — the URL is the seed
                $url,
                $this->language,
                [$this->geoTarget],
                $this->keywordCount * 5    // over-fetch, then score down
            );

            $chosen = $this->pick($ideas);

            if ($chosen === []) {
                Log::info('KeywordForecastBuilder: no usable keyword ideas', ['url' => $url]);

                return null;
            }

            $maxCpc = $this->bid($chosen);

            $forecast = (new GenerateKeywordForecast($this->houseCustomer()))(
                $mccId,
                array_column($chosen, 'keyword'),
                $maxCpc,
                $this->conversionRate,
                $this->forecastDays,
            );

            if (! $forecast['success']) {
                Log::info('KeywordForecastBuilder: forecast unavailable', [
                    'url' => $url,
                    'error' => $forecast['error'] ?? 'unknown',
                ]);

                return null;
            }

            return [
                'keywords' => $chosen,
                'max_cpc' => round($maxCpc, 2),
                'days' => $this->forecastDays,
                'conversion_rate' => $this->conversionRate,
                // Unscaled: ForecastFrame applies a budget after the cache, so
                // changing the budget costs arithmetic rather than API quota.
                'impressions' => (int) round((float) ($forecast['impressions'] ?? 0)),
                'clicks' => (int) round((float) ($forecast['clicks'] ?? 0)),
                'cost' => round((float) ($forecast['cost'] ?? 0), 2),
                'conversions' => round((float) ($forecast['conversions'] ?? 0), 1),
                // Per-click figures are ratios and do not scale with budget.
                'average_cpc' => round((float) ($forecast['average_cpc'] ?? 0), 2),
                'ctr' => round((float) ($forecast['ctr'] ?? 0), 4),
            ];
        } catch (\Throwable $e) {
            report($e);
            Log::error('KeywordForecastBuilder failed: '.$e->getMessage(), ['url' => $url]);

            return null;
        }
    }

    /**
     * The keywords worth forecasting: real volume, cheapest competition first.
     *
     * Same trade-off the real campaign builder makes — volume is worth having
     * and competition is worth avoiding — so the customer is shown the keyword
     * set the platform would actually build for them.
     *
     * @param  list<array<string, mixed>>  $ideas
     * @return list<array{keyword: string, monthly_searches: int, cpc: float}>
     */
    public function pick(array $ideas): array
    {
        // A keyword Google quotes no bid for has no commercial demand behind
        // it, and rendering "$0.00" next to it reads as a broken page rather
        // than a cheap opportunity.
        $usable = array_filter(
            $ideas,
            fn ($i) => ($i['avg_monthly_searches'] ?? 0) >= self::MIN_MONTHLY_SEARCHES
                && $this->topOfPageBid($i) > 0.0
        );

        usort($usable, fn ($a, $b) => $this->score($b) <=> $this->score($a));

        return array_map(fn ($i) => [
            'keyword' => (string) $i['keyword'],
            'monthly_searches' => (int) $i['avg_monthly_searches'],
            'cpc' => round($this->topOfPageBid($i), 2),
        ], array_slice($usable, 0, $this->keywordCount));
    }

    /** @param array<string, mixed> $idea */
    private function score(array $idea): float
    {
        $volume = (int) ($idea['avg_monthly_searches'] ?? 0);
        $competition = (int) ($idea['competition_index'] ?? 50);

        // Volume leads, competition breaks ties. Weighted evenly, a 10/month
        // term with no competition outscored a 140/month one, and the panel
        // filled with keywords nobody searches — technically the easiest to
        // win, and worthless as evidence of a market. Log scale still keeps a
        // single head term from drowning the set.
        return log10(max($volume, 1)) * 25 + (100 - $competition) * 0.5;
    }

    /**
     * What Google says it costs to reach the top of the page for this keyword.
     *
     * Interpolated between the low and high top-of-page bids so the number
     * reflects a real competitive position rather than the cheapest possible
     * placement, which forecasts volume nobody would actually receive.
     *
     * @param  array<string, mixed>  $idea
     */
    private function topOfPageBid(array $idea): float
    {
        $low = ((int) ($idea['low_top_of_page_bid_micros'] ?? 0)) / 1_000_000;
        $high = ((int) ($idea['high_top_of_page_bid_micros'] ?? 0)) / 1_000_000;

        if ($high <= 0.0) {
            return $low > 0.0 ? $low : ((int) ($idea['average_cpc_micros'] ?? 0)) / 1_000_000;
        }

        return $low + (($high - $low) * $this->bidAggressiveness);
    }

    /**
     * One bid for the whole forecast ad group, since Google forecasts per bid.
     *
     * Median, not mean: sitetospend.com returned a keyword quoted at $968 a
     * click beside others near $75, and the mean set the whole group's bid to
     * $156 — a bid nobody would place, forecasting spend nobody would make.
     *
     * @param  list<array{keyword: string, monthly_searches: int, cpc: float}>  $chosen
     */
    public function bid(array $chosen): float
    {
        $bids = array_values(array_filter(array_column($chosen, 'cpc'), fn ($c) => $c > 0));

        if ($bids === []) {
            return 2.0;
        }

        sort($bids);
        $mid = intdiv(count($bids), 2);

        return count($bids) % 2 === 1
            ? $bids[$mid]
            : ($bids[$mid - 1] + $bids[$mid]) / 2;
    }

    /**
     * Keyword Planner needs a Customer only to label its logs — the call
     * authenticates as the MCC and returns market data, not account data.
     */
    private function houseCustomer(): Customer
    {
        $customer = new Customer(['name' => 'Keyword forecast']);
        $customer->id = 0;

        return $customer;
    }
}
