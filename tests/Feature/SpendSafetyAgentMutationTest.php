<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GeminiService;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class SpendSafetyAgentMutationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_trial_or_hold_keeps_self_healing_diagnostics_but_disables_mutation_even_when_the_feature_is_enabled(): void
    {
        foreach ([['spend_guardrails' => ['enabled' => true]], ['spend_safety_hold' => ['reason' => 'manual_pause']]] as $attributes) {
            $customer = Customer::factory()->create(['google_ads_customer_id' => fake()->unique()->numerify('##########')]);
            $campaign = Campaign::factory()->create($attributes + ['customer_id' => $customer->id, 'google_ads_campaign_id' => '999', 'status' => 'active']);
            Feature::for($customer)->activate(AutoHealing::class);
            $agent = new ReadOnlyHealingDiagnosticProbe(app(GeminiService::class));
            $result = $agent->heal($campaign);
            $this->assertFalse($result['auto_healing_enabled']);
            $this->assertTrue($agent->diagnosed);
            $this->assertSame([], $result['actions_taken']);
            $this->assertSame('disapproved_ad', $result['warnings'][0]['type']);
            $this->assertNotEmpty($result['read_only_reason']);
        }
    }

    public function test_quality_score_and_hourly_budget_agents_see_a_hold_saved_after_the_campaign_was_loaded(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $stale = $campaign->fresh();
        $campaign->update(['spend_safety_hold' => ['reason' => 'no_conversions_after_matured_spend']]);

        $qs = (new QualityScoreImprovementAgent(app(GeminiService::class)))->improve($stale);
        $budget = (new BudgetIntelligenceAgent)->optimize($stale);
        $this->assertTrue($qs['skipped']);
        $this->assertSame([], $qs['actions']);
        $this->assertSame([], $qs['paused']);
        $this->assertSame([], $budget['adjustments']);
        $this->assertNotEmpty($budget['read_only_reason']);
    }
}

class ReadOnlyHealingDiagnosticProbe extends SelfHealingAgent
{
    public bool $diagnosed = false;

    protected function healGoogleAdsCampaign(Campaign $campaign, Customer $customer, array &$results): void
    {
        $this->diagnosed = true;
        $this->handleGoogleDisapprovedAd($campaign, $customer, $customer->cleanGoogleCustomerId(), [
            'resource_name' => 'customers/1234567890/adGroupAds/1~2',
            'approval_status' => PolicyApprovalStatus::DISAPPROVED,
            'policy_topics' => [['topic' => 'MISLEADING_CONTENT']],
        ], $results);
    }
}
