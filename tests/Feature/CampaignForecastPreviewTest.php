<?php

namespace Tests\Feature;

use App\Services\Demo\CampaignForecastPreview;
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

    private function invokePrivate(string $method, array $args): mixed
    {
        $m = new \ReflectionMethod(CampaignForecastPreview::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(app(CampaignForecastPreview::class), $args);
    }

    public function test_no_mcc_configured_returns_null_rather_than_throwing(): void
    {
        // No MccAccount rows and no env fallback in the test environment.
        Cache::flush();

        $this->assertNull(app(CampaignForecastPreview::class)->forUrl('https://example.com'));
    }

    public function test_keywords_without_real_volume_are_dropped(): void
    {
        $picked = $this->invokePrivate('pick', [[
            $this->idea('real demand', 2400, 40, 1.00, 3.00),
            $this->idea('nobody searches this', 3, 10, 1.00, 2.00),
        ]]);

        $this->assertCount(1, $picked);
        $this->assertSame('real demand', $picked[0]['keyword']);
    }

    public function test_volume_is_weighed_against_competition(): void
    {
        // The cheaper term wins despite lower volume: log-scaled volume keeps a
        // single head term from drowning a set the platform could actually win.
        $picked = $this->invokePrivate('pick', [[
            $this->idea('brutally contested', 5000, 99, 8.00, 14.00),
            $this->idea('winnable', 1200, 8, 1.00, 2.00),
        ]]);

        $this->assertSame('winnable', $picked[0]['keyword']);
    }

    public function test_the_quoted_bid_sits_between_googles_low_and_high(): void
    {
        config(['demo.bid_aggressiveness' => 0.75]);

        $picked = $this->invokePrivate('pick', [[$this->idea('anything', 900, 50, 2.00, 6.00)]]);

        // 2.00 + (4.00 * 0.75)
        $this->assertEqualsWithDelta(5.00, $picked[0]['cpc'], 0.01);
    }

    public function test_one_expensive_keyword_does_not_set_the_bid_for_the_group(): void
    {
        $bid = $this->invokePrivate('bid', [[
            ['keyword' => 'cheap', 'monthly_searches' => 100, 'cpc' => 1.00],
            ['keyword' => 'cheap too', 'monthly_searches' => 100, 'cpc' => 1.00],
            ['keyword' => 'outlier', 'monthly_searches' => 100, 'cpc' => 40.00],
        ]]);

        $this->assertLessThan(40.0, $bid, 'A max() here would inflate the whole forecast off one term.');
        $this->assertEqualsWithDelta(14.0, $bid, 0.01);
    }

    public function test_a_keyword_with_no_bid_data_falls_back_rather_than_quoting_zero(): void
    {
        $picked = $this->invokePrivate('pick', [[
            array_merge($this->idea('no bids', 500, 30, 0.0, 0.0), ['average_cpc_micros' => 3_250_000]),
        ]]);

        $this->assertEqualsWithDelta(3.25, $picked[0]['cpc'], 0.01);
    }

    public function test_the_keyword_set_is_capped_to_the_configured_size(): void
    {
        config(['demo.keyword_count' => 3]);

        $ideas = [];
        for ($i = 0; $i < 20; $i++) {
            $ideas[] = $this->idea("keyword {$i}", 500 + $i, 40, 1.00, 2.00);
        }

        $this->assertCount(3, $this->invokePrivate('pick', [$ideas]));
    }
}
