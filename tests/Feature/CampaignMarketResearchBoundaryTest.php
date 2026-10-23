<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\DeployCampaign;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\FacebookAdsExecutionAgent;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Agents\LinkedInAdsExecutionAgent;
use App\Services\Agents\MicrosoftAdsExecutionAgent;
use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakePlatformExecutionAgent;
use Tests\TestCase;

class CampaignMarketResearchBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    private function expectation(Mockery\LegacyMockInterface $agent, string $method): Mockery\Expectation
    {
        $agent->shouldReceive($method);
        $director = $agent->mockery_getExpectationsFor($method);
        $this->assertInstanceOf(Mockery\ExpectationDirector::class, $director);
        $expectations = $director->getExpectations();
        $expectation = end($expectations);
        $this->assertInstanceOf(Mockery\Expectation::class, $expectation);

        return $expectation;
    }

    public static function deploymentPaths(): array
    {
        $paths = [];
        foreach (['Google Ads', 'Facebook Ads', 'Microsoft Ads', 'LinkedIn Ads'] as $platform) {
            $paths[$platform.' execution agent'] = [true, $platform];
            $paths[$platform.' legacy adapter'] = [false, $platform];
        }

        return $paths;
    }

    #[DataProvider('deploymentPaths')]
    public function test_direct_deployment_cannot_bypass_research_approval(bool $useAgents, string $platform): void
    {
        $campaign = Campaign::factory()->create(['budget_confirmed_at' => now()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => $platform, 'signed_off_at' => now()]);
        $this->mock(CampaignMarketResearchWorkflow::class, function ($mock) use ($campaign) {
            $mock->shouldReceive('assertApproved')->once()->withArgs(fn (Campaign $current) => $current->id === $campaign->id)
                ->andThrow(ValidationException::withMessages(['daily_budget' => 'Research the current campaign before approving spend.']));
        });

        $result = DeploymentService::deploy($campaign, $strategy, $campaign->customer, $useAgents);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['requires_market_research']);
        $this->assertSame('Research the current campaign before approving spend.', $result['error']);
        $this->assertNull($strategy->fresh()->deployment_status);
    }

    public function test_a_stale_strategy_object_does_not_hide_current_research_requirements(): void
    {
        $campaign = Campaign::factory()->create();
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'deployment_status' => 'active']);
        Strategy::whereKey($strategy->id)->update(['deployment_status' => null]);
        $this->mock(CampaignMarketResearchWorkflow::class, function ($mock) {
            $mock->shouldReceive('assertApproved')->once()
                ->andThrow(ValidationException::withMessages(['daily_budget' => 'Current proposal is unapproved.']));
        });

        $result = DeploymentService::deploy($campaign, $strategy, $campaign->customer);

        $this->assertTrue($result['requires_market_research']);
    }

    public static function executionPlatforms(): array
    {
        return array_map(fn ($class) => [$class], [GoogleAdsExecutionAgent::class, FacebookAdsExecutionAgent::class,
            MicrosoftAdsExecutionAgent::class, LinkedInAdsExecutionAgent::class]);
    }

    #[DataProvider('executionPlatforms')]
    public function test_intent_changed_during_ai_planning_is_checked_again_before_new_ads(string $class): void
    {
        $campaign = Campaign::factory()->create(['approved_daily_budget' => '5.00', 'daily_budget' => '5.00',
            'budget_confirmed_at' => now(), 'keywords' => [['text' => 'approved offer', 'match_type' => 'EXACT']]]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => '5.00',
            'deployment_status' => 'deploying', 'signed_off_at' => now()]);
        $context = new ExecutionContext($strategy, $campaign, $campaign->customer);
        $this->mock(CampaignMarketResearchWorkflow::class, function ($mock) {
            $mock->shouldReceive('assertApproved')->once()->withArgs(fn (Campaign $fresh) => $fresh->keywords[0]['text'] === 'unreviewed offer')
                ->andThrow(ValidationException::withMessages(['daily_budget' => 'The campaign changed during planning.']));
        });
        $agent = Mockery::mock($class, [$campaign->customer])->makePartial()->shouldAllowMockingProtectedMethods();
        $this->assertInstanceOf(PlatformExecutionAgent::class, $agent);
        $this->expectation($agent, 'bootPlatformServices')->once();
        $this->expectation($agent, 'validatePrerequisites')->once()->andReturn(new ValidationResult(true));
        $this->expectation($agent, 'generateExecutionPlan')->once()->andReturnUsing(function () use ($campaign) {
            $campaign->update(['keywords' => [['text' => 'unreviewed offer', 'match_type' => 'EXACT']]]);

            return new ExecutionPlan([]);
        });
        $agent->shouldNotReceive('executePlan');
        $agent->shouldNotReceive('handleExecutionError');

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertSame('campaign_market_research_not_approved', $result->errors[0]->code);
        $this->assertTrue($strategy->fresh()->requiresInitialMarketResearch(), 'Deploying without objects cannot bypass research approval.');
    }

    public function test_existing_platform_repairs_do_not_receive_a_new_approval_requirement(): void
    {
        $campaign = Campaign::factory()->create();
        // Unknown platform deliberately stops at adapter selection, with no API calls.
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'unknown',
            'deployment_status' => 'failed', 'deployed_at' => now()]);
        $this->mock(CampaignMarketResearchWorkflow::class, fn ($mock) => $mock->shouldNotReceive('assertApproved'));

        $result = DeploymentService::deploy($campaign, $strategy, $campaign->customer);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('requires_market_research', $result);
        $this->assertStringContainsString('No execution agent', $result['error']);
    }

    public function test_a_newer_valid_approval_does_not_authorize_a_plan_generated_for_old_inputs(): void
    {
        $campaign = Campaign::factory()->create(['approved_daily_budget' => '5.00', 'daily_budget' => '5.00',
            'budget_confirmed_at' => now(), 'keywords' => [['text' => 'old accepted offer', 'match_type' => 'EXACT']]]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => '5.00',
            'deployment_status' => 'deploying', 'signed_off_at' => now()]);
        $this->mock(CampaignMarketResearchWorkflow::class, fn ($mock) => $mock->shouldReceive('assertApproved')->once()->andReturnNull());
        $agent = new FakePlatformExecutionAgent($campaign->customer, ['plan' => function () use ($campaign) {
            // A customer can approve a newer quote while an earlier job is
            // planning. Its old model output must still be discarded.
            $campaign->update(['keywords' => [['text' => 'new accepted offer', 'match_type' => 'EXACT']]]);

            return new ExecutionPlan([]);
        }]);

        $result = $agent->execute(new ExecutionContext($strategy, $campaign, $campaign->customer));

        $this->assertFalse($result->success);
        $this->assertSame('campaign_market_research_plan_changed', $result->errors[0]->code);
        $this->assertSame(['boot', 'validate', 'plan'], $agent->calls);
    }

    public function test_research_approval_cannot_authorize_a_strategy_from_another_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $strategy = Strategy::factory()->create();
        $this->mock(CampaignMarketResearchWorkflow::class, fn ($mock) => $mock->shouldNotReceive('assertApproved'));

        $result = DeploymentService::deploy($campaign, $strategy, $campaign->customer);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('do not belong', $result['error']);
    }

    public function test_direct_queued_job_checks_current_approval_before_account_creation_or_funding(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['facebook_ads_account_id' => null]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Paused,
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads',
            'signed_off_at' => now(), 'deployment_status' => 'queued']);
        $this->mock(CampaignMarketResearchWorkflow::class, function ($mock) {
            $mock->shouldReceive('assertApproved')->once()
                ->andThrow(ValidationException::withMessages(['daily_budget' => 'The approved research no longer matches.']));
        });
        $billing = $this->mock(AdSpendBillingService::class, function ($mock) {
            $mock->shouldNotReceive('initializeCreditAccount');
            $mock->shouldNotReceive('addCredit');
        });

        (new DeployCampaign($campaign))->handle($billing);

        $this->assertSame('failed', $strategy->fresh()->deployment_status);
        $this->assertSame('The approved research no longer matches.', $strategy->fresh()->deployment_error);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame('5.00', $campaign->fresh()->daily_budget);
        $this->assertNull($customer->fresh()->facebook_ads_account_id);
        $this->assertNull($customer->adSpendCredit()->first());
    }

    public function test_no_stripe_customer_payment_method_or_credit_is_created_for_unresearched_campaign(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'stripe_id' => null, 'subscription_status' => 'active']);
        $customer = Customer::factory()->create(['service_type' => 'managed']);
        $customer->users()->attach($owner, ['role' => 'owner']);
        BrandGuideline::create(['customer_id' => $customer->id, 'brand_voice' => [], 'tone_attributes' => [],
            'writing_patterns' => [], 'color_palette' => [], 'typography' => [], 'visual_style' => [],
            'messaging_themes' => [], 'unique_selling_propositions' => [], 'target_audience' => [],
            'competitor_differentiation' => [], 'brand_personality' => [], 'do_not_use' => [],
            'user_verified' => true, 'extracted_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'daily_budget' => '20.00',
            'approved_daily_budget' => '20.00', 'budget_confirmed_at' => now()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now()]);
        $this->mock(CampaignMarketResearchWorkflow::class, function ($mock) {
            $mock->shouldReceive('assertApproved')->once()
                ->andThrow(ValidationException::withMessages(['daily_budget' => 'The researched proposal must be approved before funding.']));
        });
        $this->mock(AdSpendBillingService::class, function ($mock) {
            $mock->shouldNotReceive('initializeCreditAccount');
            $mock->shouldNotReceive('addCredit');
        });

        $this->actingAs($owner)->withSession(['active_customer_id' => $customer->id])
            ->postJson(route('billing.ad-spend.setup-for-deployment'), [
                'campaign_id' => $campaign->id, 'strategy_id' => $strategy->id,
                'daily_budget' => 20, 'payment_method_id' => 'pm_never_contact_stripe',
            ])->assertUnprocessable()->assertJson(['success' => false, 'requires_market_research' => true]);

        $this->assertNull($owner->fresh()->stripe_id);
        $this->assertNull($customer->adSpendCredit()->first());
    }
}
