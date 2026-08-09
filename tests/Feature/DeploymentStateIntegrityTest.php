<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\MonitorCampaignStatus;
use App\Jobs\ReconcileStuckDeployments;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\DeploymentVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Two ways the platform's record of itself drifted from reality.
 *
 * 1. A strategy marked 'deploying' whose worker died before the agent returned
 *    stays 'deploying' forever: no catch block runs, VerifyDeployment only looks
 *    at 'deployed', and the idempotency guard refuses to redeploy anything still
 *    in flight. Two strategies sat like that for weeks, one since May.
 *
 * 2. MonitorCampaignStatus wrote platform_status and primary_status but never
 *    `status`, so a pause or resume made directly in the Google Ads UI never
 *    reached us (BILL-8).
 */
class DeploymentStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function stuckStrategy(string $stuckSince = '-2 hours'): Strategy
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/123/campaigns/456',
        ]);

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            'deployment_status' => 'deploying',
        ]);

        // Age the row past the stuck threshold. Written straight to the table:
        // updated_at is not fillable, so an update() would silently drop it and
        // the reconciler would never select the row.
        \DB::table('strategies')
            ->where('id', $strategy->id)
            ->update(['updated_at' => now()->modify($stuckSince)]);

        return $strategy->refresh();
    }

    private function runReconcilerWithVerdict(bool $exists, bool $supported = true): void
    {
        /** @var DeploymentVerifier&\Mockery\MockInterface $verifier */
        $verifier = Mockery::mock(DeploymentVerifier::class);
        $verifier->shouldReceive('supports')->andReturn($supported);
        $verifier->shouldReceive('verify')->andReturn($exists);

        (new ReconcileStuckDeployments)->handle($verifier);
    }

    public function test_stuck_deployment_whose_objects_exist_is_marked_deployed(): void
    {
        $strategy = $this->stuckStrategy();

        $this->runReconcilerWithVerdict(true);

        // The platform is the source of truth: the objects are live, so the
        // deployment succeeded regardless of what our row claimed.
        $this->assertSame('deployed', $strategy->fresh()->deployment_status);
        $this->assertNotNull($strategy->fresh()->deployed_at);
    }

    public function test_stuck_deployment_that_cannot_be_verified_is_marked_failed(): void
    {
        $strategy = $this->stuckStrategy();

        $this->runReconcilerWithVerdict(false);

        // 'failed' rather than 'deploying' matters: only a terminal state clears
        // the idempotency guard and lets the campaign be redeployed.
        $this->assertSame('failed', $strategy->fresh()->deployment_status);
        $this->assertNotNull($strategy->fresh()->deployment_error);
    }

    public function test_an_unverifiable_platform_is_not_declared_failed(): void
    {
        // Microsoft and LinkedIn have no verification path. Marking them failed
        // would assert something we never checked — the deployment may well have
        // reached the platform.
        $strategy = $this->stuckStrategy();

        $this->runReconcilerWithVerdict(false, supported: false);

        $this->assertSame('deploy_unverified', $strategy->fresh()->deployment_status);
        $this->assertStringContainsString('unknown', $strategy->fresh()->deployment_error);
    }

    public function test_a_deployment_still_in_flight_is_left_alone(): void
    {
        $strategy = $this->stuckStrategy('-5 minutes');

        $this->runReconcilerWithVerdict(false);

        $this->assertSame('deploying', $strategy->fresh()->deployment_status);
    }

    public function test_reconciled_strategies_count_as_deployed_for_ab_testing(): void
    {
        $strategy = $this->stuckStrategy();
        $this->runReconcilerWithVerdict(true);

        $this->assertTrue($strategy->fresh()->isDeployed());
    }

    public function test_platform_pause_is_reflected_in_local_status(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->syncStatus($campaign, 'PAUSED');

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
    }

    public function test_platform_resume_is_reflected_in_local_status(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Paused]);

        $this->syncStatus($campaign, 'ENABLED');

        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_unknown_platform_status_never_overwrites_local_status(): void
    {
        // An API hiccup returning UNKNOWN must not be read as "campaign stopped".
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->syncStatus($campaign, 'UNKNOWN');

        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_a_draft_is_never_flipped_live_by_the_platform(): void
    {
        // A draft carrying a platform id is a data problem to look at, not one to
        // hide by silently marking the campaign active.
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

        $this->syncStatus($campaign, 'ENABLED');

        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
    }

    /** Invoke the private reconciler directly — it is the unit under test. */
    private function syncStatus(Campaign $campaign, string $platformStatus): void
    {
        $job = new MonitorCampaignStatus;
        $method = new \ReflectionMethod($job, 'syncLifecycleStatus');
        $method->invoke($job, $campaign, $platformStatus);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
