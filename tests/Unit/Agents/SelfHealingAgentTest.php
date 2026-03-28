<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\FacebookAds\AdSetService as FacebookAdSetService;
use App\Services\FacebookAds\CreativeService as FacebookCreativeService;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SelfHealingAgentTest extends TestCase
{
    protected SelfHealingAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        config(['budget_rules.self_healing' => [
            'auto_heal_enabled' => true,
            'max_heals_per_day' => 10,
            'max_fix_attempts' => 3,
        ]]);

        $this->geminiMock = Mockery::mock(GeminiService::class);
    }

    public function test_heal_detects_and_regenerates_google_disapproved_ads(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(SelfHealingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock healGoogleDisapprovedAds to simulate finding and fixing a disapproved ad
        $agent->shouldReceive('healGoogleDisapprovedAds')
            ->once()
            ->andReturnUsing(function ($cust, $custId, $campaignResource, &$results) {
                $results['actions_taken'][] = [
                    'type' => 'google_ad_resubmitted',
                    'platform' => 'google_ads',
                    'original_ad' => 'customers/1234567890/ads/999',
                    'new_ad' => 'customers/1234567890/ads/1000',
                    'reason' => 'Misleading content',
                    'changes' => 'Ad copy modified for compliance',
                ];
            });

        $agent->shouldReceive('checkGoogleBudgetHealth')->once()->andReturnNull();
        $agent->shouldReceive('checkGoogleDeliveryHealth')->once()->andReturnNull();

        Log::spy();

        $results = $agent->heal($campaign);

        $this->assertEquals($campaign->id, $results['campaign_id']);
        $this->assertEquals('google_ads', $results['platform']);
        $this->assertNotEmpty($results['actions_taken']);
        $this->assertEquals('google_ad_resubmitted', $results['actions_taken'][0]['type']);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_heal_detects_facebook_disapproved_ads(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'facebook_ads_account_id' => 'act_123456',
            'facebook_ads_access_token' => 'test_token',
        ]);
        $customer->id = 2;

        $campaign = new Campaign([
            'customer_id' => 2,
            'facebook_ads_campaign_id' => 'fb_camp_111',
        ]);
        $campaign->id = 2;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(SelfHealingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('healFacebookDisapprovedAds')
            ->once()
            ->andReturnUsing(function ($camp, $cust, &$results) {
                $results['actions_taken'][] = [
                    'type' => 'facebook_ad_replaced',
                    'platform' => 'facebook_ads',
                    'original_ad' => 'fb_ad_999',
                    'new_ad' => 'fb_ad_1000',
                    'reason' => 'Policy violation',
                ];
            });

        $agent->shouldReceive('checkFacebookDeliveryHealth')->once()->andReturnNull();
        $agent->shouldReceive('checkFacebookCreativeHealth')->once()->andReturnNull();

        Log::spy();

        $results = $agent->heal($campaign);

        $this->assertEquals('facebook_ads', $results['platform']);
        $this->assertNotEmpty($results['actions_taken']);
        $this->assertEquals('facebook_ad_replaced', $results['actions_taken'][0]['type']);
    }

    public function test_heal_all_campaigns_processes_active_campaigns(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 3;

        $activeCampaign1 = new Campaign([
            'customer_id' => 3,
            'google_ads_campaign_id' => '111',
            'status' => 'active',
        ]);
        $activeCampaign1->id = 3;
        $activeCampaign1->setRelation('customer', $customer);

        $activeCampaign2 = new Campaign([
            'customer_id' => 3,
            'google_ads_campaign_id' => '222',
            'status' => 'active',
        ]);
        $activeCampaign2->id = 4;
        $activeCampaign2->setRelation('customer', $customer);

        // Mock the agent to return the active campaigns instead of querying DB
        $agent = Mockery::mock(SelfHealingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getActiveCampaigns')
            ->once()
            ->with($customer)
            ->andReturn(collect([$activeCampaign1, $activeCampaign2]));

        $agent->shouldReceive('heal')->twice()->andReturn([
            'campaign_id' => 0,
            'platform' => 'google_ads',
            'actions_taken' => [],
            'warnings' => [],
            'errors' => [],
        ]);

        Log::spy();

        $results = $agent->healAllCampaigns($customer);

        $this->assertEquals($customer->id, $results['customer_id']);
        $this->assertEquals(2, $results['campaigns_checked']);
        $this->assertCount(2, $results['results']);
    }

    public function test_heal_handles_errors_gracefully(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 4;

        $campaign = new Campaign([
            'customer_id' => 4,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 5;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(SelfHealingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('healGoogleDisapprovedAds')
            ->once()
            ->andReturnUsing(function ($cust, $custId, $campaignResource, &$results) {
                $results['errors'][] = 'Failed to check Google ad status: Connection timeout';
            });

        $agent->shouldReceive('checkGoogleBudgetHealth')->once()->andReturnNull();
        $agent->shouldReceive('checkGoogleDeliveryHealth')->once()->andReturnNull();

        Log::spy();

        $results = $agent->heal($campaign);

        $this->assertNotEmpty($results['errors']);
        $this->assertStringContainsString('Connection timeout', $results['errors'][0]);
    }

    public function test_heal_detects_not_serving_campaigns(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 5;

        $campaign = new Campaign([
            'customer_id' => 5,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 6;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(SelfHealingAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('healGoogleDisapprovedAds')->once()->andReturnNull();
        $agent->shouldReceive('checkGoogleBudgetHealth')->once()->andReturnNull();

        // Simulate delivery health check detecting not_serving
        $agent->shouldReceive('checkGoogleDeliveryHealth')
            ->once()
            ->andReturnUsing(function ($cust, $camp, $custId, $campaignResource, &$results) {
                $results['warnings'][] = [
                    'type' => 'not_serving',
                    'platform' => 'google_ads',
                    'message' => 'Campaign is not serving',
                    'severity' => 'high',
                ];
            });

        Log::spy();

        $results = $agent->heal($campaign);

        $this->assertNotEmpty($results['warnings']);
        $notServing = collect($results['warnings'])->firstWhere('type', 'not_serving');
        $this->assertNotNull($notServing);
        $this->assertEquals('high', $notServing['severity']);
    }
}
