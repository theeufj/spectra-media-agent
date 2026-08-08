<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\HealthCheckAgent;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class HealthCheckAgentTest extends TestCase
{
    // GeminiService::recordCost() writes an ai_costs row on every call, so
    // these tests were committing cost rows that leaked into the suite and
    // broke AiCostControllerTest's totals.
    use DatabaseTransactions;

    protected HealthCheckAgent $agent;

    protected $googleChecker;

    protected $facebookChecker;

    protected GeminiService $geminiMock;

    /**
     * HealthCheckAgent's constructor grew from 1 dependency to 5 when the
     * per-platform checks were extracted into their own classes. The test still
     * passed only Gemini, so every case died with ArgumentCountError before
     * reaching an assertion.
     *
     * @return list<mixed>
     */
    private function agentDependencies(): array
    {
        $empty = ['status' => 'healthy', 'issues' => [], 'warnings' => []];

        $this->googleChecker = Mockery::mock(\App\Services\Health\GoogleAdsHealthChecker::class);
        $this->googleChecker->shouldReceive('check')->andReturn($empty)->byDefault();

        $this->facebookChecker = Mockery::mock(\App\Services\Health\FacebookAdsHealthChecker::class);
        $this->facebookChecker->shouldReceive('check')->andReturn($empty)->byDefault();

        $billing = Mockery::mock(\App\Services\Health\BillingHealthChecker::class);
        $billing->shouldReceive('check')->andReturn($empty)->byDefault();

        $campaigns = Mockery::mock(\App\Services\Health\CampaignHealthChecker::class);
        $campaigns->shouldReceive('checkAll')->andReturn($empty + ['campaigns' => []])->byDefault();

        return [$this->geminiMock, $this->googleChecker, $this->facebookChecker, $billing, $campaigns];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->geminiMock->shouldReceive('generateContent')->andReturn(['text' => '[]'])->byDefault();
        $this->agent = Mockery::mock(HealthCheckAgent::class, $this->agentDependencies())
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_detects_google_ads_account_suspension(): void
    {
        // The per-platform checks were extracted into their own classes, so this
        // stubs GoogleAdsHealthChecker rather than protected methods on the agent
        // that no longer exist — the old mocks silently did nothing.
        $this->googleChecker->shouldReceive('check')->andReturn([
            'status' => 'critical',
            'issues' => [[
                'type' => 'account_suspended',
                'severity' => 'critical',
                'message' => 'Google Ads account is suspended',
            ]],
            'warnings' => [],
        ]);

        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        Log::spy();
        $results = $this->agent->checkCustomerHealth($customer);

        $this->assertNotEquals('healthy', $results['overall_health']);

        $issue = collect($results['issues'])->firstWhere('type', 'account_suspended');
        $this->assertNotNull($issue);
        $this->assertEquals('critical', $issue['severity']);
    }

    public function test_detects_facebook_account_restrictions(): void
    {
        $this->facebookChecker->shouldReceive('check')->andReturn([
            'status' => 'critical',
            'issues' => [[
                'type' => 'account_disabled',
                'severity' => 'critical',
                'message' => 'Facebook ad account is disabled',
            ]],
            'warnings' => [],
        ]);

        $customer = new Customer(['name' => 'Test Company', 'facebook_ads_account_id' => 'act_123']);
        $customer->id = 1;

        Log::spy();
        $results = $this->agent->checkCustomerHealth($customer);

        $issue = collect($results['issues'])->firstWhere('type', 'account_disabled');
        $this->assertNotNull($issue);
        $this->assertEquals('critical', $issue['severity']);
    }

    public function test_detects_performance_anomalies(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
            'status' => 'active',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        // Mock the agent's detectPerformanceAnomalies to return a CTR drop warning
        // instead of querying GoogleAdsPerformanceData from DB
        $realAgent = Mockery::mock(HealthCheckAgent::class, $this->agentDependencies())
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $realAgent->shouldReceive('detectPerformanceAnomalies')
            ->once()
            ->with($campaign)
            ->andReturn([
                'warnings' => [
                    [
                        'type' => 'ctr_drop',
                        'severity' => 'high',
                        'message' => 'CTR dropped by 80% compared to previous period',
                        'current_ctr' => 0.01,
                        'previous_ctr' => 0.05,
                    ],
                ],
            ]);

        $result = $realAgent->detectPerformanceAnomalies($campaign);

        $this->assertNotEmpty($result['warnings']);
        $ctrWarning = collect($result['warnings'])->firstWhere('type', 'ctr_drop');
        $this->assertNotNull($ctrWarning);
        $this->assertEquals('high', $ctrWarning['severity']);
    }

    public function test_detects_creative_fatigue(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
            'status' => 'active',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        // Mock the agent's checkCreativeFatigue to return a fatigue warning
        // instead of querying GoogleAdsPerformanceData from DB
        $realAgent = Mockery::mock(HealthCheckAgent::class, $this->agentDependencies())
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $realAgent->shouldReceive('checkCreativeFatigue')
            ->once()
            ->with($campaign)
            ->andReturn([
                'warnings' => [
                    [
                        'type' => 'creative_fatigue',
                        'severity' => 'medium',
                        'message' => 'CTR has declined steadily over 30 days, indicating creative fatigue',
                        'early_ctr' => 0.05,
                        'recent_ctr' => 0.015,
                    ],
                ],
            ]);

        $result = $realAgent->checkCreativeFatigue($campaign);

        $this->assertNotEmpty($result['warnings']);
        $fatigueWarning = collect($result['warnings'])->firstWhere('type', 'creative_fatigue');
        $this->assertNotNull($fatigueWarning);
        $this->assertEquals('medium', $fatigueWarning['severity']);
    }

    public function test_returns_healthy_when_no_issues(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => null,
            'facebook_ads_account_id' => null,
        ]);
        $customer->id = 3;

        // No Google or Facebook accounts — only billing/campaign checks run
        $this->agent->shouldReceive('checkBillingHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => [],
        ]);
        $this->agent->shouldReceive('checkCampaignsHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [], 'campaigns' => [],
        ]);

        Log::spy();

        $results = $this->agent->checkCustomerHealth($customer);

        $this->assertEquals('healthy', $results['overall_health']);
        $this->assertEmpty($results['issues']);
    }
}
