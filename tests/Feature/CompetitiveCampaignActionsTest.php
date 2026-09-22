<?php

namespace Tests\Feature;

use App\Jobs\ApplyCompetitiveRecommendation;
use App\Jobs\ReviewCompetitiveCampaigns;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Competition\CompetitiveActionReport;
use App\Services\Competition\CompetitivePlatformGateway;
use App\Services\Competition\CompetitiveRecommendationService;
use App\Services\Competition\CompetitorCampaignContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CompetitiveCampaignActionsTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => (string) fake()->unique()->numberBetween(1000000000, 9999999999), 'competitive_strategy' => ['keyword_strategy' => ['opportunity_keywords' => ['managed ads']]],
            'competitive_strategy_updated_at' => now(),
        ]);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'primary_status' => 'ELIGIBLE',
            'daily_budget' => 50, 'approved_daily_budget' => 70, 'google_ads_campaign_id' => '123']);
    }

    private function state(): array
    {
        return ['enabled' => true, 'search' => true, 'budget_micros' => 50000000,
            'ads' => [['resource' => 'customers/1234567890/adGroupAds/12~34', 'ad_group' => 'customers/1234567890/adGroups/12',
                'enabled' => true, 'headlines' => ['Existing one', 'Existing two', 'Existing three'], 'descriptions' => ['Existing description one', 'Existing description two'],
                'final_urls' => ['https://example.com/offer']]],
            'keywords' => [['resource' => 'customers/1234567890/adGroupCriteria/12~45', 'ad_group' => 'customers/1234567890/adGroups/12',
                'text' => 'old keyword', 'match_type' => 2, 'enabled' => true, 'cpc_bid_micros' => 1000000]],
        ];
    }

    private function rec(): array
    {
        return ['type' => 'BUDGET', 'suggested_value' => 60, 'reasoning' => 'Relevant gap and strong conversions',
            'source' => 'competitor', 'evidence' => [['id' => 'report:1', 'kind' => 'competitive_strategy', 'observed_at' => now()->toIso8601String()]]];
    }

    private function service(): array
    {
        $gateway = Mockery::mock(CompetitivePlatformGateway::class);
        $gateway->shouldReceive('state')->andReturn($this->state());

        $this->app->instance(CompetitivePlatformGateway::class, $gateway);

        return [app(CompetitiveRecommendationService::class), $gateway];
    }

    public function test_context_is_fresh_tenant_scoped_and_signals_survive_cache_overwrite(): void
    {
        $campaign = $this->campaign();
        $other = $this->campaign();
        $other->customer->update(['competitive_strategy' => ['secret' => 'other account']]);
        Competitor::create(['customer_id' => $campaign->customer_id, 'url' => 'https://old.example', 'domain' => 'old.example',
            'last_analyzed_at' => now()->subDays(40), 'messaging_analysis' => ['old' => true]]);
        $context = new CompetitorCampaignContext;
        $context->recordSignal($campaign, ['recommendation' => 'Auction overlap increased', 'competitor' => 'rival.example']);
        Cache::put('optimization:campaign:'.$campaign->id, ['recommendations' => []]);
        $sources = $context->forCampaign($campaign);
        $this->assertCount(2, $sources);
        $this->assertStringContainsString('rival.example', json_encode($sources));
        $this->assertStringNotContainsString('other account', json_encode($sources));
        $this->assertStringNotContainsString('old.example', json_encode($sources));
    }

    public function test_invented_evidence_cannot_authorize_a_competitor_test(): void
    {
        $context = new CompetitorCampaignContext;
        $result = $context->attribute(['recommendations' => [
            ['type' => 'COMPETITOR_KEYWORD_TEST', 'source' => 'competitor', 'evidence_ids' => ['someone-else'], 'keywords' => ['bad']],
            ['type' => 'BUDGET', 'source' => 'invented', 'evidence_ids' => ['actual'], 'suggested_value' => 60],
        ]], [['id' => 'actual', 'data' => ['proof' => true]]], ['clicks' => 100]);
        $this->assertCount(1, $result['recommendations']);
        $this->assertSame('competitor', $result['recommendations'][0]['source']);
        $this->assertSame('actual', $result['recommendations'][0]['evidence'][0]['id']);
    }

    public function test_repeated_analysis_keeps_one_action_and_does_not_erase_its_history(): void
    {
        [$service] = $this->service();
        $campaign = $this->campaign();
        $first = $service->record($campaign, $this->rec());
        $first->update(['status' => 'rejected']);
        $second = $service->record($campaign, [...$this->rec(), 'reasoning' => 'Different wording']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('rejected', $second->status);
    }

    public function test_approving_twice_queues_once_and_another_tenant_cannot_approve(): void
    {
        [$service] = $this->service();
        $campaign = $this->campaign();
        $rec = $service->record($campaign, $this->rec());
        $user = User::factory()->create();
        $campaign->customer->users()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user)->post(route('strategy.war-room.recommendations.approve', $rec))->assertRedirect();
        $this->post(route('strategy.war-room.recommendations.approve', $rec))->assertRedirect();
        Queue::assertPushed(ApplyCompetitiveRecommendation::class, 1);
        $this->assertSame('approved', $rec->fresh()->status);
        $stranger = User::factory()->create();
        $other = Customer::factory()->create();
        $other->users()->attach($stranger->id, ['role' => 'owner']);
        $this->actingAs($stranger)->post(route('strategy.war-room.recommendations.approve', $rec))->assertForbidden();
        $this->actingAs($user)->post(route('strategy.war-room.recommendations.reject', $rec))->assertRedirect();
        $this->assertSame('approved', $rec->fresh()->status, 'A running or queued change cannot be relabelled dismissed.');
    }

    public function test_only_platform_verification_marks_an_applied_change_verified_and_a_duplicate_job_does_not_apply_again(): void
    {
        [$service, $gateway] = $this->service();
        $rec = $service->record($this->campaign(), $this->rec(), true);
        $gateway->shouldReceive('apply')->once()->andReturn(['applied' => true]);
        $gateway->shouldReceive('verified')->once()->andReturn(false);
        $service->apply($rec);
        $this->assertSame('applied', $rec->fresh()->status);
        $this->assertNull($rec->fresh()->verified_at);
        $service->apply($rec->fresh());
        $gateway->shouldReceive('verified')->once()->andReturn(true);
        $service->verify($rec->fresh());
        $this->assertSame('verified', $rec->fresh()->status);
        $this->assertNotNull($rec->fresh()->execution['before']);
        $this->assertNotNull($rec->fresh()->execution['after']);
    }

    public function test_platform_failure_is_visible_and_is_not_reported_as_applied(): void
    {
        [$service, $gateway] = $this->service();
        $rec = $service->record($this->campaign(), $this->rec(), true);
        $gateway->shouldReceive('apply')->once()->andReturn(['applied' => false, 'message' => 'Google rejected this change']);
        $gateway->shouldNotReceive('verified');
        $service->apply($rec);
        $this->assertSame('failed', $rec->fresh()->status);
        $this->assertNull($rec->fresh()->applied_at);
        $this->assertSame('Google rejected this change', $rec->fresh()->execution['result']['message']);
    }

    public function test_budget_limit_is_rechecked_after_approval_before_any_mutation(): void
    {
        [$service, $gateway] = $this->service();
        $campaign = $this->campaign();
        $rec = $service->record($campaign, $this->rec(), true);
        $campaign->update(['approved_daily_budget' => 55]);
        $gateway->shouldNotReceive('apply');
        $service->apply($rec);
        $this->assertSame('failed', $rec->fresh()->status);
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
    }

    public function test_an_interrupted_mutation_is_read_back_without_replaying_it(): void
    {
        [$service, $gateway] = $this->service();
        $rec = $service->record($this->campaign(), $this->rec(), true);
        $gateway->shouldReceive('apply')->once()->andThrow(new \RuntimeException('Connection closed after request'));
        $service->apply($rec);
        $this->assertSame('needs_verification', $rec->fresh()->status);
        $this->assertNull($rec->fresh()->applied_at);
        $service->apply($rec->fresh());
        $gateway->shouldReceive('verified')->once()->andReturn(true);
        $service->verify($rec->fresh());
        $this->assertSame('verified', $rec->fresh()->status);
        $this->assertArrayNotHasKey('error', $rec->fresh()->execution);
    }

    public function test_shared_or_billing_limited_budgets_need_portfolio_review(): void
    {
        [$service] = $this->service();
        $campaign = $this->campaign();
        $campaign->update(['billing_budget_multiplier' => 0.5]);
        $record = $service->record($campaign, $this->rec(), true);
        $this->assertSame('pending', $record->status);
        $this->assertFalse($record->execution['can_apply']);
        $campaign->update(['billing_budget_multiplier' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('portfolio review');
        $service->prepare($campaign, $this->rec(), [...$this->state(), 'shared_budget' => true]);
    }

    public function test_a_readback_outage_does_not_relabel_an_accepted_change_as_failed(): void
    {
        [$service, $gateway] = $this->service();
        $rec = $service->record($this->campaign(), $this->rec(), true);
        $gateway->shouldReceive('apply')->once()->andReturn(['applied' => true]);
        $gateway->shouldReceive('verified')->once()->andThrow(new \RuntimeException('Readback unavailable'));
        $service->apply($rec);
        $this->assertSame('applied', $rec->fresh()->status);
        $this->assertNotNull($rec->fresh()->applied_at);
        $this->assertNull($rec->fresh()->verified_at);
    }

    public function test_manual_approval_works_when_automatic_optimisation_is_disabled(): void
    {
        [$service, $gateway] = $this->service();
        $campaign = $this->campaign();
        \Laravel\Pennant\Feature::for($campaign->customer)->deactivate(\App\Features\AutoOptimization::class);
        $rec = $service->record($campaign, $this->rec(), true);
        $this->assertSame('pending', $rec->status);
        $rec->update(['status' => 'approved']);
        $gateway->shouldReceive('apply')->once()->withArgs(fn ($target, $payload, $state, $approved) => $approved === true)
            ->andReturn(['applied' => true]);
        $gateway->shouldReceive('verified')->once()->andReturn(true);
        $service->apply($rec->fresh());
        $this->assertSame('verified', $rec->fresh()->status);
    }

    public function test_auction_changes_compare_the_same_campaign_across_dates(): void
    {
        $campaign = $this->campaign();
        $other = Campaign::factory()->create(['customer_id' => $campaign->customer_id, 'status' => 'active',
            'google_ads_campaign_id' => '456']);
        $competitor = Competitor::create(['customer_id' => $campaign->customer_id, 'url' => 'https://rival.example',
            'domain' => 'rival.example', 'auction_trends' => [
                ['date' => now()->toDateString(), 'campaign_resource' => $campaign->googleAdsResourceName(), 'impression_share' => 20],
                ['date' => now()->toDateString(), 'campaign_resource' => $other->googleAdsResourceName(), 'impression_share' => 60],
            ]]);
        $this->app->instance(\App\Services\Agents\CompetitorDiscoveryAgent::class,
            Mockery::mock(\App\Services\Agents\CompetitorDiscoveryAgent::class));
        $agent = app(\App\Services\Agents\CompetitorIntelligenceAgent::class);
        $method = new \ReflectionMethod($agent, 'analyzeAuctionTrends');
        $initialActions = $method->invoke($agent, $campaign->customer, []);
        $this->assertSame([], $initialActions);
        $competitor->update(['auction_trends' => [
            ['date' => now()->subDays(7)->toDateString(), 'campaign_resource' => $campaign->googleAdsResourceName(), 'impression_share' => 20],
            ['date' => now()->toDateString(), 'campaign_resource' => $campaign->googleAdsResourceName(), 'impression_share' => 40],
            ['date' => now()->toDateString(), 'campaign_resource' => $other->googleAdsResourceName(), 'impression_share' => 80],
        ]]);
        $actions = $method->invoke($agent, $campaign->customer, []);
        $this->assertCount(1, $actions);
        $this->assertSame($campaign->googleAdsResourceName(), $actions[0]['campaign_resource']);
        $this->assertSame(1, Recommendation::where('campaign_id', $campaign->id)->where('source', 'competitor_signal')->count());
        $this->assertSame(0, Recommendation::where('campaign_id', $other->id)->where('source', 'competitor_signal')->count());
    }

    public function test_keyword_and_copy_tests_require_review_and_pin_the_existing_destination(): void
    {
        [$service] = $this->service();
        $campaign = $this->campaign();
        $rec = $service->record($campaign, [...$this->rec(), 'type' => 'COMPETITOR_AD_TEST',
            'headlines' => ['Managed Ads For Teams', 'Focus On Your Business', 'Simple Campaign Management'],
            'descriptions' => ['A new angle to test alongside the existing campaign.', 'Review your ad plan and get started.'],
            'final_urls' => ['https://untrusted.example']], true);
        $this->assertSame('pending', $rec->status);
        $this->assertSame(['https://example.com/offer'], $rec->payload['final_urls']);
        $this->assertTrue($rec->execution['can_apply']);
        Queue::assertNotPushed(ApplyCompetitiveRecommendation::class);
        $bad = $service->record($campaign, [...$this->rec(), 'type' => 'COMPETITOR_KEYWORD_TEST', 'keywords' => ['a', 'b', 'c', 'd']]);
        $this->assertFalse($bad->execution['can_apply']);
    }

    public function test_a_keyword_resource_from_another_campaign_cannot_be_changed(): void
    {
        [$service] = $this->service();
        $rec = $service->record($this->campaign(), [...$this->rec(), 'type' => 'BIDDING', 'sub_type' => 'keyword_cpc',
            'keyword_resource' => 'customers/1234567890/adGroupCriteria/999~999', 'suggested_value' => 1100000], true);
        $this->assertSame('pending', $rec->status);
        $this->assertFalse($rec->execution['can_apply']);
    }

    public function test_report_history_is_scoped_to_the_customer(): void
    {
        [$service] = $this->service();
        $first = $this->campaign();
        $second = $this->campaign();
        $service->record($first, $this->rec());
        $service->record($second, $this->rec());
        $report = (new CompetitiveActionReport)->forCustomer($first->customer);
        $this->assertCount(1, $report['actions']);
        $this->assertSame($first->uuid, $report['actions'][0]['campaign_uuid']);
    }

    public function test_measured_outcome_uses_complete_non_overlapping_windows_and_does_not_claim_causation(): void
    {
        [$service] = $this->service();
        $campaign = $this->campaign();
        $rec = $service->record($campaign, $this->rec());
        $applied = now()->subDays(9);
        $rec->update(['status' => 'verified', 'applied_at' => $applied, 'verified_at' => $applied,
            'execution' => ['baseline' => ['days' => 7, 'clicks' => 100, 'conversions' => 10]]]);
        for ($i = 1; $i <= 7; $i++) {
            GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'date' => $applied->copy()->addDays($i)->toDateString(),
                'impressions' => 100, 'clicks' => 10, 'cost' => 5, 'conversions' => 2]);
        }
        $service->measure($rec->fresh());
        $outcome = $rec->fresh()->outcome;
        $this->assertSame('measured', $rec->fresh()->status);
        $this->assertSame(70, $outcome['after']['clicks']);
        $this->assertTrue($outcome['sufficient_data']);
        $this->assertStringContainsString('does not isolate', $outcome['summary']);
    }

    public function test_report_review_uses_only_serving_campaigns_and_persists_competitor_actions(): void
    {
        $campaign = $this->campaign();
        $draft = Campaign::factory()->create(['customer_id' => $campaign->customer_id, 'primary_status' => 'PENDING']);
        $agent = Mockery::mock(CampaignOptimizationAgent::class, ['analyze' => [
            'recommendations' => [$this->rec(), ['type' => 'BUDGET', 'suggested_value' => 55]],
        ]]);
        $this->app->instance(CampaignOptimizationAgent::class, $agent);
        [$service] = $this->service();
        (new ReviewCompetitiveCampaigns($campaign->customer_id))->handle(app(CampaignOptimizationAgent::class), $service);
        $this->assertSame(1, Recommendation::where('source', 'competitor')->where('campaign_id', $campaign->id)->count());
        $this->assertSame(0, Recommendation::where('campaign_id', $draft->id)->count());
        $this->assertSame('completed', Cache::get('competitive_review:'.$campaign->customer_id)['status']);
    }

    public function test_actual_optimiser_prompt_contains_dated_research_and_preserves_its_provenance(): void
    {
        $campaign = $this->campaign();
        $this->service();
        $sources = (new CompetitorCampaignContext)->forCampaign($campaign);
        $gemini = Mockery::mock(\App\Services\GeminiService::class);
        $gemini->shouldReceive('generateContent')->withArgs(function ($model, $prompt) {
            $this->assertStringContainsString('managed ads', $prompt);
            $this->assertStringContainsString('approved_daily_budget', $prompt);
            $this->assertStringContainsString('Existing one', $prompt);
            $this->assertStringContainsString('old keyword', $prompt);
            $this->assertStringContainsString('not proof a competitor bids', $prompt);

            return true;
        })->andReturn(['text' => json_encode(['recommendations' => [[
            'type' => 'COMPETITOR_KEYWORD_TEST', 'keywords' => ['managed ads'], 'evidence_ids' => [$sources[0]['id']],
        ]]])]);
        $this->app->instance(\App\Services\GeminiService::class, $gemini);
        $this->app->instance(\App\Services\Agents\Optimization\MetricsFetcher::class, Mockery::mock(
            \App\Services\Agents\Optimization\MetricsFetcher::class, [
                'fetchCurrent' => ['impressions' => 10000, 'clicks' => 500, 'conversions' => 40],
                'fetchHistorical' => null, 'platform' => 'Google Ads',
            ]));
        $analysis = app(CampaignOptimizationAgent::class)->analyze($campaign);
        $this->assertSame('competitor', $analysis['recommendations'][0]['source']);
        $this->assertSame($sources[0]['id'], $analysis['recommendations'][0]['evidence'][0]['id']);
        $this->assertFalse($analysis['recommendations'][0]['auto_apply_eligible']);
    }

    public function test_readback_checks_exact_keywords_and_does_not_recreate_an_existing_ad(): void
    {
        $gateway = new CompetitivePlatformGateway;
        $state = $this->state();
        $payload = ['type' => 'COMPETITOR_AD_TEST', 'ad_group' => $state['ads'][0]['ad_group'],
            'headlines' => $state['ads'][0]['headlines'], 'descriptions' => $state['ads'][0]['descriptions'],
            'final_urls' => $state['ads'][0]['final_urls']];
        $result = $gateway->apply($this->campaign(), $payload, $state);
        $this->assertTrue($result['applied']);
        $this->assertSame([$state['ads'][0]['resource']], $result['resources']);
        $this->assertTrue($gateway->verified($payload, $state));
        $this->assertFalse($gateway->verified(['type' => 'COMPETITOR_KEYWORD_TEST', 'keywords' => ['new term'], 'ad_group' => $payload['ad_group']], $state));
    }

    public function test_stale_proposals_and_campaign_locks_cannot_cause_an_unreviewed_mutation(): void
    {
        [$service, $gateway] = $this->service();
        $campaign = $this->campaign();
        $rec = $service->record($campaign, $this->rec(), true);
        $lock = Cache::lock('competitive_change:'.$campaign->id, 600);
        $lock->get();
        $this->assertFalse($service->apply($rec));
        $this->assertSame('approved', $rec->fresh()->status);
        $lock->release();
        $rec->update(['evidence' => [['observed_at' => now()->subDays(31)->toIso8601String()]]]);
        $gateway->shouldNotReceive('apply');
        $service->apply($rec->fresh());
        $this->assertSame('failed', $rec->fresh()->status);
        $this->assertStringContainsString('outdated research', $rec->fresh()->execution['error']);
    }
}
