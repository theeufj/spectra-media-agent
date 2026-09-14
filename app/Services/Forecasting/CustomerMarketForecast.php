<?php

namespace App\Services\Forecasting;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\GeoTargets;
use Illuminate\Support\Facades\Cache;

/**
 * What this customer's market is worth, at the budget they are being asked for.
 *
 * The funnel is scan → campaign → confirm budget → pay → deploy, and every real
 * signup so far has stopped at or before the budget step. That step asked a
 * customer to commit daily spend and told them nothing about what it buys: the
 * evidence existed, was already being pulled from Keyword Planner, and was
 * shown only to anonymous visitors on the landing page.
 *
 * This is that same forecast aimed at a customer we know things about — their
 * country, their currency, the budget on the campaign in front of them, and
 * what a customer is worth to them. The last of those is what turns clicks into
 * money, and it is the only figure here we cannot measure.
 */
class CustomerMarketForecast
{
    /**
     * Google's forecast for a customer, framed on one campaign's budget.
     *
     * Returns null when there is nothing to forecast (no website, no MCC, no
     * usable keywords) rather than throwing — the caller is rendering a page
     * the customer is already looking at.
     *
     * @return array<string, mixed>|null
     */
    public function for(Customer $customer, ?Campaign $campaign = null): ?array
    {
        return $this->atBudget($customer, $this->monthlyBudget($campaign));
    }

    /**
     * The same forecast at an arbitrary monthly budget.
     *
     * Separate entry point because the budget panel asks "what would *this*
     * number buy" while the customer is still typing it, and that must not
     * depend on a campaign having been saved first.
     *
     * @return array<string, mixed>|null
     */
    public function atBudget(Customer $customer, float $monthlyBudget): ?array
    {
        $raw = $this->raw($customer);

        if (! $raw) {
            return null;
        }

        return $this->withRevenue(
            ForecastFrame::apply($raw, $monthlyBudget),
            $customer
        );
    }

    /**
     * Google's unscaled answer for this customer's site, cached for a day.
     *
     * Keyed on the customer and their URL and nothing else. The budget and the
     * order value both change what the panel displays, but neither changes what
     * Keyword Planner would say, so putting either in the key would have burned
     * a pair of API calls every time someone nudged a number.
     *
     * @return array<string, mixed>|null
     */
    private function raw(Customer $customer): ?array
    {
        $url = $customer->website;

        if (! $url) {
            return null;
        }

        return Cache::remember(
            sprintf('market_forecast:%d:%s', $customer->id, md5($url)),
            now()->addHours(24),
            fn () => $this->builder($customer)->forUrl($url)
        );
    }

    private function builder(Customer $customer): KeywordForecastBuilder
    {
        return new KeywordForecastBuilder(
            // The one line that stops every customer's forecast being an
            // Australian one: config('demo.geo_target') is a fixed AU constant.
            geoTarget: GeoTargets::resourceNameForCountry($customer->country),
            language: (string) config('demo.language'),
            keywordCount: (int) config('demo.keyword_count'),
            forecastDays: (int) config('demo.forecast_days'),
            conversionRate: (float) config('demo.conversion_rate'),
            bidAggressiveness: (float) config('demo.bid_aggressiveness'),
        );
    }

    /**
     * A campaign's own daily budget when there is one, because the question the
     * panel answers is "what does *this* number buy". The demo default is the
     * fallback for a customer who has no campaign yet.
     */
    private function monthlyBudget(?Campaign $campaign): float
    {
        $daily = $campaign === null ? 0.0 : (float) ($campaign->daily_budget ?? 0);

        return $daily > 0 ? $daily * 30 : (float) config('demo.monthly_budget');
    }

    /**
     * Add the money figures, and be explicit about what is missing.
     *
     * When there is no order value the revenue keys are absent rather than
     * zero. A revenue line reading "$0.00" reads as a broken page rather than a
     * missing input, and the panel has something specific to ask for instead.
     *
     * Not defaulted for a second reason: the same figure drives
     * BudgetIntelligenceAgent's reallocation once set, so a guess here would
     * not stay on the page.
     *
     * @param  array<string, mixed>  $forecast
     * @return array<string, mixed>
     */
    private function withRevenue(array $forecast, Customer $customer): array
    {
        $aov = $customer->average_order_value;
        $orderValue = $aov !== null && (float) $aov > 0 ? (float) $aov : null;

        $forecast['currency'] = $customer->currency_code ?: 'USD';
        $forecast['country_known'] = GeoTargets::knows($customer->country);
        $forecast['has_order_value'] = $orderValue !== null;

        if ($orderValue === null) {
            return $forecast;
        }

        $conversions = (float) ($forecast['conversions'] ?? 0);
        $cost = (float) ($forecast['cost'] ?? 0);
        $revenue = $conversions * $orderValue;

        $forecast['order_value'] = round($orderValue, 2);
        $forecast['revenue'] = round($revenue, 2);
        $forecast['net'] = round($revenue - $cost, 2);
        $forecast['roas'] = $cost > 0 ? round($revenue / $cost, 2) : null;

        /*
         * What would have to be true for this to pay.
         *
         * A forecast that says "-A$689" and stops is a number a customer can
         * do nothing with. These two say what the gap actually is: the amount
         * a customer would need to be worth at today's conversion rate, and
         * the rate they would need at today's customer value. Both are
         * arithmetic on figures already on the panel, not new predictions.
         *
         * Note what is NOT offered as a remedy: a smaller budget. Conversions
         * scale with clicks and clicks scale with spend, so halving the budget
         * halves the loss and leaves the return exactly where it was. Saying
         * otherwise would be advice that cannot work.
         */
        $clicks = (float) ($forecast['clicks'] ?? 0);
        $rate = (float) ($forecast['conversion_rate'] ?? 0);

        // Per-customer value that makes revenue meet spend.
        $forecast['break_even_order_value'] = $conversions > 0
            ? round($cost / $conversions, 2)
            : null;

        // Conversion rate that makes revenue meet spend at today's value.
        $forecast['break_even_conversion_rate'] = ($clicks > 0 && $orderValue > 0)
            ? round($cost / ($clicks * $orderValue), 4)
            : null;

        // Only worth showing when it is actually out of reach today.
        $forecast['below_break_even'] = $revenue < $cost;
        $forecast['conversion_rate'] = $rate;

        return $forecast;
    }
}
