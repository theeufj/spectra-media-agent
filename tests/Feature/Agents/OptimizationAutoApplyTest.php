<?php

namespace Tests\Feature\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What may be auto-applied to a live campaign, and on what evidence.
 */
class OptimizationAutoApplyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_budget_is_not_stored_locally_when_the_platform_rejects_the_change(): void
    {
        // No system-user token: FacebookCampaignService returns false without
        // issuing a request — the platform never received the change.
        config(['services.facebook.system_user_token' => null]);

        $campaign = $this->facebookCampaign(50);

        $result = app(RecommendationApplier::class)->apply($campaign, [
            'type' => 'BUDGET',
            'suggested_value' => 80,
        ]);

        $this->assertFalse($result['applied']);
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
    }

    public function test_budget_above_the_approved_envelope_requires_review(): void
    {
        config(['services.facebook.system_user_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $campaign = $this->facebookCampaign(50);

        $result = app(RecommendationApplier::class)->apply($campaign, [
            'type' => 'BUDGET',
            'suggested_value' => 500, // 10x — a hallucinated number
        ]);

        $this->assertFalse($result['applied']);
        $this->assertTrue($result['requires_review']);
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
        Http::assertNothingSent();
    }

    public function test_an_aliased_type_still_reaches_its_applier(): void
    {
        // BUDGET_ADJUSTMENT is scored against BUDGET's threshold, so it must be
        // applied as a budget change rather than falling to "not yet supported".
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'daily_budget' => 40,
            'approved_daily_budget' => 60,
        ]);

        $result = app(RecommendationApplier::class)->apply($campaign, [
            'type' => 'BUDGET_ADJUSTMENT',
            'suggested_value' => 60,
        ]);

        $this->assertTrue($result['applied']);
        $this->assertStringContainsString('Budget adjusted', $result['message']);
        $this->assertEquals(60, $campaign->fresh()->daily_budget);
    }

    public function test_a_recommendation_missing_its_required_fields_is_queued_for_review(): void
    {
        $categorized = (new RecommendationScorer)->categorize([
            'recommendations' => [
                // No suggested_value — the applier has nothing to push.
                ['type' => 'BUDGET', 'confidence_score' => 0.95],
                ['type' => 'BUDGET_ADJUSTMENT', 'confidence_score' => 0.95, 'suggested_value' => 80],
                // Device bid adjustment with no device and no modifier.
                ['type' => 'TARGETING', 'confidence_score' => 0.99, 'sub_type' => 'device'],
            ],
        ]);

        $this->assertCount(1, $categorized['auto_apply']);
        $this->assertSame('BUDGET_ADJUSTMENT', $categorized['auto_apply'][0]['type']);
        $this->assertCount(2, $categorized['recommended']);
    }

    public function test_the_applier_refuses_an_under_specified_recommendation(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'daily_budget' => 40,
            'approved_daily_budget' => 60,
        ]);

        $result = app(RecommendationApplier::class)->apply($campaign, [
            'type' => 'AD_EXTENSIONS',
            'sub_type' => 'call', // no phone_number
        ]);

        $this->assertFalse($result['applied']);
        $this->assertTrue($result['requires_review']);
        $this->assertStringContainsString('phone_number', $result['message']);
    }

    public function test_canonical_type_folds_the_models_verbose_names(): void
    {
        $this->assertSame('BUDGET', RecommendationScorer::canonicalType('budget_adjustment'));
        $this->assertSame('NEGATIVE_KEYWORDS', RecommendationScorer::canonicalType('SEARCH_TERM_REVIEW'));
        // Unknown types keep their own name so they fall to the default threshold.
        $this->assertSame('SOMETHING_NEW', RecommendationScorer::canonicalType('something_new'));
    }

    private function facebookCampaign(float $dailyBudget): Campaign
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_1234567890']);

        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'facebook_ads_campaign_id' => '9876543210',
            'daily_budget' => $dailyBudget,
        ]);
    }
}
