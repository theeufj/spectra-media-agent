<?php

namespace Tests\Feature;

use App\Models\MccAccount;
use App\Services\Forecasting\ForecastFrame;
use App\Services\Forecasting\KeywordForecastBuilder;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ForecastV26MetricsTest extends TestCase
{
    use DatabaseTransactions;

    private function builder(array $metrics): KeywordForecastBuilder
    {
        MccAccount::query()->update(['is_active' => false]);
        MccAccount::create(['name' => 'Forecast test', 'google_customer_id' => '123-456-7890',
            'refresh_token' => 'unused-offline-fixture', 'is_active' => true]);
        $ideas = $this->createMock(GenerateKeywordIdeas::class);
        $ideas->expects($this->once())->method('__invoke')
            ->with('1234567890', [], 'https://example.com', 'languageConstants/1000', ['geoTargetConstants/2840'], 50)
            ->willReturn([['keyword' => 'managed ads', 'avg_monthly_searches' => 1000, 'competition_index' => 30,
                'low_top_of_page_bid_micros' => 2_000_000, 'high_top_of_page_bid_micros' => 4_000_000]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->once())->method('__invoke')
            ->with('1234567890', ['managed ads'], 3.0, 0.03, 30,
                ['geo_target_constants' => ['geoTargetConstants/2840'], 'language_constants' => ['languageConstants/1000']])
            ->willReturn($metrics + ['success' => true]);
        $this->app->bind(GenerateKeywordIdeas::class, fn () => $ideas);
        $this->app->bind(GenerateKeywordForecast::class, fn () => $forecast);

        return new KeywordForecastBuilder('geoTargetConstants/2840', 'languageConstants/1000', 10, 30, 0.03, 0.5);
    }

    public function test_v26_missing_impressions_and_ctr_remain_unknown_in_both_budget_frames(): void
    {
        $raw = $this->builder(['impressions' => null, 'ctr' => null, 'clicks' => 1000.0, 'cost' => 1000.0,
            'average_cpc' => 1.0])->forUrl('https://example.com');

        $this->assertNotNull($raw);
        $this->assertNull($raw['impressions']);
        $this->assertNull($raw['ctr']);
        $this->assertSame(30.0, $raw['conversions'], 'Conversions use the disclosed assumption, not an absent Google CPC conversion metric.');
        foreach ([[500.0, 500], [2000.0, 1000]] as [$budget, $clicks]) {
            $framed = ForecastFrame::apply($raw, $budget);
            $this->assertNotNull($framed);
            $this->assertNull($framed['impressions']);
            $this->assertNull($framed['ctr']);
            $this->assertNull($framed['unconstrained']['impressions']);
            $this->assertSame($clicks, $framed['clicks']);
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unavailableMetrics(): array
    {
        return ['missing clicks' => [['clicks' => null, 'cost' => 1000.0]],
            'missing cost' => [['clicks' => 1000.0, 'cost' => null]],
            'nonfinite cost' => [['clicks' => 1000.0, 'cost' => INF]],
            'negative clicks' => [['clicks' => -1.0, 'cost' => 1000.0]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableMetrics')]
    public function test_missing_or_invalid_click_or_cost_evidence_suppresses_the_panel(array $metrics): void
    {
        $this->assertNull($this->builder($metrics)->forUrl('https://example.com'));
        $this->assertNull(ForecastFrame::apply($metrics + ['conversions' => 30.0], 500.0));
    }

    public function test_explicit_zero_is_preserved_while_an_unavailable_cpc_stays_unknown(): void
    {
        $raw = $this->builder(['impressions' => 0, 'ctr' => 0.0, 'clicks' => 0.0, 'cost' => 0.0,
            'average_cpc' => null])->forUrl('https://example.com');
        $this->assertNotNull($raw);
        $framed = ForecastFrame::apply($raw, 500.0);
        $this->assertNotNull($framed);

        $this->assertSame(0, $framed['impressions']);
        $this->assertSame(0.0, $framed['ctr']);
        $this->assertSame(0, $framed['clicks']);
        $this->assertSame(0.0, $framed['cost']);
        $this->assertNull($framed['average_cpc']);
    }
}
