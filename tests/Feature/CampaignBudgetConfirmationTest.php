<?php

namespace Tests\Feature;

use App\Jobs\GenerateStrategy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\KnowledgeBase;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Agents\ExecutionBudgetGuard;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\Deployment\StrategyBudgetAllocator;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignBudgetConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        // These regressions isolate allocation reconciliation. Provider-backed
        // approval enforcement is exercised by CampaignMarketResearchWorkflowTest.
        $workflow = $this->createMock(CampaignMarketResearchWorkflow::class);
        $workflow->method('proposalForApproval')->willReturn([]);
        $workflow->method('request')->willReturn([]);
        $this->app->instance(CampaignMarketResearchWorkflow::class, $workflow);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $customer = Customer::factory()->create(['is_sandbox' => true]);
        $customer->users()->attach($this->owner, ['role' => 'owner']);
        $this->campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'daily_budget' => '50.00',
            'approved_daily_budget' => null,
            'total_budget' => '350.00',
            'auto_generated_at' => now(),
            'budget_confirmed_at' => null,
        ]);
        $this->actingAs($this->owner)->withSession(['active_customer_id' => $customer->id]);
    }

    private function strategy(array $attributes = []): Strategy
    {
        return Strategy::factory()->create(array_merge([
            'campaign_id' => $this->campaign->id,
            'platform' => 'Google Ads',
            'daily_budget' => '50.00',
            'signed_off_at' => now(),
        ], $attributes));
    }

    private function confirm(string $daily = '5.00'): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => $daily]);
    }

    private function allocateAndAssertAuthorized(Strategy ...$selected): void
    {
        $this->campaign->refresh();
        (new StrategyBudgetAllocator)->allocate($this->campaign, collect(array_values($selected)));
        foreach ($selected as $strategy) {
            $result = (new ExecutionBudgetGuard)->validate(
                new ExecutionPlan([]),
                new ExecutionContext($strategy, $this->campaign, $this->campaign->customer),
                $strategy->platform,
            );
            $this->assertTrue($result->passed, $result->errorMessage());
        }
    }

    public function test_confirming_a_lower_budget_reallocates_the_suggestion_before_first_deployment(): void
    {
        $strategy = $this->strategy(['signed_off_at' => null]);

        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($strategy->fresh()->daily_budget);
        $strategy->update(['signed_off_at' => now()]);
        $this->allocateAndAssertAuthorized($strategy);

        $this->assertEquals('5.00', $strategy->fresh()->daily_budget);
        $this->assertEquals('5.00', $this->campaign->approved_daily_budget);
        $this->assertEquals('35.00', $this->campaign->total_budget);
    }

    public function test_reconfirming_the_same_amount_repairs_an_unbuilt_failed_strategy(): void
    {
        $this->campaign->update(['daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()]);
        $strategy = $this->strategy(['deployment_status' => 'failed']);

        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->allocateAndAssertAuthorized($strategy);

        $this->assertEquals('5.00', $strategy->fresh()->daily_budget);
    }

    public function test_multiple_platforms_share_only_the_new_approval(): void
    {
        $google = $this->strategy(['daily_budget' => '25.00']);
        $facebook = $this->strategy(['platform' => 'Facebook Ads', 'daily_budget' => '25.00']);

        $this->confirm('5.01')->assertRedirect()->assertSessionHasNoErrors();
        $this->allocateAndAssertAuthorized($facebook, $google);

        $this->assertEquals('2.51', $google->fresh()->daily_budget);
        $this->assertEquals('2.50', $facebook->fresh()->daily_budget);
        $this->assertEquals('5.01', $this->campaign->strategies()->sum('daily_budget'));
    }

    public function test_generation_finishing_after_budget_confirmation_uses_the_current_approval(): void
    {
        Notification::fake();
        EnabledPlatform::firstOrCreate(['name' => 'Google Ads'], ['slug' => 'google-ads'])->update(['is_enabled' => true]);
        $this->campaign->update(['platforms' => ['Google Ads']]);
        KnowledgeBase::factory()->create(['customer_id' => $this->campaign->customer_id, 'user_id' => $this->owner->id, 'source_type' => 'url']);
        $subjects = ['rain beads on fabric', 'organised pockets holding tools', 'cyclist lifting backpack', 'bag leaning beside doorway', 'zip fastening between fingers', 'woven strap material'];
        $candidates = array_map(fn ($i) => [
            'selling_idea' => ['Weather resistance', 'Useful organisation', 'Commuter portability', 'Everyday use', 'Easy fastening', 'Comfortable straps'][$i],
            'evidence' => 'Product specification supplied by the business.',
            'subject' => $subjects[$i], 'visual' => $subjects[$i].' photographed in natural light.',
            'visual_style' => ['detail_photo', 'still_life', 'editorial_photo'][$i % 3],
            'composition' => ['close_up', 'overhead', 'environmental'][$i % 3],
            'layout' => 'editorial', 'headline' => 'Made for your day', 'supporting_copy' => 'Explore the bag and its features.', 'cta' => 'Explore the bag',
        ], range(0, 5));
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateWithFunctionCalling')->willReturnCallback(function () use ($candidates) {
            // The job already read 50 into its prompt. Approval changes while
            // the model is working, before the response is saved.
            $this->confirm()->assertRedirect()->assertSessionHasNoErrors();

            return ['text' => json_encode(['strategies' => [[
                'platform' => 'Google Ads', 'daily_budget' => 50,
                'ad_copy_strategy' => 'Campaign copy grounded in the supplied product specifications.',
                'imagery_strategy' => 'Photograph the product details.',
                'creative_candidates' => $candidates,
                'video_strategy' => null, 'generate_video' => false,
                'bidding_strategy' => ['name' => 'MaximizeClicks'], 'revenue_cpa_multiple' => 2,
            ]]])];
        });

        $this->app->instance(GeminiService::class, $gemini);
        (new GenerateStrategy($this->campaign->fresh()))->handle();

        $strategy = $this->campaign->strategies()->sole();
        $this->assertEquals('5.00', $strategy->daily_budget);
        $strategy->update(['signed_off_at' => now()]);
        $this->allocateAndAssertAuthorized($strategy);
        $this->assertEquals('5.00', $this->campaign->approved_daily_budget);
    }

    public function test_fitting_custom_allocations_survive_budget_confirmation_and_retries(): void
    {
        $google = $this->strategy(['daily_budget' => '2.00']);
        $facebook = $this->strategy(['platform' => 'Facebook Ads', 'daily_budget' => '3.00']);

        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->allocateAndAssertAuthorized($google, $facebook);

        $this->assertEquals('2.00', $google->fresh()->daily_budget);
        $this->assertEquals('3.00', $facebook->fresh()->daily_budget);
    }

    public function test_an_unsigned_suggestion_does_not_replace_a_fitting_custom_approved_allocation(): void
    {
        $approved = $this->strategy(['daily_budget' => '3.00']);
        $draft = $this->strategy(['platform' => 'Facebook Ads', 'signed_off_at' => null]);

        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('3.00', $approved->fresh()->daily_budget);
        $this->assertNull($draft->fresh()->daily_budget);
        $draft->update(['signed_off_at' => now()]);
        $this->allocateAndAssertAuthorized($draft);
        $this->assertEquals('2.00', $draft->fresh()->daily_budget);
    }

    public function test_existing_platform_allocations_are_reserved_before_reallocating_unbuilt_drafts(): void
    {
        $live = $this->strategy([
            'daily_budget' => '3.00',
            'deployment_status' => 'failed',
            'execution_result' => ['platform_ids' => ['campaign_id' => 'existing-platform-campaign']],
        ]);
        $draft = $this->strategy(['platform' => 'Microsoft Ads']);

        $this->confirm()->assertRedirect()->assertSessionHasNoErrors();
        $this->allocateAndAssertAuthorized($draft);

        $this->assertEquals('3.00', $live->fresh()->daily_budget);
        $this->assertEquals('2.00', $draft->fresh()->daily_budget);
    }

    public static function protectedStrategies(): array
    {
        return [
            'active' => [['deployment_status' => 'active']],
            'previously deployed' => [['deployment_status' => 'failed', 'deployed_at' => '2026-01-01 00:00:00']],
            'platform ID' => [['deployment_status' => 'failed', 'execution_result' => ['platform_ids' => ['campaign_id' => '123']]]],
            'queued' => [['deployment_status' => 'queued']],
            'deploying' => [['deployment_status' => 'deploying']],
        ];
    }

    #[DataProvider('protectedStrategies')]
    public function test_unsafe_budget_confirmation_preserves_the_campaign_and_strategy_atomically(array $attributes): void
    {
        $this->campaign->update(['approved_daily_budget' => '50.00', 'budget_confirmed_at' => now()->subDay()]);
        $before = $this->campaign->fresh()->getAttributes();
        $strategy = $this->strategy($attributes);

        $this->confirm()->assertRedirect()->assertSessionHasErrors('daily_budget');

        $this->assertSame($before, $this->campaign->fresh()->getAttributes());
        $this->assertEquals('50.00', $strategy->fresh()->daily_budget);
    }

    public function test_an_unknown_existing_platform_allocation_cannot_be_replaced_with_an_invented_budget(): void
    {
        $strategy = $this->strategy(['daily_budget' => null, 'deployed_at' => now()]);

        $this->confirm()->assertRedirect()->assertSessionHasErrors('daily_budget');

        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertNull($strategy->fresh()->daily_budget);
    }

    public function test_a_legacy_campaign_google_id_is_still_a_protected_platform_footprint(): void
    {
        $this->campaign->forceFill(['google_ads_campaign_id' => 'customers/123/campaigns/456'])->save();
        $strategy = $this->strategy(['deployment_status' => 'failed']);

        $this->confirm()->assertRedirect()->assertSessionHasErrors('daily_budget');

        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertEquals('50.00', $strategy->fresh()->daily_budget);
    }

    public function test_another_customer_cannot_change_or_reallocate_the_campaign_budget(): void
    {
        $strategy = $this->strategy();

        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '5.00'])
            ->assertNotFound();

        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertEquals('50.00', $strategy->fresh()->daily_budget);
    }
}
