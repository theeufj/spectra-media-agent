<?php

namespace Tests\Feature;

use App\Services\Demo\CampaignForecastPreview;
use App\Services\Forecasting\KeywordForecastBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The public demo ends on Google's numbers, and never on an exception.
 *
 * A visitor typing their URL into the landing page is the top of the funnel.
 * Keyword Planner being slow, rate-limited or unconfigured must cost them the
 * forecast panel and nothing else — the ad copy and brand extraction still
 * return. Anything that throws here throws on the front page.
 *
 * The keyword scoring these assertions cover now lives in
 * KeywordForecastBuilder, shared with the signed-in path, so it is exercised
 * there. What remains specific to the demo — that it runs on demo config and
 * survives a missing MCC — is asserted against CampaignForecastPreview itself.
 */
class CampaignForecastPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function idea(string $keyword, int $volume, int $competition, float $low, float $high): array
    {
        return [
            'keyword' => $keyword,
            'avg_monthly_searches' => $volume,
            'competition_index' => $competition,
            'low_top_of_page_bid_micros' => (int) ($low * 1_000_000),
            'high_top_of_page_bid_micros' => (int) ($high * 1_000_000),
            'average_cpc_micros' => (int) ((($low + $high) / 2) * 1_000_000),
        ];
    }

    private function builder(float $aggressiveness = 0.75, int $keywordCount = 10): KeywordForecastBuilder
    {
        return new KeywordForecastBuilder(
            geoTarget: 'geoTargetConstants/2036',
            language: 'languageConstants/1000',
            keywordCount: $keywordCount,
            forecastDays: 30,
            conversionRate: 0.03,
            bidAggressiveness: $aggressiveness,
        );
    }

    public function test_no_mcc_configured_returns_null_rather_than_throwing(): void
    {
        // No MccAccount rows and no env fallback in the test environment.
        Cache::flush();

        $this->assertNull(app(CampaignForecastPreview::class)->forUrl('https://example.com'));
    }

    public function test_the_demo_path_runs_on_demo_configuration(): void
    {
        // Delegating to the shared builder must not quietly re-point the demo
        // at someone else's settings.
        config(['demo.geo_target' => 'geoTargetConstants/2036', 'demo.keyword_count' => 7]);

        $builder = KeywordForecastBuilder::fromDemoConfig();

        $read = fn (string $prop) => (new \ReflectionProperty(KeywordForecastBuilder::class, $prop))->getValue($builder);

        $this->assertSame('geoTargetConstants/2036', $read('geoTarget'));
        $this->assertSame(7, $read('keywordCount'));
    }

    public function test_keywords_without_real_volume_are_dropped(): void
    {
        $picked = $this->builder()->pick([
            $this->idea('real demand', 2400, 40, 1.00, 3.00),
            $this->idea('nobody searches this', 3, 10, 1.00, 2.00),
        ]);

        $this->assertCount(1, $picked);
        $this->assertSame('real demand', $picked[0]['keyword']);
    }

    public function test_volume_is_weighed_against_competition(): void
    {
        // The cheaper term wins despite lower volume: log-scaled volume keeps a
        // single head term from drowning a set the platform could actually win.
        $picked = $this->builder()->pick([
            $this->idea('brutally contested', 5000, 99, 8.00, 14.00),
            $this->idea('winnable', 1200, 8, 1.00, 2.00),
        ]);

        $this->assertSame('winnable', $picked[0]['keyword']);
    }

    public function test_the_quoted_bid_sits_between_googles_low_and_high(): void
    {
        $picked = $this->builder(aggressiveness: 0.75)->pick([$this->idea('anything', 900, 50, 2.00, 6.00)]);

        // 2.00 + (4.00 * 0.75)
        $this->assertEqualsWithDelta(5.00, $picked[0]['cpc'], 0.01);
    }

    public function test_one_expensive_keyword_does_not_set_the_bid_for_the_group(): void
    {
        $bid = $this->builder()->bid([
            ['keyword' => 'cheap', 'monthly_searches' => 100, 'cpc' => 1.00],
            ['keyword' => 'cheap too', 'monthly_searches' => 100, 'cpc' => 1.00],
            ['keyword' => 'outlier', 'monthly_searches' => 100, 'cpc' => 40.00],
        ]);

        // Median of [1.00, 1.00, 40.00]. A mean would give 14.00 and a max 40.00,
        // both of them bids nobody would place.
        $this->assertEqualsWithDelta(1.00, $bid, 0.01);
    }

    public function test_a_keyword_with_no_bid_data_falls_back_rather_than_quoting_zero(): void
    {
        $picked = $this->builder()->pick([
            array_merge($this->idea('no bids', 500, 30, 0.0, 0.0), ['average_cpc_micros' => 3_250_000]),
        ]);

        $this->assertEqualsWithDelta(3.25, $picked[0]['cpc'], 0.01);
    }

    public function test_the_keyword_set_is_capped_to_the_configured_size(): void
    {
        $ideas = [];
        for ($i = 0; $i < 20; $i++) {
            $ideas[] = $this->idea("keyword {$i}", 500 + $i, 40, 1.00, 2.00);
        }

        $this->assertCount(3, $this->builder(keywordCount: 3)->pick($ideas));
    }
}
