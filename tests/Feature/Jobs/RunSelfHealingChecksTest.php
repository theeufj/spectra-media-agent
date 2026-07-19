<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunSelfHealingChecks;
use App\Models\Campaign;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Services\Agents\SelfHealingAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group jobs
 * @group agents
 */
class RunSelfHealingChecksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }
    }

    public function test_job_can_be_instantiated(): void
    {
        $job = new RunSelfHealingChecks();

        $this->assertInstanceOf(RunSelfHealingChecks::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(600, $job->timeout);
    }

    public function test_job_runs_without_exception_when_no_eligible_campaigns(): void
    {
        // With no campaigns in ELIGIBLE/LEARNING status the job should complete silently
        $eligibleExists = Campaign::whereIn('primary_status', ['ELIGIBLE', 'LEARNING'])
            ->where(fn ($q) => $q
                ->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id')
                ->orWhereNotNull('microsoft_ads_campaign_id')
                ->orWhereNotNull('linkedin_campaign_id')
            )->exists();

        if (!$eligibleExists) {
            $job = new RunSelfHealingChecks();
            $job->handle(
                app(SelfHealingAgent::class),
                app(CampaignDiagnosticsAgent::class),
                app(CampaignRemediationAgent::class),
                app(FacebookLearningPhaseAgent::class),
                app(FacebookAdRelevanceDiagnosticsAgent::class),
                app(LinkedInCampaignOptimizationAgent::class),
            );
            $this->assertTrue(true);
            return;
        }

        $this->markTestSkipped('Eligible campaigns exist — run test_handle_runs_against_live_campaigns instead.');
    }

    public function test_handle_runs_against_live_campaigns(): void
    {
        $eligibleExists = Campaign::whereIn('primary_status', ['ELIGIBLE', 'LEARNING'])
            ->where(fn ($q) => $q
                ->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id')
            )->exists();

        if (!$eligibleExists) {
            $this->markTestSkipped('No eligible/learning campaigns in DB.');
        }

        $job = new RunSelfHealingChecks();
        $job->handle(
            app(SelfHealingAgent::class),
            app(CampaignDiagnosticsAgent::class),
            app(CampaignRemediationAgent::class),
            app(FacebookLearningPhaseAgent::class),
            app(FacebookAdRelevanceDiagnosticsAgent::class),
            app(LinkedInCampaignOptimizationAgent::class),
        );

        $this->assertTrue(true);
    }
}
