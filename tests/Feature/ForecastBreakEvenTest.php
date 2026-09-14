<?php

namespace Tests\Feature;

use App\Services\Forecasting\CustomerMarketForecast;
use PHPUnit\Framework\TestCase;

/**
 * What would have to be true for the spend to pay.
 *
 * The panel showed "-A$689" and stopped, which is a number a customer can do
 * nothing with — and it sits directly above the button that confirms their
 * budget. These two figures say what the gap actually is: what a customer would
 * need to be worth at today's conversion rate, and the rate they would need at
 * today's customer value.
 *
 * Both are arithmetic on figures already on the panel, not new predictions.
 * And note what is deliberately not offered as a remedy: a smaller budget.
 * Conversions scale with clicks and clicks scale with spend, so halving the
 * budget halves the loss and leaves the return exactly where it was.
 */
class ForecastBreakEvenTest extends TestCase
{
    /** @return array<string, mixed> */
    private function withRevenue(array $forecast, float $aov): array
    {
        $customer = new \App\Models\Customer([
            'average_order_value' => $aov,
            'currency_code' => 'AUD',
            'country' => 'AU',
        ]);

        $m = new \ReflectionMethod(CustomerMarketForecast::class, 'withRevenue');

        return $m->invoke(app(CustomerMarketForecast::class), $forecast, $customer);
    }

    /** The real figures from yourfirststore, 14 September. */
    private const REAL = [
        'clicks' => 631,
        'conversions' => 19,
        'cost' => 1350,
        'conversion_rate' => 0.03,
    ];

    public function test_it_names_the_customer_value_that_would_break_even(): void
    {
        $out = $this->withRevenue(self::REAL, 35);

        // A$1,350 of spend over 19 conversions.
        $this->assertSame(71.05, $out['break_even_order_value']);
        $this->assertTrue($out['below_break_even']);
    }

    public function test_it_names_the_conversion_rate_that_would_break_even(): void
    {
        $out = $this->withRevenue(self::REAL, 35);

        // 631 clicks x A$35 must cover A$1,350: 6.1%, against today's 3.0%.
        $this->assertSame(0.0611, $out['break_even_conversion_rate']);
    }

    public function test_a_lifetime_value_turns_the_same_forecast_profitable(): void
    {
        /*
           The whole point of asking for lifetime value rather than one order.
           A A$35/mo product entered as one month returns 0.49x and loses A$689.
           The same customer over twelve months is A$420 — and nothing else
           about the campaign changes.
        */
        $monthly = $this->withRevenue(self::REAL, 35);
        $lifetime = $this->withRevenue(self::REAL, 420);

        $this->assertSame(0.49, $monthly['roas']);
        $this->assertLessThan(0, $monthly['net']);

        $this->assertSame(5.91, $lifetime['roas']);
        $this->assertGreaterThan(0, $lifetime['net']);
        $this->assertFalse($lifetime['below_break_even']);
    }

    public function test_no_order_value_means_no_targets_rather_than_zeroes(): void
    {
        $customer = new \App\Models\Customer(['average_order_value' => null, 'currency_code' => 'AUD', 'country' => 'AU']);
        $m = new \ReflectionMethod(CustomerMarketForecast::class, 'withRevenue');

        $out = $m->invoke(app(CustomerMarketForecast::class), self::REAL, $customer);

        // A zero break-even reads as "you already break even", which is worse
        // than saying nothing.
        $this->assertFalse($out['has_order_value']);
        $this->assertArrayNotHasKey('break_even_order_value', $out);
    }
}
