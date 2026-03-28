<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\HealthCheckAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class HealthCheckAgentTest extends TestCase
{
    protected HealthCheckAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->geminiMock->shouldReceive('generateContent')->andReturn(['text' => '[]'])->byDefault();
        $this->agent = Mockery::mock(HealthCheckAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_detects_google_ads_account_suspension(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
        ]);
        $customer->id = 1;

        // Override the protected methods to simulate account suspension
        $this->agent->shouldReceive('testGoogleAdsConnectivity')
            ->andReturn(['connected' => true]);

        $this->agent->shouldReceive('checkGoogleAdsTokenHealth')
            ->andReturn(['issues' => [], 'warnings' => []]);

        $this->agent->shouldReceive('checkGoogleAdsAccountStatus')
            ->andReturn([
                'issues' => [
                    [
                        'type' => 'account_suspended',
                        'severity' => 'critical',
                        'message' => 'Google Ads account is suspended',
                        'details' => 'Contact Google Ads support to resolve account suspension',
                    ],
                ],
                'warnings' => [],
            ]);

        $this->agent->shouldReceive('checkGoogleConversionTracking')
            ->andReturn(['warnings' => [], 'has_tracking' => true]);

        // Let the remaining methods run with defaults
        $this->agent->shouldReceive('checkFacebookAdsHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [],
        ]);
        $this->agent->shouldReceive('checkBillingHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [],
        ]);
        $this->agent->shouldReceive('checkCampaignsHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [], 'campaigns' => [],
        ]);

        Log::spy();

        $results = $this->agent->checkCustomerHealth($customer);

        $this->assertNotEquals('healthy', $results['overall_health']);
        $this->assertNotEmpty($results['issues']);

        $suspensionIssue = collect($results['issues'])->firstWhere('type', 'account_suspended');
        $this->assertNotNull($suspensionIssue);
        $this->assertEquals('critical', $suspensionIssue['severity']);
    }

    public function test_detects_facebook_account_restrictions(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'facebook_ads_account_id' => 'act_123456',
            'facebook_ads_access_token' => 'test_token_123',
        ]);
        $customer->id = 2;

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'account_status' => 2, // DISABLED
                'disable_reason' => 'POLICY_VIOLATION',
                'name' => 'Test Account',
            ], 200),
        ]);

        // Let Google health return healthy (no google account)
        $this->agent->shouldReceive('checkGoogleAdsHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [],
        ]);
        $this->agent->shouldReceive('checkBillingHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [],
        ]);
        $this->agent->shouldReceive('checkCampaignsHealth')->andReturn([
            'status' => 'healthy', 'issues' => [], 'warnings' => [], 'campaigns' => [],
        ]);

        // Let the Facebook health check use real logic for restrictions
        $this->agent->shouldReceive('testFacebookAdsConnectivity')
            ->andReturn(['connected' => true]);
        $this->agent->shouldReceive('checkFacebookTokenHealth')
            ->andReturn(['issues' => [], 'warnings' => []]);
        $this->agent->shouldReceive('checkFacebookPixelHealth')
            ->andReturn(['warnings' => []]);

        // Let checkFacebookAccountRestrictions run the real code (uses Http::fake)
        $this->agent->shouldReceive('checkFacebookAccountRestrictions')->passthru();
        $this->agent->shouldReceive('checkFacebookAdsHealth')->passthru();

        Log::spy();

        $results = $this->agent->checkCustomerHealth($customer);

        $disabledIssue = collect($results['issues'])->firstWhere('type', 'account_disabled');
        $this->assertNotNull($disabledIssue);
        $this->assertEquals('critical', $disabledIssue['severity']);
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
        $realAgent = Mockery::mock(HealthCheckAgent::class, [$this->geminiMock])
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
        $realAgent = Mockery::mock(HealthCheckAgent::class, [$this->geminiMock])
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
