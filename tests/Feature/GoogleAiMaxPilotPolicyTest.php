<?php

namespace Tests\Feature;

use App\Jobs\ReviewGoogleAdsRecommendations;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchCostProtection;
use App\Services\Agents\GoogleSearchDeliveryRecovery;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Agents\GoogleSearchReachRecovery;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\ApplyRecommendation;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use Google\Ads\GoogleAds\V26\Enums\RecommendationTypeEnum\RecommendationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoogleAiMaxPilotPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private function pilot(array $changes = []): array
    {
        return $changes + ['enabled' => true, 'approved_at' => now()->subMinute()->toIso8601String(),
            'source' => 'owner_approved_ai_max', 'approval' => 'Owner approved the dedicated setup purchase pilot.',
            'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(14)->toIso8601String(),
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney',
            'max_daily_budget_micros' => 50_000_000, 'baseline_cost_micros' => 200_000_000,
            'review_spend_micros' => 200_000_000, 'permits_conversion_bidding' => true,
            'acknowledges_no_cpc_cap' => true,
            'selected_conversion_action' => 'customers/1234567890/conversionActions/444'];
    }

    private function campaign(?array $pilot = null): Campaign
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD', 'service_type' => 'managed']);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => '999', 'start_date' => now()->startOfDay(), 'end_date' => now()->addDays(14),
            'approved_daily_budget' => '50.00', 'daily_budget' => '50.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => ['automation_limits' => ['enabled' => true, 'currency_code' => 'AUD',
                'approved_at' => now()->subMinute()->toIso8601String(), 'source' => 'owner_approved_cost_repair',
                'max_daily_budget_micros' => 50_000_000, 'max_cpc_bid_micros' => 10_000_000],
                ...($pilot === null ? [] : ['ai_max_pilot' => $pilot])]]);
    }

    public function test_native_pilot_requires_explicit_policy_and_keeps_legacy_limits_intact(): void
    {
        $campaign = $this->campaign();
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CONVERSIONS', null));
        $this->assertTrue(CampaignSpendGuardrails::permitsKeywordBid($campaign, 10_000_000));
        $old = $campaign->spend_guardrails['automation_limits'];
        $campaign->update(['spend_guardrails' => [...$campaign->spend_guardrails, 'ai_max_pilot' => $this->pilot()]]);

        $this->assertTrue(CampaignSpendGuardrails::validAiMaxPilot($campaign->fresh(['customer'])));
        $this->assertTrue(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CONVERSIONS', null));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'TARGET_CPA', null));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', 10_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CONVERSIONS', 10_000_000));
        $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($campaign));
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 10_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, 1.1));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, 0.8));
        $this->assertTrue(CampaignSpendGuardrails::permitsDailyBudget($campaign, 50_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 51_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 40_000_000));
        $this->assertEquals($old, $campaign->fresh()->spend_guardrails['automation_limits']);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh()));
        $this->assertTrue(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
    }

    public function test_invalid_or_expired_pilot_cannot_reactivate_legacy_optimizers_or_uncapped_bidding(): void
    {
        foreach ([['acknowledges_no_cpc_cap' => false], ['permits_conversion_bidding' => false], ['approval' => ''],
            ['approved_at' => ''], ['approved_at' => now()->addHour()->toIso8601String()], ['baseline_cost_micros' => null],
            ['review_spend_micros' => 0], ['currency_code' => 'USD'], ['account_timezone' => 'invalid'],
            ['selected_conversion_action' => 'customers/9999999999/conversionActions/444'],
            ['ends_at' => now()->addDays(15)->toIso8601String()],
            ['starts_at' => now()->subDays(2)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]] as $changes) {
            $campaign = $this->campaign($this->pilot($changes));
            $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($campaign));
            $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CONVERSIONS', null));
            $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 10_000_000));
            $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
        }
    }

    public function test_a_stale_campaign_cannot_enable_or_mutate_after_an_owner_hold(): void
    {
        $campaign = $this->campaign($this->pilot());
        $stale = $campaign->fresh(['customer']);
        $campaign->update(['spend_safety_hold' => ['reason' => 'owner_pause']]);
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($stale, 'MAXIMIZE_CONVERSIONS', null));
        $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($stale));
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
    }

    public function test_native_mode_does_not_run_legacy_reach_forecasts_or_cpc_bootstrap(): void
    {
        $campaign = $this->campaign($this->pilot());
        $planner = $this->mock(GoogleSearchReachPlanner::class);
        $planner->shouldNotReceive('assess');
        $result = (new GoogleSearchReachRecovery($planner))->check($campaign);
        $this->assertSame('native_automation', $result['status']);
        $this->assertFalse($result['mutation_allowed']);
        $this->assertFalse((new GoogleSearchDeliveryRecovery)->bootstrap($campaign, [])['started']);
        $cost = (new GoogleSearchCostProtection)->check($campaign);
        $this->assertSame('not_applicable', $cost['status']);
        $this->assertSame(0, $cost['actions_taken']);
    }

    public function test_google_recommendations_cannot_change_a_controlled_pilot_or_legacy_cpc_profile(): void
    {
        foreach ([null, $this->pilot()] as $pilot) {
            $campaign = $this->campaign($pilot);
            $service = new AiMaxRecommendationProbe($campaign->customer);
            $service->recommendations = [['resource_name' => 'customers/1234567890/recommendations/abc',
                'campaign_resource' => 'customers/1234567890/campaigns/999', 'type' => RecommendationType::RAISE_TARGET_CPA]];
            $this->assertFalse($service('1234567890', ['customers/1234567890/recommendations/abc']));
            $this->assertSame(0, $service->writes);
            $campaign->delete();
        }
    }

    public function test_google_keyword_and_bidding_recommendations_remain_open_during_native_learning(): void
    {
        $campaign = $this->campaign($this->pilot());
        $recommendations = [];
        foreach ([RecommendationType::KEYWORD, RecommendationType::USE_BROAD_MATCH_KEYWORD, RecommendationType::RAISE_TARGET_CPA] as $index => $type) {
            $recommendations[] = ['resource_name' => 'customers/1234567890/recommendations/r'.$index,
                'type' => $type, 'campaign_resource' => 'customers/1234567890/campaigns/999',
                'keyword' => $type === RecommendationType::KEYWORD ? ['text' => 'google ads services', 'match_type' => 3] : null];
        }
        $reader = $this->createMock(GetGoogleAdsRecommendations::class);
        $reader->expects($this->exactly(2))->method('__invoke')->with('1234567890')->willReturn($recommendations);
        $this->app->bind(GetGoogleAdsRecommendations::class, fn () => $reader);
        $apply = $this->createMock(ApplyRecommendation::class);
        $apply->expects($this->never())->method('__invoke');
        $this->app->bind(ApplyRecommendation::class, fn () => $apply);
        $dismiss = $this->createMock(DismissRecommendation::class);
        $dismiss->expects($this->never())->method('__invoke');
        $this->app->bind(DismissRecommendation::class, fn () => $dismiss);

        (new ReviewGoogleAdsRecommendations)->handle();
        (new ReviewGoogleAdsRecommendations)->handle();

        $this->assertSame(3, AgentActivity::where('customer_id', $campaign->customer_id)->where('action', 'recommendation_retained')->count());
        $keyword = AgentActivity::where('customer_id', $campaign->customer_id)->where('details->type', RecommendationType::KEYWORD)->firstOrFail();
        $this->assertSame('google ads services', $keyword->details['keyword']['text']);
        $this->assertSame('ai_max_pilot_review', $keyword->details['reason']);
        $this->assertSame(0, AgentActivity::where('customer_id', $campaign->customer_id)->where('action', 'recommendations_applied')->count());
    }

    public function test_unbounded_legacy_recommendations_keep_their_allowlist_and_dry_run_is_read_only(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['spend_guardrails' => []]);
        $service = new AiMaxRecommendationProbe($campaign->customer);
        $service->recommendations = [['resource_name' => 'customers/1234567890/recommendations/abc',
            'campaign_resource' => 'customers/1234567890/campaigns/999', 'type' => RecommendationType::OPTIMIZE_AD_ROTATION]];
        $this->assertTrue($service->dryRun()('1234567890', ['customers/1234567890/recommendations/abc']));
        $this->assertSame(0, $service->writes);
        $this->assertTrue($service->dryRun(false)('1234567890', ['customers/1234567890/recommendations/abc']));
        $this->assertSame(1, $service->writes);
        $service->recommendations[0]['type'] = RecommendationType::KEYWORD;
        $this->assertFalse($service('1234567890', ['customers/1234567890/recommendations/abc']));
        $this->assertSame(1, $service->writes);
    }
}

class AiMaxRecommendationProbe extends ApplyRecommendation
{
    public array $recommendations = [];

    public int $writes = 0;

    protected function readRecommendations(string $customerId, array $resources): array
    {
        return $this->recommendations;
    }

    protected function apply(string $customerId, array $operations): void
    {
        $this->writes++;
    }
}
