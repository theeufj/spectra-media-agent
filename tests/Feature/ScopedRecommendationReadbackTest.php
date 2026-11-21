<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use App\Models\Strategy;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationReadback;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScopedRecommendationReadbackTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'facebook_ads_account_id' => 'act_777']);

        return Campaign::factory()->for($customer)->create(['google_ads_campaign_id' => '1234', 'approved_daily_budget' => 80, 'daily_budget' => 40, 'status' => 'active']);
    }

    public function test_cross_resource_budget_and_unapproved_targeting_cannot_mutate(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['facebook_ads_campaign_id' => '1111']);
        $applier = app(RecommendationApplier::class);
        $result = $applier->apply($campaign, ['type' => 'BUDGET', 'suggested_value' => 60,
            'target_entity' => ['account_id' => '1234567890', 'external_campaign_id' => '1234']], true);
        $this->assertFalse($result['applied']);
        $this->assertTrue($result['requires_review']);
        $this->assertEquals(40, $campaign->fresh()->daily_budget);
        $campaign->update(['facebook_ads_campaign_id' => null]);
        $result = $applier->apply($campaign, ['type' => 'TARGETING', 'sub_type' => 'device', 'device_type' => 2, 'suggested_value' => 1.1]);
        $this->assertTrue($result['requires_review']);
        Http::assertNothingSent();
    }

    public function test_generic_recommendations_cannot_create_or_replace_an_existing_schedule(): void
    {
        $campaign = $this->campaign();
        $service = $this->createMock(SetAdSchedule::class);
        $service->expects($this->never())->method('setBusinessHours');
        $service->expects($this->never())->method('__invoke');
        $service->expects($this->once())->method('enabledIntervals')->willReturn([]);
        $service->expects($this->never())->method('updateExistingModifier');
        $this->app->bind(SetAdSchedule::class, fn () => $service);
        $result = app(RecommendationApplier::class)->apply($campaign, ['type' => 'SCHEDULE', 'sub_type' => 'business_hours',
            'suggested_value' => 1.2, 'criterion_resource_name' => 'customers/1234567890/campaignCriteria/1234~555'], true);
        $this->assertFalse($result['applied']);
        $this->assertTrue($result['requires_review']);
    }

    public function test_uncorroborated_submission_only_promotes_after_readback_of_the_same_resource_and_value(): void
    {
        $campaign = $this->campaign();
        $s = Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads', 'google_ads_campaign_id' => '1234', 'daily_budget' => 40]);
        $receipt = ['budget_cents' => 6000];
        $row = Recommendation::create(['campaign_id' => $campaign->id, 'type' => 'BUDGET', 'platform' => 'google',
            'target_entity' => ['account_id' => '1234567890', 'external_campaign_id' => '1234', 'strategy_id' => $s->id],
            'status' => 'pending_verification', 'requires_approval' => false, 'execution' => ['result' => ['receipt' => $receipt, 'applied' => false]]]);
        $reader = $this->getMockBuilder(RecommendationReadback::class)->disableOriginalConstructor()->onlyMethods(['rows'])->getMock();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($reader, $campaign->customer);
        $reader->expects($this->exactly(3))->method('rows')->willReturn(
            [['campaign' => ['resourceName' => 'customers/1234567890/campaigns/9999'], 'campaignBudget' => ['amountMicros' => 60000000]]],
            [['campaign' => ['resourceName' => 'customers/1234567890/campaigns/1234'], 'campaignBudget' => ['amountMicros' => 40000000]]],
            [['campaign' => ['resourceName' => 'customers/1234567890/campaigns/1234'], 'campaignBudget' => ['amountMicros' => 60000000]]]);
        $this->app->bind(RecommendationReadback::class, fn () => $reader);
        $this->assertFalse(RecommendationReadback::reconcile($row));
        $this->assertFalse(RecommendationReadback::reconcile($row));
        $this->assertSame('pending_verification', $row->fresh()->status);
        $this->assertTrue(RecommendationReadback::reconcile($row));
        $this->assertSame('applied', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->execution['result']['verified_at']);
    }
}
