<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\InitialSearchBidPlanner;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use Carbon\Carbon;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Tests\TestCase;

class InitialSearchBidPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23 01:00:00', 'UTC'));
    }

    public function test_it_uses_fresh_cost_and_complete_targeted_reach_to_choose_the_lowest_credible_bid(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['approved_cpc_ceiling_micros'] = null;
        $snapshot['negative_keywords'] = [['scope' => 'campaign', 'text' => 'free', 'match_type' => 'BROAD']];
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->with('1234567890', ['paid search agency', 'advertising agency'], ['geoTargetConstants/2036'], 'languageConstants/1000')
            ->willReturn($this->historical());
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $bids = [];
        $forecast->expects($this->exactly(2))->method('__invoke')->willReturnCallback(function ($id, $keywords, $maxCpc, $rate, $days, $context) use (&$bids, $snapshot) {
            $this->assertSame('1234567890', $id);
            $this->assertSame(['PHRASE', 'EXACT'], array_column($keywords, 'match_type'));
            $this->assertSame($snapshot['negative_keywords'], $context['negative_keywords']);
            $this->assertSame(['geoTargetConstants/2036'], $context['geo_target_constants']);
            $this->assertSame('AUD', $context['currency_code']);
            $this->assertSame('Australia/Sydney', $context['time_zone']);
            $this->assertSame(5_000_000, $context['daily_budget_micros']);
            $this->assertSame(30, $days);
            $this->assertNull($rate);
            $bids[] = $context['cpc_bid_ceiling_micros'];

            return $this->forecast($keywords, $context, $days, count($bids) === 1 ? 4 : 42,
                ['Google’s forecast no longer supports negative keywords.']);
        });

        $result = $this->planner($historical, $forecast)->plan($this->campaign(), $snapshot);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertSame([500_000, 1_000_000], $bids);
        $this->assertSame(1_000_000, $result['cpc_bid_micros']);
        $this->assertFalse($result['activation_ready']);
        $this->assertFalse($result['auto_repair_safe']);
        $this->assertStringContainsString('negative keywords', implode(' ', $result['caveats']));
        $this->assertSame($snapshot, $result['evidence']['snapshot']);
        $this->assertSame(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), $result['evidence']['input_fingerprint']);
    }

    public function test_it_never_uses_the_inherited_one_cent_bid_as_a_research_cap_or_fallback(): void
    {
        $result = $this->withEstimates()->plan($this->campaign(), $this->snapshot());

        $this->assertTrue($result['ready']);
        $this->assertSame(500_000, $result['cpc_bid_micros']);
        $this->assertGreaterThan(10_000, $result['cpc_bid_micros']);
    }

    public function test_budget_comparison_forecasts_the_exact_selected_bid_instead_of_choosing_a_cheaper_candidate(): void
    {
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->willReturn($this->historical());
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->once())->method('__invoke')->willReturnCallback(function ($id, $keywords, $maxCpc, $rate, $days, $context) {
            $this->assertSame(1_000_000, $context['cpc_bid_ceiling_micros']);
            $this->assertSame(10_000_000, $context['daily_budget_micros']);
            $this->assertSame([1_000_000], array_values(array_unique(array_column($keywords, 'cpc_bid_micros'))));

            return $this->forecast($keywords, $context, $days, 98);
        });
        $context = [...$this->snapshot(), 'customer_id' => '1234567890'];
        $context['keywords'] = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => 0]), $context['keywords']);
        $result = $this->planner($historical, $forecast)->forecastAtBid($this->campaign(), $context['keywords'], $context, 10_000_000, 1_000_000, 2_000_000);
        $this->assertTrue($result['ready']);
        $this->assertSame(1_000_000, $result['cpc_bid_micros']);
        $this->assertCount(1, $result['evidence']['candidates']);
    }

    public function test_exact_bid_options_cannot_raise_keyword_or_strategy_limits_or_round_up_a_bid(): void
    {
        foreach (['keyword', 'strategy', 'fractional_cent'] as $limit) {
            $context = [...$this->snapshot(), 'customer_id' => '1234567890'];
            $keywords = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => 0]), $context['keywords']);
            if ($limit === 'keyword') {
                $keywords[0]['cpc_bid_micros'] = 500_000;
            }
            $result = $this->neverEstimate()->forecastAtBid($this->campaign(), $keywords, $context, 10_000_000,
                $limit === 'fractional_cent' ? 1_000_001 : 1_000_000, $limit === 'strategy' ? 500_000 : 2_000_000);
            $this->assertFalse($result['ready']);
            $this->assertSame([], $result['evidence']['candidates']);
        }
    }

    public function test_unknown_and_zero_costs_require_review_without_calling_forecast(): void
    {
        foreach ([[], ['paid search agency' => ['low_top_of_page_bid_micros' => 0, 'high_top_of_page_bid_micros' => null]]] as $metrics) {
            $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
            $historical->expects($this->once())->method('__invoke')->willReturn(['success' => true, 'keywords' => $metrics]);
            $forecast = $this->createMock(GenerateKeywordForecast::class);
            $forecast->expects($this->never())->method('__invoke');
            $result = $this->planner($historical, $forecast)->plan($this->campaign(), $this->snapshot());

            $this->assertFalse($result['ready']);
            $this->assertArrayNotHasKey('cpc_bid_micros', $result);
            $this->assertSame([], $result['evidence']['candidates']);
        }
    }

    public function test_zero_unknown_or_insufficient_forecast_reach_never_chooses_a_bid(): void
    {
        foreach ([['clicks' => 0, 'cost_micros' => 0], ['clicks' => null, 'cost_micros' => 500_000],
            ['clicks' => 40, 'cost_micros' => null], ['clicks' => 40, 'cost_micros' => 0],
            ['clicks' => 2, 'cost_micros' => 500_000], ['success' => false]] as $override) {
            $result = $this->withEstimates($override)->plan($this->campaign(), $this->snapshot());

            $this->assertFalse($result['ready'], json_encode($override));
            $this->assertArrayNotHasKey('cpc_bid_micros', $result);
            $this->assertNotEmpty($result['evidence']['candidates']);
        }
    }

    public function test_candidates_and_forecast_budget_never_exceed_daily_allocation_or_existing_explicit_limits(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['authorized_daily_budget_micros'] = 400_000;
        $snapshot['approved_cpc_ceiling_micros'] = 300_001;
        $snapshot['keywords'][1]['keyword_cpc_bid_micros'] = 250_001;
        $result = $this->withEstimates()->plan($this->campaign(), $snapshot);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertSame(250_000, $result['cpc_bid_micros']);
        $this->assertSame(400_000, $result['evidence']['forecast']['context']['daily_budget_micros']);
        $this->assertSame(250_000, $result['evidence']['max_cpc_bid_micros']);
    }

    public function test_a_forecast_above_the_verified_budget_envelope_is_not_credible(): void
    {
        $result = $this->withEstimates(['cost_micros' => 150_000_001])->plan($this->campaign(), $this->snapshot());

        $this->assertFalse($result['ready']);
    }

    public function test_forecast_evidence_for_different_targeting_keywords_dates_or_currency_is_not_accepted(): void
    {
        foreach (['targeting', 'keywords', 'dates', 'currency'] as $mismatch) {
            $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
            $historical->expects($this->once())->method('__invoke')->willReturn($this->historical());
            $forecast = $this->createMock(GenerateKeywordForecast::class);
            $forecast->method('__invoke')->willReturnCallback(function ($id, $keywords, $maxCpc, $rate, $days, $context) use ($mismatch) {
                $result = $this->forecast($keywords, $context, $days);
                match ($mismatch) {
                    'targeting' => $result['context']['geo_target_constants'] = ['geoTargetConstants/2840'],
                    'keywords' => $result['context']['keywords'] = array_slice($result['context']['keywords'], 0, 1),
                    'dates' => $result['forecast_period']['from'] = '2026-10-22',
                    'currency' => $result['context']['currency_code'] = 'USD',
                };

                return $result;
            });

            $result = $this->planner($historical, $forecast)->plan($this->campaign(), $this->snapshot());

            $this->assertFalse($result['ready'], $mismatch);
            $this->assertArrayNotHasKey('cpc_bid_micros', $result);
        }
    }

    public function test_remote_enabled_unknown_account_context_unconfirmed_or_unauthorized_budgets_are_not_estimated(): void
    {
        foreach ([['status' => CampaignStatus::ENABLED], ['status' => CampaignStatus::UNKNOWN],
            ['campaign_resource' => 'customers/9999999999/campaigns/123'], ['currency_code' => 'USD'],
            ['account_time_zone_verified' => false], ['time_zone' => 'Not/AZone'],
            ['authorized_daily_budget_micros' => 6_000_000], ['authorized_daily_budget_micros' => 0],
            ['daily_budget_micros' => 0], ['approved_cpc_ceiling_micros' => 0]] as $override) {
            $result = $this->neverEstimate()->plan($this->campaign(), array_replace($this->snapshot(), $override));
            $this->assertFalse($result['ready'], json_encode($override));
        }
        $campaign = $this->campaign();
        $campaign->budget_confirmed_at = null;
        $this->assertFalse($this->neverEstimate()->plan($campaign, $this->snapshot())['ready']);
    }

    public function test_spending_holds_and_controlled_trials_are_preserved_without_provider_calls(): void
    {
        foreach ([['spend_safety_hold' => true], ['spend_guardrails' => ['enabled' => true, 'max_cpc_bid_micros' => 500_000]]] as $attributes) {
            $campaign = $this->campaign();
            $campaign->forceFill($attributes);
            $before = $campaign->getAttributes();

            $this->assertFalse($this->neverEstimate()->plan($campaign, $this->snapshot())['ready']);
            $this->assertSame($before, $campaign->getAttributes());
        }
    }

    public function test_it_uses_account_local_future_days_and_both_approved_and_actual_serving_dates(): void
    {
        $campaign = $this->campaign();
        $campaign->forceFill(['start_date' => '2026-10-26', 'end_date' => '2026-11-05']);
        $snapshot = $this->snapshot();
        $snapshot['start_date_time'] = '2026-10-27 12:30:00';
        $snapshot['end_date_time'] = '2026-11-03 17:00:00';
        $result = $this->withEstimates()->plan($campaign, $snapshot);

        $this->assertTrue($result['ready']);
        $forecast = $result['evidence']['forecast'];
        $this->assertSame(6, $forecast['period_days']);
        $this->assertSame(['from' => '2026-10-28', 'through' => '2026-11-02'], $forecast['forecast_period']);
    }

    public function test_an_expired_serving_window_does_not_call_cost_or_reach_providers(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['end_date_time'] = '2026-10-23 23:59:59';

        $this->assertFalse($this->neverEstimate()->plan($this->campaign(), $snapshot)['ready']);
    }

    public function test_large_cost_pools_use_at_most_eight_candidates_and_no_synthetic_fallback(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['keywords'] = [];
        $metrics = [];
        for ($index = 1; $index <= 20; $index++) {
            $snapshot['keywords'][] = $this->snapshot()['keywords'][0];
            $snapshot['keywords'][$index - 1]['text'] = 'selected keyword '.$index;
            $metrics['selected keyword '.$index] = ['low_top_of_page_bid_micros' => $index * 100_000];
        }
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->willReturn(['success' => true, 'keywords' => $metrics]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->exactly(8))->method('__invoke')->willReturn(['success' => false]);
        $result = $this->planner($historical, $forecast)->plan($this->campaign(), $snapshot);

        $this->assertFalse($result['ready']);
        $this->assertCount(8, $result['evidence']['candidates']);
        $this->assertSame(100_000, $result['evidence']['candidates'][0]['cpc_bid_micros']);
        $this->assertSame(2_000_000, $result['evidence']['candidates'][7]['cpc_bid_micros']);
    }

    private function campaign(): Campaign
    {
        return (new Campaign(['approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()]))
            ->setRelation('customer', new Customer(['google_ads_customer_id' => '123-456-7890', 'currency_code' => 'AUD']));
    }

    private function snapshot(): array
    {
        return ['campaign_resource' => 'customers/1234567890/campaigns/123', 'strategy_id' => 456,
            'status' => CampaignStatus::PAUSED, 'channel' => AdvertisingChannelType::SEARCH,
            'bidding_strategy' => BiddingStrategyType::MANUAL_CPC, 'account_time_zone_verified' => true,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'daily_budget_micros' => 5_000_000,
            'authorized_daily_budget_micros' => 5_000_000, 'geo_target_constants' => ['geoTargetConstants/2036'],
            'excluded_geo_target_constants' => [], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'ad_schedule' => [], 'device_bid_modifiers' => [],
            'unsupported_targeting' => [], 'search_partners_enabled' => false,
            'cpc_bid_ceiling_micros' => 10_000, 'actual_cpc_ceiling_micros' => 10_000,
            'keywords' => [
                ['text' => 'paid search agency', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'ad_group_status' => 'ENABLED',
                    'ad_group_resource' => 'customers/1234567890/adGroups/234', 'cpc_bid_micros' => 10_000, 'keyword_cpc_bid_micros' => 0],
                ['text' => 'advertising agency', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'ad_group_status' => 'ENABLED',
                    'ad_group_resource' => 'customers/1234567890/adGroups/234', 'cpc_bid_micros' => 10_000, 'keyword_cpc_bid_micros' => 0],
            ]];
    }

    private function historical(): array
    {
        return ['success' => true, 'checked_at' => now()->toIso8601String(), 'keywords' => [
            'paid search agency' => ['low_top_of_page_bid_micros' => 500_000, 'high_top_of_page_bid_micros' => 2_000_000],
            'advertising agency' => ['low_top_of_page_bid_micros' => 1_000_000, 'high_top_of_page_bid_micros' => 3_000_000],
        ]];
    }

    private function forecast(array $keywords, array $context, int $days, int $clicks = 40, array $caveats = []): array
    {
        // Match the existing provider's normalized keyword shape and key order.
        $keywords = array_map(fn ($keyword) => ['text' => $keyword['text'], 'match_type' => $keyword['match_type'],
            'ad_group_resource' => $keyword['ad_group_resource'], 'cpc_bid_micros' => $keyword['cpc_bid_micros']], $keywords);

        return ['success' => true, 'clicks' => $clicks, 'cost_micros' => 2_000_000, 'period_days' => $days,
            'forecast_period' => ['from' => $context['forecast_start_date'],
                'through' => Carbon::parse($context['forecast_start_date'])->addDays($days - 1)->toDateString()],
            'context' => $context + ['keywords' => $keywords], 'caveats' => $caveats, 'auto_repair_safe' => $caveats === []];
    }

    private function withEstimates(array $forecastOverride = []): InitialSearchBidPlanner
    {
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->willReturn($this->historical());
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->method('__invoke')->willReturnCallback(fn ($id, $keywords, $maxCpc, $rate, $days, $context) => array_replace($this->forecast($keywords, $context, $days), $forecastOverride));

        return $this->planner($historical, $forecast);
    }

    private function neverEstimate(): InitialSearchBidPlanner
    {
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->never())->method('__invoke');
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->never())->method('__invoke');

        return $this->planner($historical, $forecast);
    }

    private function planner(GenerateKeywordHistoricalMetrics $historical, GenerateKeywordForecast $forecast): InitialSearchBidPlanner
    {
        return new class($historical, $forecast) extends InitialSearchBidPlanner
        {
            public function __construct(private GenerateKeywordHistoricalMetrics $historical, private GenerateKeywordForecast $forecast) {}

            protected function historicalProvider(Customer $customer): GenerateKeywordHistoricalMetrics
            {
                return $this->historical;
            }

            protected function forecastProvider(Customer $customer): GenerateKeywordForecast
            {
                return $this->forecast;
            }
        };
    }
}
