<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\Competition\CompetitivePlatformGateway;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\UpdateKeywordBid;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StrategyAwareOptimizationTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '123',
            'daily_budget' => 50, 'approved_daily_budget' => 50]);
    }

    private function state(string $strategy = 'TARGET_SPEND'): array
    {
        return ['source' => 'google_ads_api', 'observed_at' => now()->toIso8601String(),
            'bidding_strategy_type' => $strategy, 'keyword_bids_supported' => $strategy === 'MANUAL_CPC', 'ads' => [],
            'keywords' => [['resource' => 'customers/1234567890/adGroupCriteria/12~45',
                'cpc_bid_micros' => 0, 'ad_group_cpc_bid_micros' => 1000000, 'enabled' => true]]];
    }

    private function recommendation(string $type = 'BIDDING'): array
    {
        return ['type' => $type, 'sub_type' => 'keyword_cpc', 'direction' => 'increase',
            'keyword_resource' => 'customers/1234567890/adGroupCriteria/12~45',
            'criterion_resource_name' => 'customers/1234567890/adGroupCriteria/12~45',
            'suggested_value' => 1500000, 'confidence_score' => 0.90];
    }

    public function test_analysis_reads_bidding_truth_without_competitor_evidence_and_filters_ineffective_changes(): void
    {
        $campaign = $this->campaign();
        $gateway = $this->createMock(CompetitivePlatformGateway::class);
        $gateway->expects($this->once())->method('state')->with($campaign)->willReturn($this->state());
        $this->app->instance(CompetitivePlatformGateway::class, $gateway);
        $metrics = $this->createMock(MetricsFetcher::class);
        $metrics->method('fetchCurrent')->willReturn(['impressions' => 10000, 'clicks' => 500, 'conversions' => 20, 'cost_micros' => 1000000000]);
        $metrics->method('fetchHistorical')->willReturn(null);
        $metrics->method('platform')->willReturn('Google Ads');
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willReturnCallback(function ($model, $prompt) {
            $this->assertStringContainsString('"bidding_strategy_type": "TARGET_SPEND"', $prompt);
            $this->assertStringContainsString('A zero/unset keyword cpc_bid_micros is normal', $prompt);
            $this->assertStringContainsString('"source": "google_ads_api"', $prompt);

            return ['text' => json_encode(['recommendations' => [
                $this->recommendation(), $this->recommendation('KEYWORDS'),
                ['type' => 'CHANGE_BID_STRATEGY', 'sub_type' => 'strategy', 'suggested_strategy' => 'MAXIMIZE_CLICKS'],
                [...$this->recommendation('KEYWORDS'), 'direction' => 'pause'],
            ]])];
        });
        $agent = new CampaignOptimizationAgent($gemini, $metrics, new RecommendationScorer, app(RecommendationApplier::class));

        $result = $agent->analyze($campaign);

        $this->assertCount(1, $result['recommendations']);
        $this->assertSame('pause', $result['recommendations'][0]['direction']);
        $this->assertCount(3, $result['blocked_recommendations']);
        $this->assertSame('TARGET_SPEND', $result['google_configuration']['bidding_strategy_type']);
    }

    public function test_current_automated_strategy_blocks_keyword_cpc_even_after_owner_approval(): void
    {
        $gateway = $this->createMock(CompetitivePlatformGateway::class);
        $gateway->expects($this->exactly(2))->method('state')->willReturn($this->state());
        $this->app->instance(CompetitivePlatformGateway::class, $gateway);
        $writer = $this->createMock(UpdateKeywordBid::class);
        $writer->expects($this->never())->method('__invoke');
        $this->app->bind(UpdateKeywordBid::class, fn () => $writer);
        $campaign = $this->campaign();

        foreach (['BIDDING', 'KEYWORDS'] as $type) {
            $result = app(RecommendationApplier::class)->apply($campaign, $this->recommendation($type), true);
            $this->assertFalse($result['applied']);
            $this->assertStringContainsString('current strategy is TARGET_SPEND', $result['message']);
        }
    }

    public function test_valid_manual_bid_uses_the_same_confidence_threshold_as_the_scorer(): void
    {
        $gateway = $this->createMock(CompetitivePlatformGateway::class);
        $gateway->expects($this->once())->method('state')->willReturn($this->state('MANUAL_CPC'));
        $this->app->instance(CompetitivePlatformGateway::class, $gateway);
        $writer = $this->createMock(UpdateKeywordBid::class);
        $writer->expects($this->once())->method('__invoke')->with('1234567890', 'customers/1234567890/adGroupCriteria/12~45', 1500000)->willReturn(true);
        $this->app->bind(UpdateKeywordBid::class, fn () => $writer);

        $result = app(RecommendationApplier::class)->apply($this->campaign(), $this->recommendation());

        $this->assertTrue($result['applied']);
    }

    public function test_missing_configuration_and_a_foreign_campaign_keyword_never_mutate(): void
    {
        $gateway = $this->createMock(CompetitivePlatformGateway::class);
        $reads = 0;
        $gateway->expects($this->exactly(2))->method('state')->willReturnCallback(function () use (&$reads) {
            if ($reads++ === 0) {
                throw new \RuntimeException('Google unavailable');
            }

            return $this->state('MANUAL_CPC');
        });
        $this->app->instance(CompetitivePlatformGateway::class, $gateway);
        $writer = $this->createMock(UpdateKeywordBid::class);
        $writer->expects($this->never())->method('__invoke');
        $this->app->bind(UpdateKeywordBid::class, fn () => $writer);
        $campaign = $this->campaign();

        $result = app(RecommendationApplier::class)->apply($campaign, $this->recommendation(), true);
        $this->assertFalse($result['applied']);
        $this->assertStringContainsString('could not be verified', $result['message']);
        $result = app(RecommendationApplier::class)->apply($campaign, [...$this->recommendation(), 'keyword_resource' => 'customers/1234567890/adGroupCriteria/999~111'], true);
        $this->assertFalse($result['applied']);
        $this->assertStringContainsString('not found in this campaign', $result['message']);
    }

    public function test_bounded_trial_keeps_high_confidence_analysis_advisory(): void
    {
        $campaign = $this->campaign();
        $campaign->forceFill(['spend_guardrails' => ['enabled' => true, 'max_daily_budget_micros' => 10000000]])->save();
        $gateway = $this->createMock(CompetitivePlatformGateway::class);
        $gateway->expects($this->once())->method('state')->willReturn($this->state());
        $this->app->instance(CompetitivePlatformGateway::class, $gateway);
        $metrics = $this->createMock(MetricsFetcher::class);
        $metrics->method('fetchCurrent')->willReturn(['impressions' => 20000, 'clicks' => 1000, 'conversions' => 50]);
        $metrics->method('fetchHistorical')->willReturn(null);
        $metrics->method('platform')->willReturn('Google Ads');
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willReturn(['text' => json_encode(['recommendations' => [
            ['type' => 'NEGATIVE_KEYWORDS', 'keywords' => ['unrelated jobs'], 'impact' => 'HIGH'],
        ]])]);
        $agent = new CampaignOptimizationAgent($gemini, $metrics, new RecommendationScorer, app(RecommendationApplier::class));

        $result = $agent->analyze($campaign);

        $this->assertSame([], $result['categorized']['auto_apply']);
        $this->assertCount(1, $result['categorized']['recommended']);
        $this->assertFalse($result['recommendations'][0]['auto_apply_eligible']);
        $this->assertFalse($agent->applyRecommendation($campaign, $this->recommendation())['applied']);
    }
}
