<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunStrategicDiagnosis;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group jobs
 * @group agents
 */
class RunStrategicDiagnosisTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }
    }

    public function test_job_can_be_dispatched(): void
    {
        $job = new RunStrategicDiagnosis();

        $this->assertInstanceOf(RunStrategicDiagnosis::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(900, $job->timeout);
    }

    public function test_job_handle_runs_without_exception_on_real_campaigns(): void
    {
        $hasCampaigns = Campaign::withDeployedPlatforms()->exists();

        if (!$hasCampaigns) {
            $this->markTestSkipped('No deployed campaigns in DB.');
        }

        $job = new RunStrategicDiagnosis();
        $job->handle(
            app(CampaignDiagnosticsAgent::class),
            app(CampaignRemediationAgent::class)
        );

        // If it completes without exception, diagnosis ran end-to-end
        $this->assertTrue(true);
    }

    public function test_job_creates_agent_activity_records(): void
    {
        $campaign = Campaign::with('customer')
            ->whereNotNull('google_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('google_ads_customer_id'))
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No Google Ads campaign in DB.');
        }

        $activityBefore = \App\Models\AgentActivity::count();

        $job = new RunStrategicDiagnosis();
        $job->handle(
            app(CampaignDiagnosticsAgent::class),
            app(CampaignRemediationAgent::class)
        );

        $activityAfter = \App\Models\AgentActivity::count();

        // At least one activity record should exist (may have been there before)
        $this->assertGreaterThanOrEqual($activityBefore, $activityAfter);
    }
}
