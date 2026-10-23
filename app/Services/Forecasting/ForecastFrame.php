<?php

namespace App\Services\Forecasting;

/**
 * Google's forecast, framed against a budget someone would actually set.
 *
 * The forecast Keyword Planner returns is unconstrained — it answers "what is
 * all of the demand at this bid", which for a competitive market is a
 * six-figure monthly spend. sitetospend.com's own forecast came back at
 * $911,000. That is true, and useless: no customer is going to spend it, and
 * the number reads as a bug rather than an opportunity.
 *
 * Scaling keeps every underlying figure Google's — the bid, the volumes, the
 * effective cost per click — and makes the headline one the customer can act
 * on. The panel says which it is showing.
 *
 * This is deliberately separate from KeywordForecastBuilder and applied
 * *after* the cache. Framing is pure arithmetic on numbers we already hold, so
 * a customer dragging the budget field re-frames for free; folding it into the
 * builder would have made every budget keystroke a fresh pair of Keyword
 * Planner calls, and the cache key would have changed on each one.
 */
class ForecastFrame
{
    /**
     * @param  array<string, mixed>  $raw  an unscaled forecast from KeywordForecastBuilder
     * @return array<string, mixed>|null
     */
    public static function apply(array $raw, float $monthlyBudget): ?array
    {
        $cost = self::number($raw['cost'] ?? null);
        $clicks = self::number($raw['clicks'] ?? null);
        $conversions = self::number($raw['conversions'] ?? null);
        if ($cost === null || $clicks === null || $conversions === null) {
            return null;
        }
        $impressions = self::number($raw['impressions'] ?? null);
        $scaled = $monthlyBudget > 0 && $cost > $monthlyBudget;
        $factor = $scaled ? $monthlyBudget / $cost : 1.0;

        return array_merge($raw, [
            'budget' => round($monthlyBudget, 2),
            'budget_capped' => $scaled,
            'impressions' => $impressions === null ? null : (int) round($impressions * $factor),
            'clicks' => (int) round($clicks * $factor),
            'cost' => round($cost * $factor, 2),
            'conversions' => round($conversions * $factor, 1),
            'ctr' => self::number($raw['ctr'] ?? null),

            /*
             * The unscaled totals travel with the framed ones so the browser
             * can re-frame without asking us again. Keyword Planner quota is
             * the scarce resource here, not arithmetic.
             */
            'unconstrained' => [
                'impressions' => $impressions === null ? null : (int) $impressions,
                'clicks' => (int) $clicks,
                'cost' => round($cost, 2),
                'conversions' => round($conversions, 1),
            ],
        ]);
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0
            ? (float) $value : null;
    }
}
