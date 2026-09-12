<?php

namespace App\Services\Demo;

use App\Services\Forecasting\ForecastFrame;
use App\Services\Forecasting\KeywordForecastBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * What a visitor's own market actually costs, before they pay us anything.
 *
 * The public demo ended on a plan: ad copy and brand colours. A plan is a
 * claim, and a visitor has no way to check it. This ends the demo on Google's
 * numbers instead — real monthly search volume, real top-of-page bids, and
 * Google's own forecast of what that keyword set delivers over a month.
 *
 * The pipeline itself now lives in KeywordForecastBuilder, because a signed-up
 * customer deserves the same evidence against their own country and their own
 * budget rather than the demo's fixed ones. This class is what remains that is
 * genuinely specific to the anonymous funnel: demo configuration, and a cache
 * keyed on nothing but the URL, since there is no visitor identity to key on.
 *
 * @see \App\Services\Forecasting\CustomerMarketForecast for the signed-in path
 */
class CampaignForecastPreview
{
    public function forUrl(string $url): ?array
    {
        // The cache holds Google's unscaled answer. Framing is arithmetic and
        // happens after it, so changing the demo budget does not invalidate a
        // day's worth of Keyword Planner calls.
        $raw = Cache::remember(
            'demo_forecast:'.md5($url),
            now()->addHours(24),
            fn () => KeywordForecastBuilder::fromDemoConfig()->forUrl($url)
        );

        return $raw ? ForecastFrame::apply($raw, (float) config('demo.monthly_budget')) : null;
    }
}
