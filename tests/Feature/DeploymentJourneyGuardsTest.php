<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\DeployCampaign;
use App\Jobs\VerifyDeployment;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\DeploymentFailed;
use App\Services\Deployment\DeploymentVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The deployment journey's guard rails: no money taken for deploys that will
 * be refused, no unsigned strategies deployed, no silent double-deploys, and
 * verification that tells the truth per platform.
 */
class DeploymentJourneyGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private function subscribedOwner(array $customerAttrs = []): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $customer = Customer::factory()->create($customerAttrs);
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    public function test_funding_is_refused_before_the_budget_is_confirmed(): void
    {
        [$user, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'auto_generated_at' => now(),
            'budget_confirmed_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('billing.ad-spend.setup-for-deployment'), [
            'daily_budget' => 20,
            'days_to_charge' => 7,
            'campaign_id' => $campaign->id,
        ]);

        $response->assertStatus(422)->assertJson(['requires_budget_confirmation' => true]);
        $this->assertNull($customer->fresh()->adSpendCredit);
    }

    public function test_self_funded_customers_are_never_charged_prepay(): void
    {
        // google_ads_link_status active === the customer's own account, their
        // own card, billed by Google directly.
        [$user] = $this->subscribedOwner(['google_ads_link_status' => 'active']);

        $response = $this->actingAs($user)->postJson(route('billing.ad-spend.setup-for-deployment'), [
            'daily_budget' => 20,
            'days_to_charge' => 7,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('billed directly', $response->json('error'));
    }

    public function test_only_signed_off_strategies_are_selected_for_deployment(): void
    {
        [, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'daily_budget' => 40]);
        $approved = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
        ]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Facebook Ads',
            'signed_off_at' => null,
        ]);

        // Same expression the job uses to pick its work.
        $selected = $campaign->strategies->whereNotNull('signed_off_at');

        $this->assertCount(1, $selected);
        $this->assertTrue($selected->contains('id', $approved->id));
    }

    public function test_a_second_deploy_click_does_not_wipe_an_in_flight_deployment(): void
    {
        Queue::fake();
        [$user, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
            'deployment_status' => 'deploying',
        ]);

        $response = $this->actingAs($user)->post(route('deployment.deploy'), [
            'campaign_id' => $campaign->id,
        ]);

        $response->assertRedirect(route('campaigns.deployment-status', $campaign, absolute: false));
        // The in-flight status must survive, and no second job may dispatch.
        $this->assertSame('deploying', $strategy->fresh()->deployment_status);
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_reclicking_deploy_on_a_pending_admin_campaign_changes_nothing(): void
    {
        Queue::fake();
        Mail::fake();
        [$user, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::PendingAdminDeployment,
        ]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
            'deployment_status' => 'deployed',
        ]);

        $this->actingAs($user)->post(route('deployment.deploy'), [
            'campaign_id' => $campaign->id,
        ])->assertRedirect();

        $this->assertSame('deployed', $campaign->strategies()->first()->deployment_status);
        Mail::assertNothingSent();
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_a_broken_google_link_routes_to_the_admin_queue_not_the_api(): void
    {
        Queue::fake();
        Mail::fake();
        Notification::fake();
        [$user, $customer] = $this->subscribedOwner([
            'google_ads_customer_id' => '1234567890',
            'google_ads_link_status' => 'refused',
        ]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
        ]);

        $this->actingAs($user)->post(route('deployment.deploy'), [
            'campaign_id' => $campaign->id,
        ])->assertRedirect();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::PendingAdminDeployment, $campaign->status);
        $this->assertNotNull($campaign->pending_admin_deployment_at);
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_a_teammate_on_a_company_plan_passes_the_subscription_gate(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $teammate = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $customer = Customer::factory()->create();
        $owner->customers()->attach($customer->id, ['role' => 'owner']);
        $teammate->customers()->attach($customer->id, ['role' => 'member']);
        session(['active_customer_id' => $customer->id]);

        // A subscribed-gated page: previously the middleware bounced the
        // teammate to pricing before any teammate-aware check could run.
        $this->actingAs($teammate)->get('/billing/ad-spend')->assertOk();
    }

    public function test_verification_leaves_unverifiable_platforms_as_deployed(): void
    {
        [, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'LinkedIn Ads',
            'deployment_status' => 'deployed',
        ]);

        (new VerifyDeployment($campaign))->handle(new DeploymentVerifier);

        // No verification path exists for LinkedIn: "can't check" must not
        // become "unverified".
        $this->assertSame('deployed', $strategy->fresh()->deployment_status);
    }

    public function test_failed_verification_of_a_supported_platform_notifies_the_user(): void
    {
        Notification::fake();
        [$user, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'deployment_status' => 'deployed',
        ]);

        /** @var DeploymentVerifier&\Mockery\MockInterface $verifier */
        $verifier = Mockery::mock(DeploymentVerifier::class);
        $verifier->shouldReceive('supports')->andReturn(true);
        $verifier->shouldReceive('verify')->andReturn(false);

        (new VerifyDeployment($campaign))->handle($verifier);

        $this->assertSame('deploy_unverified', $campaign->strategies()->first()->deployment_status);
        Notification::assertSentTo($user, DeploymentFailed::class);
    }

    public function test_a_live_campaign_cannot_be_deleted(): void
    {
        [$user, $customer] = $this->subscribedOwner();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::Active,
        ]);

        $this->actingAs($user)
            ->from(route('campaigns.index'))
            ->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect(route('campaigns.index', absolute: false));

        $this->assertNotNull(Campaign::find($campaign->id));
    }
}
