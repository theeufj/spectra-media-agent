<?php

namespace App\Services\Demo;

use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What a visitor's own market actually costs, before they pay us anything.
 *
 * The public demo ended on a plan: ad copy and brand colours. A plan is a
 * claim, and a visitor has no way to check it. This ends the demo on Google's
 * numbers instead — real monthly search volume, real top-of-page bids, and
 * Google's own forecast of what that keyword set delivers over a month.
 *
 * Everything here except the conversion rate is measured by Google rather than
 * asserted by us, which is the whole point: it is evidence about the visitor's
 * business, not a testimonial about ours.
 *
 * Runs against the platform MCC. BaseGoogleAdsService authenticates from
 * MccAccount::getActive() and uses the Customer only to label its logs, so no
 * visitor account is needed — Keyword Planner returns market data, not account
 * data, and forecasts create nothing.
 */
class CampaignForecastPreview
{
    /** Ideas below this monthly volume are noise, not a market. */
    private const MIN_MONTHLY_SEARCHES = 10;

    /**
     * Google's forecast for the keywords behind a URL.
     *
     * Returns null rather than throwing: the visitor is waiting on a demo, and
     * a slow or unavailable Keyword Planner must cost them the forecast, not
     * the whole result.
     *
     * @return array{
     *     keywords: list<array{keyword: string, monthly_searches: int, cpc: float}>,
     *     max_cpc: float,
     *     days: int,
     *     conversion_rate: float,
     *     impressions: int, clicks: int, cost: float, conversions: float,
     *     average_cpc: float, ctr: float
     * }|null
     */
    public function forUrl(string $url): ?array
    {
        return Cache::remember(
            'demo_forecast:'.md5($url),
            now()->addHours(24),
            fn () => $this->build($url)
        );
    }

    private function build(string $url): ?array
    {
        $mcc = MccAccount::getActive();

        if (! $mcc) {
            Log::warning('CampaignForecastPreview: no active MCC, skipping forecast', ['url' => $url]);

            return null;
        }

        $mccId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        try {
            $ideas = (new GenerateKeywordIdeas($this->houseCustomer()))(
                $mccId,
                [],                              // no seed terms — the URL is the seed
                $url,
                config('demo.language'),
                [config('demo.geo_target')],
                config('demo.keyword_count') * 5 // over-fetch, then score down
            );

            $chosen = $this->pick($ideas);

            if ($chosen === []) {
                Log::info('CampaignForecastPreview: no usable keyword ideas', ['url' => $url]);

                return null;
            }

            $maxCpc = $this->bid($chosen);

            $forecast = (new GenerateKeywordForecast($this->houseCustomer()))(
                $mccId,
                array_column($chosen, 'keyword'),
                $maxCpc,
                config('demo.conversion_rate'),
                config('demo.forecast_days'),
            );

            if (! $forecast['success']) {
                Log::info('CampaignForecastPreview: forecast unavailable', [
                    'url' => $url,
                    'error' => $forecast['error'] ?? 'unknown',
                ]);

                return null;
            }

            return [
                'keywords' => $chosen,
                'max_cpc' => round($maxCpc, 2),
                'days' => (int) config('demo.forecast_days'),
                'conversion_rate' => (float) config('demo.conversion_rate'),
                'impressions' => (int) round($forecast['impressions'] ?? 0),
                'clicks' => (int) round($forecast['clicks'] ?? 0),
                'cost' => round((float) ($forecast['cost'] ?? 0), 2),
                'conversions' => round((float) ($forecast['conversions'] ?? 0), 1),
                'average_cpc' => round((float) ($forecast['average_cpc'] ?? 0), 2),
                'ctr' => round((float) ($forecast['ctr'] ?? 0), 4),
            ];
        } catch (\Throwable $e) {
            report($e);
            Log::error('CampaignForecastPreview failed: '.$e->getMessage(), ['url' => $url]);

            return null;
        }
    }

    /**
     * The keywords worth forecasting: real volume, cheapest competition first.
     *
     * Same trade-off the real campaign builder makes — volume is worth having
     * and competition is worth avoiding — so the visitor is shown the keyword
     * set the platform would actually build for them.
     *
     * @param  list<array<string, mixed>>  $ideas
     * @return list<array{keyword: string, monthly_searches: int, cpc: float}>
     */
    private function pick(array $ideas): array
    {
        // A keyword Google quotes no bid for has no commercial demand behind
        // it, and rendering "$0.00" next to it reads as a broken page rather
        // than a cheap opportunity.
        $usable = array_filter(
            $ideas,
            fn ($i) => ($i['avg_monthly_searches'] ?? 0) >= self::MIN_MONTHLY_SEARCHES
                && $this->topOfPageBid($i) > 0.0
        );

        usort($usable, function ($a, $b) {
            return $this->score($b) <=> $this->score($a);
        });

        return array_map(fn ($i) => [
            'keyword' => (string) $i['keyword'],
            'monthly_searches' => (int) $i['avg_monthly_searches'],
            'cpc' => round($this->topOfPageBid($i), 2),
        ], array_slice($usable, 0, (int) config('demo.keyword_count')));
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

        $aggressiveness = (float) config('demo.bid_aggressiveness');

        return $low + (($high - $low) * $aggressiveness);
    }

    /**
     * One bid for the whole forecast ad group, since Google forecasts per bid.
     *
     * @param  list<array{keyword: string, monthly_searches: int, cpc: float}>  $chosen
     */
    private function bid(array $chosen): float
    {
        $bids = array_values(array_filter(array_column($chosen, 'cpc'), fn ($c) => $c > 0));

        if ($bids === []) {
            return 2.0;
        }

        // Mean, not max: one expensive outlier should not set the bid for the
        // whole group and inflate the forecast cost.
        return array_sum($bids) / count($bids);
    }

    /**
     * A Customer the Google Ads services can be constructed with.
     *
     * Not persisted and never queried. BaseGoogleAdsService takes a Customer
     * but authenticates from the MCC, reading the model only to name the
     * account in its log lines.
     */
    private function houseCustomer(): Customer
    {
        $customer = new Customer(['name' => 'Public demo forecast']);
        $customer->id = 0;

        return $customer;
    }
}
