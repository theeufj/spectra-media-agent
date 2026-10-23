<?php

namespace Tests\Feature;

use App\Jobs\ConfigureSetupConversionGoal;
use App\Jobs\SetupConversionTracking;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\ConversionSetupService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\WebsiteConversionActions;
use App\Services\GTM\GTMContainerService;
use App\Services\Onboarding\SetupConversionGoal;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SetupConversionGoalTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private Campaign $campaign;

    private Strategy $strategy;

    private array $actions = [];

    private array $created = [];

    private array $tags = [];

    private ?int $remoteStatus = GoogleStatus::PAUSED;

    private ?\Closure $duringStatus = null;

    private int $retiredTags = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gtm.platform_account_id' => '111']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->customer = Customer::factory()->create(['service_type' => 'setup_only', 'setup_fee_paid_at' => now(),
            'website' => 'https://business.example', 'google_ads_customer_id' => '123',
            'conversion_action_id' => 'customers/123/conversionActions/1', 'gtm_container_id' => 'GTM-OWNED',
            'gtm_account_id' => '111', 'gtm_workspace_id' => '1', 'gtm_installed' => false,
            'conversion_tracking_verified_at' => null]);
        $this->customer->update(['gtm_config' => ['container_path' => 'accounts/111/containers/222', 'customer_uuid' => $this->customer->uuid]]);
        $this->customer->users()->attach($user, ['role' => 'owner']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'status' => 'paused',
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()]);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'daily_budget' => '5.00', 'conversion_goals' => ['primary_goal' => 'Lead'], 'deployment_status' => 'verified',
            'execution_result' => ['metadata' => ['google_search_baseline' => ['bidding' => 'MANUAL_CPC', 'budget_micros' => 5000000]]]]);
        $this->strategy->recordGoogleCampaignId('customers/123/campaigns/9');
        $this->actingAs($user)->withSession(['active_customer_id' => $this->customer->id]);
        $this->actions = [['resource' => 'customers/123/conversionActions/1', 'name' => 'Legacy signup', 'objective' => 'signup', 'primary_for_goal' => true],
            ['resource' => 'customers/123/conversionActions/2', 'name' => 'Existing lead', 'objective' => 'lead', 'primary_for_goal' => false]];
        $actions = $this->createMock(WebsiteConversionActions::class);
        $actions->method('all')->willReturnCallback(fn () => $this->actions);
        $actions->method('createFor')->willReturnCallback(function ($strategy, $objective, $authorize = null) {
            $authorize?->__invoke();
            $this->created[] = $objective;

            return ['resource' => 'customers/123/conversionActions/3', 'name' => 'Reviewed '.$objective, 'objective' => $objective, 'primary_for_goal' => false];
        });
        $this->app->bind(WebsiteConversionActions::class, fn () => $actions);
        $reader = $this->createMock(GetCampaignStatus::class);
        $reader->method('__invoke')->willReturnCallback(function () {
            if ($this->duringStatus) {
                ($this->duringStatus)();
            }

            return $this->remoteStatus === null ? null : ['status' => $this->remoteStatus];
        });
        $this->app->bind(GetCampaignStatus::class, fn () => $reader);
        $details = $this->createMock(GetConversionActionDetails::class);
        $details->method('__invoke')->willReturn(['conversion_id' => 'AW-123', 'conversion_label' => 'reviewed-label']);
        $this->app->bind(GetConversionActionDetails::class, fn () => $details);
        $gtm = $this->createMock(GTMContainerService::class);
        $gtm->method('configureReviewedConversionTag')->willReturnCallback(function ($customer, $uuid, $goal, $id, $label, $authorize = null) {
            $authorize?->__invoke();
            $this->tags[] = $goal;

            return ['success' => true];
        });
        $gtm->method('retireReviewedConversionTag')->willReturnCallback(function () {
            $this->retiredTags++;

            return ['success' => true];
        });
        $this->app->instance(GTMContainerService::class, $gtm);
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('reconcilePausedSetup')->willReturnCallback(function ($strategy) {
            $intent = ['mode' => 'custom', 'category' => $strategy->conversion_goals['primary_goal'],
                'action_resource' => $strategy->conversion_goals['action_resource'], 'origin' => 'WEBSITE'];
            $readiness = ['ready' => true, 'configuration_verified' => true, 'campaign_resource' => $strategy->reusableGoogleCampaignId(),
                'intent' => $intent, 'issues' => [], 'checked_at' => now()->toIso8601String()];
            $execution = $strategy->fresh()->execution_result;
            $execution['metadata']['conversion_goal_readiness'] = $readiness;
            $execution['metadata']['google_search_baseline']['conversion_goal'] = $intent;
            $execution['metadata']['google_search_baseline']['conversion_category'] = $intent['category'];
            $strategy->update(['execution_result' => $execution]);

            return $readiness;
        });
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
    }

    private function goal(array $extra = []): array
    {
        return $extra + ['objective' => 'lead', 'trigger' => 'thank_you_url', 'thank_you_url' => 'https://business.example/lead/complete', 'strategy' => $this->strategy->uuid];
    }

    private function submit(array $extra = []): ConfigureSetupConversionGoal
    {
        $this->post(route('campaigns.conversion-goal.update', $this->campaign), $this->goal($extra))->assertSessionHasNoErrors()->assertRedirect();

        $job = null;
        Queue::assertPushed(ConfigureSetupConversionGoal::class, function (ConfigureSetupConversionGoal $queued) use (&$job) {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(ConfigureSetupConversionGoal::class, $job);

        return $job;
    }

    public function test_customer_can_configure_a_real_lead_goal_without_reclassifying_legacy_signup_or_claiming_tracking_verified(): void
    {
        $before = $this->actions;
        $job = $this->submit();
        $job->handle(app(SetupConversionGoal::class));
        $state = app(SetupConversionGoal::class)->status($this->campaign);

        $this->assertSame(['lead'], $this->created);
        $this->assertSame($before, $this->actions);
        $this->assertSame('configured', $state['configuration']['status']);
        $this->assertCount(1, $this->tags);
        $this->assertFalse($state['installation']['tag_detected']);
        $this->assertFalse($state['conversionVerification']['verified']);
        $this->assertNull($this->customer->fresh()->conversion_tracking_verified_at);
        $this->assertSame('customers/123/conversionActions/1', $this->customer->fresh()->conversion_action_id);
        $this->assertSame('SUBMIT_LEAD_FORM', $this->strategy->fresh()->conversion_goals['primary_goal']);
        $this->assertSame('MANUAL_CPC', $this->strategy->fresh()->execution_result['metadata']['google_search_baseline']['bidding']);
        $this->assertSame('paused', $this->campaign->fresh()->status->value);
        $this->assertEquals('5.00', $this->campaign->fresh()->approved_daily_budget);
        Queue::assertPushed(\App\Jobs\CheckGoogleCampaignReadiness::class, fn ($job) => $job->campaignId === $this->campaign->id);
    }

    public function test_existing_matching_action_can_be_selected_without_creating_another_action(): void
    {
        $job = $this->submit(['trigger' => 'existing_action', 'action_resource' => 'customers/123/conversionActions/2']);
        $job->handle(app(SetupConversionGoal::class));

        $this->assertSame([], $this->created);
        $this->assertSame([], $this->tags);
        $this->assertSame('customers/123/conversionActions/2', $this->strategy->fresh()->conversion_goals['action_resource']);
        $this->assertSame('configured', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
    }

    public function test_paid_purchase_goal_can_be_prepared_without_inventing_a_website_event_or_tracking_verification(): void
    {
        $this->submit(['objective' => 'purchase', 'trigger' => 'prepare_only'])->handle(app(SetupConversionGoal::class));

        $state = app(SetupConversionGoal::class)->status($this->campaign);
        $this->assertSame(['purchase'], $this->created);
        $this->assertSame([], $this->tags);
        $this->assertSame(0, $this->retiredTags);
        $this->assertSame('configured', $state['configuration']['status']);
        $this->assertSame('prepare_only', $state['configuration']['goal']['trigger']);
        $this->assertSame('PURCHASE', $this->strategy->fresh()->conversion_goals['primary_goal']);
        $this->assertFalse($state['conversionVerification']['verified']);
        $this->assertNull($this->customer->fresh()->conversion_tracking_verified_at);
        $this->assertSame('paused', $this->campaign->fresh()->status->value);
        $this->assertSame('MANUAL_CPC', $this->strategy->fresh()->execution_result['metadata']['google_search_baseline']['bidding']);
    }

    public function test_prepare_only_replaces_a_tracked_choice_by_retiring_its_owned_tag_without_adding_a_bogus_trigger(): void
    {
        $this->submit()->handle(app(SetupConversionGoal::class));
        $this->assertTrue($this->strategy->fresh()->execution_result['metadata']['setup_conversion_goal']['managed_tag_may_exist']);

        $this->submit(['objective' => 'purchase', 'trigger' => 'prepare_only'])->handle(app(SetupConversionGoal::class));

        $this->assertCount(1, $this->tags);
        $this->assertSame(1, $this->retiredTags);
        $this->assertFalse($this->strategy->fresh()->execution_result['metadata']['setup_conversion_goal']['managed_tag_may_exist']);
        $this->assertSame('configured', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        $this->assertFalse(app(SetupConversionGoal::class)->status($this->campaign)['conversionVerification']['verified']);
    }

    public function test_selecting_the_same_managed_action_as_an_existing_action_preserves_its_working_tag(): void
    {
        $this->submit()->handle(app(SetupConversionGoal::class));
        $this->actions[] = ['resource' => 'customers/123/conversionActions/3', 'name' => 'Reviewed lead', 'objective' => 'lead', 'primary_for_goal' => false];

        $this->submit(['trigger' => 'existing_action', 'action_resource' => 'customers/123/conversionActions/3'])->handle(app(SetupConversionGoal::class));

        $this->assertCount(1, $this->tags);
        $this->assertSame(0, $this->retiredTags);
        $this->assertTrue($this->strategy->fresh()->execution_result['metadata']['setup_conversion_goal']['managed_tag_may_exist']);
        $this->assertSame('configured', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
    }

    public function test_website_or_container_changes_during_authorization_status_read_are_caught_before_the_next_mutation(): void
    {
        foreach (['website' => 'https://new-business.example', 'gtm_container_id' => 'GTM-CHANGED'] as $field => $value) {
            $this->customer->refresh();
            $checks = 0;
            $this->duringStatus = function () use (&$checks, $field, $value) {
                if (++$checks === 2) {
                    Customer::whereKey($this->customer->id)->update([$field => $value]);
                }
            };
            $goal = $field === 'gtm_container_id' ? ['thank_you_url' => 'https://new-business.example/lead/complete'] : [];
            $this->submit($goal)->handle(app(SetupConversionGoal::class));
            $this->assertSame([], $this->created);
            $this->assertSame([], $this->tags);
            $this->assertSame('needs_review', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        }
    }

    public function test_wrong_category_and_enabled_or_unknown_campaigns_are_held_without_remote_writes(): void
    {
        $job = $this->submit(['trigger' => 'existing_action', 'action_resource' => 'customers/123/conversionActions/1']);
        $job->handle(app(SetupConversionGoal::class));
        $this->assertSame('needs_review', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        foreach ([GoogleStatus::ENABLED, null] as $status) {
            $this->remoteStatus = $status;
            $this->submit()->handle(app(SetupConversionGoal::class));
            $this->assertSame('needs_review', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        }
        $this->assertSame([], $this->created);
        $this->assertSame([], $this->tags);
    }

    public function test_foreign_actions_homepage_and_generic_events_are_rejected_before_queueing(): void
    {
        foreach ([['trigger' => 'existing_action', 'action_resource' => 'customers/999/conversionActions/2'],
            ['thank_you_url' => 'https://business.example/'], ['thank_you_url' => 'https://another.example/thanks'],
            ['trigger' => 'event', 'event_name' => 'page_view']] as $extra) {
            $this->post(route('campaigns.conversion-goal.update', $this->campaign), $this->goal($extra))->assertSessionHasErrors();
        }
        Queue::assertNotPushed(ConfigureSetupConversionGoal::class);
    }

    public function test_stale_jobs_cannot_apply_a_superseded_goal_and_terminal_failure_cannot_hide_a_new_request(): void
    {
        $old = $this->submit();
        $new = $this->submit(['objective' => 'signup', 'trigger' => 'event', 'event_name' => 'registration_complete']);
        $old->handle(app(SetupConversionGoal::class));
        $old->failed(new \RuntimeException('Old request stopped'));
        $this->assertSame([], $this->created);
        $this->assertSame($new->requestId, app(SetupConversionGoal::class)->status($this->campaign)['configuration']['request_id']);
        $this->assertSame('configuring', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        $new->failed(new \RuntimeException('Worker timeout'));
        $this->assertSame('unknown', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
    }

    public function test_prebuild_goal_is_saved_but_not_falsely_marked_as_verified_campaign_configuration(): void
    {
        $this->strategy->forceFill(['google_ads_campaign_id' => null, 'deployment_status' => null])->save();
        $this->campaign->forceFill(['google_ads_campaign_id' => null])->save();
        $this->submit()->handle(app(SetupConversionGoal::class));

        $this->assertSame('waiting_for_campaign', app(SetupConversionGoal::class)->status($this->campaign)['configuration']['status']);
        $this->assertSame('customers/123/conversionActions/3', $this->strategy->fresh()->conversion_goals['action_resource']);
        $this->assertCount(1, $this->tags);
    }

    public function test_another_tenant_cannot_open_configure_or_poll_this_campaign(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get(route('campaigns.conversion-goal.edit', $this->campaign))->assertNotFound();
        $this->getJson(route('campaigns.conversion-goal.status', $this->campaign))->assertNotFound();
        $this->post(route('campaigns.conversion-goal.update', $this->campaign), $this->goal())->assertNotFound();
    }

    public function test_account_provisioning_waits_for_explicit_goal_and_resumes_a_saved_request_without_generic_signup(): void
    {
        $legacy = $this->createMock(ConversionSetupService::class);
        $legacy->expects($this->never())->method('setup');
        (new SetupConversionTracking($this->customer))->handle($legacy);
        Queue::assertNotPushed(ConfigureSetupConversionGoal::class);
        $job = $this->submit();
        Queue::fake();
        (new SetupConversionTracking($this->customer))->handle($legacy);
        Queue::assertPushed(ConfigureSetupConversionGoal::class, fn ($queued) => $queued->requestId === $job->requestId);
    }
}
