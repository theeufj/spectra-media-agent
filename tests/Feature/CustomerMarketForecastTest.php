<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Forecasting\CustomerMarketForecast;
use App\Services\Forecasting\ForecastFrame;
use App\Services\Forecasting\KeywordForecastBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What a customer's market is worth, at the budget they are being asked for.
 *
 * The budget step is where every real signup has stopped: it asks for daily
 * spend and says nothing about what the spend buys. These assertions cover the
 * arithmetic that turns Google's forecast into that answer, and the two places
 * it would be quietly wrong — a revenue figure invented from an order value
 * nobody gave us, and a forecast run against the wrong country.
 *
 * Google's half is faked by seeding the cache with an unscaled forecast, which
 * is exactly what KeywordForecastBuilder returns. No Keyword Planner call is
 * made, and none should be: the base TestCase prevents stray HTTP.
 */
class CustomerMarketForecastTest extends TestCase
{
    use DatabaseTransactions;

    private const SITE = 'https://example.com';

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'website' => self::SITE,
            'country' => 'US',
            'currency_code' => 'USD',
        ], $attributes));
    }

    /**
     * Google's unscaled answer, as the builder would return it.
     *
     * @return array<string, mixed>
     */
    private function rawForecast(float $cost = 1000.0, float $conversions = 30.0): array
    {
        return [
            'keywords' => [['keyword' => 'emergency plumber', 'monthly_searches' => 2400, 'cpc' => 4.10]],
            'max_cpc' => 4.10,
            'days' => 30,
            'conversion_rate' => 0.03,
            'impressions' => 40000,
            'clicks' => 1000,
            'cost' => $cost,
            'conversions' => $conversions,
            'average_cpc' => 1.0,
            'ctr' => 0.025,
        ];
    }

    private function seedForecast(Customer $customer, array $raw): void
    {
        Cache::put(
            sprintf('market_forecast:%d:%s', $customer->id, md5(self::SITE)),
            $raw,
            now()->addHour()
        );
    }

    public function test_revenue_is_conversions_times_what_a_customer_is_worth(): void
    {
        $customer = $this->customer(['average_order_value' => 180.00]);
        $this->seedForecast($customer, $this->rawForecast(cost: 1000.0, conversions: 30.0));

        // Budget above the forecast cost, so nothing is scaled down.
        $out = app(CustomerMarketForecast::class)->atBudget($customer, 5000.0);

        $this->assertSame(5400.0, $out['revenue']);   // 30 * 180
        $this->assertSame(4400.0, $out['net']);       // 5400 - 1000
        $this->assertSame(5.4, $out['roas']);         // 5400 / 1000
        $this->assertTrue($out['has_order_value']);
    }

    public function test_without_an_order_value_the_money_keys_are_absent_not_zero(): void
    {
        // A revenue line reading "$0.00" reads as a broken page rather than a
        // missing input, and inventing an order value would print our
        // assumption as their forecast.
        $customer = $this->customer(['average_order_value' => null]);
        $this->seedForecast($customer, $this->rawForecast());

        $out = app(CustomerMarketForecast::class)->atBudget($customer, 5000.0);

        $this->assertFalse($out['has_order_value']);
        $this->assertArrayNotHasKey('revenue', $out);
        $this->assertArrayNotHasKey('roas', $out);
        $this->assertArrayNotHasKey('net', $out);

        // Everything Google measured is still there.
        $this->assertSame(1000, $out['clicks']);
        $this->assertSame(30.0, $out['conversions']);
    }

    public function test_an_order_value_of_zero_counts_as_not_provided(): void
    {
        $customer = $this->customer(['average_order_value' => 0]);
        $this->seedForecast($customer, $this->rawForecast());

        $this->assertFalse(app(CustomerMarketForecast::class)->atBudget($customer, 5000.0)['has_order_value']);
    }

    public function test_the_budget_comes_from_the_campaign_in_front_of_them(): void
    {
        $customer = $this->customer(['average_order_value' => 100.00]);
        $this->seedForecast($customer, $this->rawForecast(cost: 9000.0, conversions: 90.0));

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'daily_budget' => 45.00,
        ]);

        $out = app(CustomerMarketForecast::class)->for($customer, $campaign);

        // 45/day is 1350 a month, and Google's unconstrained forecast wants
        // 9000 — so everything scales by 0.15 and says it was scaled.
        $this->assertSame(1350.0, $out['budget']);
        $this->assertTrue($out['budget_capped']);
        $this->assertSame(1350.0, $out['cost']);
        $this->assertSame(13.5, $out['conversions']);
        $this->assertSame(1350.0, $out['revenue']);   // 13.5 * 100
    }

    public function test_the_unscaled_totals_travel_with_the_framed_ones(): void
    {
        // So the browser can re-frame as the customer edits the budget without
        // spending another pair of Keyword Planner calls.
        $customer = $this->customer();
        $this->seedForecast($customer, $this->rawForecast(cost: 9000.0, conversions: 90.0));

        $out = app(CustomerMarketForecast::class)->atBudget($customer, 900.0);

        $this->assertSame(9000.0, $out['unconstrained']['cost']);
        $this->assertSame(90.0, $out['unconstrained']['conversions']);
        $this->assertSame(900.0, $out['cost']);
    }

    public function test_a_budget_larger_than_the_market_is_not_inflated(): void
    {
        $customer = $this->customer();
        $this->seedForecast($customer, $this->rawForecast(cost: 1000.0, conversions: 30.0));

        $out = app(CustomerMarketForecast::class)->atBudget($customer, 50000.0);

        // Google says the demand is worth $1000; a bigger budget does not buy
        // clicks that do not exist.
        $this->assertFalse($out['budget_capped']);
        $this->assertSame(1000.0, $out['cost']);
        $this->assertSame(30.0, $out['conversions']);
    }

    public function test_the_forecast_runs_against_the_customers_own_country(): void
    {
        // config('demo.geo_target') is a fixed Australian constant. A US
        // customer forecast against Australian volume and Australian bids
        // produces numbers that are internally consistent and entirely wrong.
        $method = new \ReflectionMethod(CustomerMarketForecast::class, 'builder');

        foreach (['US' => 2840, 'GB' => 2826, 'AU' => 2036, 'NZ' => 2554] as $country => $id) {
            $builder = $method->invoke(app(CustomerMarketForecast::class), $this->customer(['country' => $country]));

            $this->assertSame(
                "geoTargetConstants/{$id}",
                (new \ReflectionProperty(KeywordForecastBuilder::class, 'geoTarget'))->getValue($builder),
                "A {$country} customer must be forecast against {$country}."
            );
        }
    }

    public function test_a_customer_with_no_website_returns_null_rather_than_forecasting_nothing(): void
    {
        $this->assertNull(
            app(CustomerMarketForecast::class)->atBudget($this->customer(['website' => null]), 1500.0)
        );
    }

    public function test_the_currency_shown_is_the_customers_own(): void
    {
        $customer = $this->customer(['currency_code' => 'AUD']);
        $this->seedForecast($customer, $this->rawForecast());

        $this->assertSame('AUD', app(CustomerMarketForecast::class)->atBudget($customer, 5000.0)['currency']);
    }

    public function test_framing_a_zero_cost_forecast_does_not_divide_by_zero(): void
    {
        $framed = ForecastFrame::apply($this->rawForecast(cost: 0.0, conversions: 0.0), 1500.0);

        $this->assertFalse($framed['budget_capped']);
        $this->assertSame(0.0, $framed['cost']);
    }

    public function test_roas_is_null_rather_than_infinite_when_nothing_is_spent(): void
    {
        $customer = $this->customer(['average_order_value' => 100.00]);
        $this->seedForecast($customer, $this->rawForecast(cost: 0.0, conversions: 0.0));

        $this->assertNull(app(CustomerMarketForecast::class)->atBudget($customer, 1500.0)['roas']);
    }
}
