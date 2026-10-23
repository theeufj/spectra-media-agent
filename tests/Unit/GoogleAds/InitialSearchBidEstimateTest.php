<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\InitialSearchBidPlanner;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class InitialSearchBidEstimateTest extends TestCase
{
    public function test_real_account_research_does_not_need_fabricated_campaign_resources_or_an_approved_budget(): void
    {
        $campaign = $this->campaign();
        $context = $this->context();
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->with('1234567890', ['real business offer'], ['geoTargetConstants/2036'], null)
            ->willReturn(['success' => true, 'keywords' => ['real business offer' => ['low_top_of_page_bid_micros' => 1_250_000]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->once())->method('__invoke')->willReturnCallback(function ($id, $keywords, $maxCpc, $rate, $days, $context) {
            $this->assertSame('1234567890', $id);
            $this->assertSame(1_250_000, $context['cpc_bid_ceiling_micros']);
            $this->assertSame(5_000_000, $context['daily_budget_micros']);
            $this->assertArrayNotHasKey('ad_group_resource', $keywords[0]);
            $this->assertArrayNotHasKey('campaign_resource', $context);

            return ['success' => true, 'clicks' => 40, 'cost_micros' => 20_000_000, 'period_days' => $days,
                'context' => $context + ['keywords' => $keywords], 'caveats' => [],
                'forecast_period' => ['from' => $context['forecast_start_date'],
                    'through' => CarbonImmutable::parse($context['forecast_start_date'])->addDays($days - 1)->toDateString()]];
        });

        $result = $this->planner($historical, $forecast)->estimate($campaign, $this->keywords(), $context, 5_000_000);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertTrue($result['evidence']['proposal_only']);
        $this->assertFalse($result['activation_ready']);
        $this->assertNull($campaign->approved_daily_budget);
        $this->assertNull($campaign->budget_confirmed_at);
        $this->assertNull($campaign->google_ads_campaign_id);
    }

    public function test_wrong_account_currency_timezone_or_unknown_keywords_cannot_produce_a_proposal(): void
    {
        foreach ([['customer_id' => '9999999999'], ['currency_code' => 'USD'], ['account_time_zone_verified' => false],
            ['time_zone' => 'Invalid/Timezone'], ['geo_target_constants' => []]] as $override) {
            $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
            $historical->expects($this->never())->method('__invoke');
            $forecast = $this->createMock(GenerateKeywordForecast::class);
            $forecast->expects($this->never())->method('__invoke');
            $result = $this->planner($historical, $forecast)->estimate($this->campaign(), $this->keywords(), array_replace($this->context(), $override), 5_000_000);

            $this->assertFalse($result['ready']);
        }
    }

    public function test_preapproval_search_is_bounded_to_four_measured_bid_candidates_and_does_not_fall_back(): void
    {
        $keywords = $metrics = [];
        for ($index = 1; $index <= 12; $index++) {
            $keywords[] = ['text' => 'selected keyword '.$index, 'match_type' => 'PHRASE'];
            $metrics['selected keyword '.$index] = ['low_top_of_page_bid_micros' => $index * 100_000];
        }
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->willReturn(['success' => true, 'keywords' => $metrics]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->exactly(4))->method('__invoke')->willReturn(['success' => true, 'clicks' => 0, 'cost_micros' => 0]);

        $result = $this->planner($historical, $forecast)->estimate($this->campaign(), $keywords, $this->context(), 5_000_000, 1_000_000);

        $this->assertFalse($result['ready']);
        $this->assertCount(4, $result['evidence']['candidates']);
        $this->assertLessThanOrEqual(1_000_000, max(array_column($result['evidence']['candidates'], 'cpc_bid_micros')));
        $this->assertArrayNotHasKey('cpc_bid_micros', $result);
    }

    public function test_a_selected_keyword_limit_caps_the_uniform_forecast_even_when_the_strategy_limit_is_higher(): void
    {
        $keywords = [[...$this->keywords()[0], 'cpc_bid_micros' => 250_000]];
        $historical = $this->createMock(GenerateKeywordHistoricalMetrics::class);
        $historical->expects($this->once())->method('__invoke')->willReturn(['success' => true,
            'keywords' => ['real business offer' => ['low_top_of_page_bid_micros' => 1_250_000]]]);
        $forecast = $this->createMock(GenerateKeywordForecast::class);
        $forecast->expects($this->once())->method('__invoke')->willReturnCallback(function ($id, $keywords, $maxCpc, $rate, $days, $context) {
            $this->assertSame(250_000, $context['cpc_bid_ceiling_micros']);
            $this->assertSame(250_000, $keywords[0]['cpc_bid_micros']);

            return ['success' => true, 'clicks' => 40, 'cost_micros' => 5_000_000, 'period_days' => $days,
                'context' => $context + ['keywords' => $keywords], 'caveats' => [],
                'forecast_period' => ['from' => $context['forecast_start_date'],
                    'through' => CarbonImmutable::parse($context['forecast_start_date'])->addDays($days - 1)->toDateString()]];
        });

        $result = $this->planner($historical, $forecast)->estimate($this->campaign(), $keywords, $this->context(), 5_000_000, 1_000_000);

        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertSame(250_000, $result['cpc_bid_micros']);
        $this->assertSame(250_000, $result['evidence']['max_cpc_bid_micros']);
        $this->assertSame(250_000, $result['evidence']['keywords'][0]['cpc_bid_micros']);
    }

    private function campaign(): Campaign
    {
        $customer = new Customer(['currency_code' => 'AUD']);
        $customer->setRawAttributes([...$customer->getAttributes(), 'google_ads_customer_id' => '1234567890']);

        return (new Campaign(['daily_budget' => '5.00', 'status' => 'draft']))->setRelation('customer', $customer);
    }

    private function keywords(): array
    {
        return [['text' => 'real business offer', 'match_type' => 'PHRASE']];
    }

    private function context(): array
    {
        return ['customer_id' => '1234567890', 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'account_time_zone_verified' => true, 'geo_target_constants' => ['geoTargetConstants/2036'],
            'language_constants' => [], 'negative_keywords' => [], 'excluded_geo_target_constants' => [],
            'ad_schedule' => [], 'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'search_partners_enabled' => false];
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
