<?php

namespace Tests\Feature;

use App\Jobs\AutomatedCampaignMaintenance;
use App\Jobs\ExpandBroadMatchKeywords;
use App\Jobs\FindUnderperformingKeywords;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\BidAdjustmentAgent;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetLocationBidAdjustment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrialOptimizationMutationTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '123',
            'status' => 'active', 'daily_budget' => 10, 'approved_daily_budget' => 50,
            'spend_guardrails' => ['enabled' => true, 'max_daily_budget_micros' => 10000000, 'max_cpc_bid_micros' => 3000000]]);
    }

    public function test_device_location_and_schedule_boosts_cannot_amplify_trial_cpc_ceiling(): void
    {
        $campaign = $this->campaign();
        $resource = $campaign->googleAdsResourceName();
        $cases = [
            [SetDeviceBidAdjustment::class, ['1234567890', $resource, 2, 1.2]],
            [SetLocationBidAdjustment::class, ['1234567890', $resource, 'geoTargetConstants/2036', 1.2]],
            [SetAdSchedule::class, ['1234567890', $resource, 2, 9, 2, 17, 2, 1.2]],
        ];
        foreach ($cases as [$class, $arguments]) {
            $service = $this->createPartialMock($class, ['ensureClient']);
            $service->expects($this->never())->method('ensureClient');
            $this->assertNull($service(...$arguments), $class.' must reject before touching Google.');
        }
    }

    public function test_deterministic_bid_adjustments_skip_the_trial_and_spending_hold(): void
    {
        $campaign = $this->campaign();
        $result = app(BidAdjustmentAgent::class)->optimize($campaign);
        $this->assertTrue($result['skipped']);
        $this->assertSame([], $result['adjustments']);
        $this->assertSame([], $result['errors']);
        $campaign->forceFill(['spend_guardrails' => null, 'spend_safety_hold' => ['reason' => 'spend_cap']])->save();
        $this->assertTrue(app(BidAdjustmentAgent::class)->optimize($campaign)['skipped']);
    }

    public function test_an_owner_approved_budget_cannot_persist_above_trial_limit(): void
    {
        $campaign = $this->campaign();

        $result = app(RecommendationApplier::class)->apply($campaign, ['type' => 'BUDGET', 'suggested_value' => 20], true);

        $this->assertFalse($result['applied']);
        $this->assertStringContainsString('approved restart limits', $result['message']);
        $this->assertEquals(10, $campaign->fresh()->daily_budget);
    }

    public function test_maintenance_records_read_only_reason_without_calling_any_mutation_agent_or_queueing_expansion(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['primary_status' => 'ELIGIBLE']);
        $selfHealing = $this->createMock(\App\Services\Agents\SelfHealingAgent::class);
        $selfHealing->expects($this->never())->method('heal');
        $search = $this->createMock(\App\Services\Agents\SearchTermMiningAgent::class);
        $search->expects($this->never())->method('mine');
        $budget = $this->createMock(\App\Services\Agents\BudgetIntelligenceAgent::class);
        $budget->expects($this->never())->method('optimize');
        $budget->expects($this->never())->method('checkCrossPlatformReallocation');
        $creative = $this->createMock(\App\Services\Agents\CreativeIntelligenceAgent::class);
        $creative->expects($this->never())->method('analyze');
        $extension = $this->createMock(\App\Services\Agents\AdExtensionAgent::class);
        $extension->expects($this->never())->method('manage');
        $bid = $this->createMock(BidAdjustmentAgent::class);
        $bid->expects($this->never())->method('optimize');
        $quality = $this->createMock(\App\Services\Agents\QualityScoreImprovementAgent::class);
        $quality->expects($this->never())->method('improve');
        $quality->expects($this->never())->method('checkAdStrength');

        (new AutomatedCampaignMaintenance)->handle($selfHealing, $search, $budget, $creative, $extension, $bid, $quality,
            $this->createMock(\App\Services\Agents\FacebookLearningPhaseAgent::class),
            $this->createMock(\App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent::class),
            $this->createMock(\App\Services\Agents\LinkedInCampaignOptimizationAgent::class),
            $this->createMock(\App\Services\Agents\AudienceIntelligenceAgent::class));

        $this->assertTrue($campaign->fresh()->last_maintenance_results['mutations_suspended']);
        $this->assertStringContainsString('monitoring continue', $campaign->fresh()->last_maintenance_results['reason']);
        Queue::assertNotPushed(ExpandBroadMatchKeywords::class);
        Queue::assertNotPushed(FindUnderperformingKeywords::class);
        $run = \App\Models\AgentRun::where('job', 'AutomatedCampaignMaintenance')->latest('id')->first();
        $this->assertSame(1, $run->details['read_only_campaigns']);
        $this->assertSame(0, $run->actions_taken);
    }

    public function test_already_queued_and_direct_agent_calls_recheck_a_new_persisted_hold(): void
    {
        $campaign = $this->campaign();
        $campaign->forceFill(['spend_guardrails' => null])->save();
        $campaign->fresh()->forceFill(['spend_safety_hold' => ['reason' => 'spend_cap']])->save();
        $this->assertNull($campaign->spend_safety_hold, 'The caller still holds an older model.');
        $loggedMessages = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message, array $context) use (&$loggedMessages): void {
            $loggedMessages[] = ['message' => $message, 'context' => $context];
        });
        Log::swap($logger);

        (new ExpandBroadMatchKeywords($campaign))->handle();
        $negative = $this->createMock(\App\Services\GoogleAds\NegativeKeywords\AddNegativeKeywordService::class);
        $negative->expects($this->never())->method('__invoke');
        (new FindUnderperformingKeywords($campaign->id))->handle($negative);
        $this->assertTrue(app(\App\Services\Agents\SearchTermMiningAgent::class)->mine($campaign)['skipped']);
        $gemini = $this->createMock(\App\Services\GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $this->assertTrue((new \App\Services\Agents\CreativeIntelligenceAgent($gemini))->analyze($campaign)['skipped']);
        $this->assertContains(['message' => 'ExpandBroadMatchKeywords: Automatic keyword changes suspended by trial or spending hold', 'context' => ['campaign_id' => $campaign->id]], $loggedMessages);
        $this->assertContains(['message' => 'FindUnderperformingKeywords: Automatic keyword changes suspended by trial or spending hold', 'context' => ['campaign_id' => $campaign->id]], $loggedMessages);
    }
}
