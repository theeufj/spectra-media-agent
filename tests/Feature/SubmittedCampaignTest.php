<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\Deployment\SubmittedCampaign;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmittedCampaignTest extends TestCase
{
    use DatabaseTransactions;

    public function test_restarted_worker_recovers_the_original_empty_campaign_without_creating_it_again(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->for($customer)->create(['google_ads_campaign_id' => null]);
        $id = (string) Str::uuid();
        $intent = ['version' => 2, 'id' => $id, 'funding' => ['account' => '1234567890'], 'started_at' => now()->toISOString()];
        $strategy = Strategy::factory()->for($campaign)->create(['launch_intent' => $intent, 'platform' => 'Google Ads', 'deployment_status' => 'queued']);
        $this->assertSame("STS {$id} Advertiser", SubmittedCampaign::name($strategy, 'google', '1234567890', 'Advertiser'));
        try {
            SubmittedCampaign::name($strategy->fresh(), 'google', '1234567890', 'retry');
            $this->fail('A second create was permitted after a lost platform response.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reconciled', $exception->getMessage());
        }
        $resource = 'customers/1234567890/campaigns/481';
        $provider = $this->createMock(ReadCampaignConfiguration::class);
        $provider->expects($this->exactly(2))->method('findEmptyPausedLaunch')->with('1234567890', $id)->willReturn(null, $resource);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $provider);
        $this->travel(36)->minutes();
        app(SubmittedCampaign::class)->reconcile($strategy->fresh());
        $this->assertNull($strategy->fresh()->google_ads_campaign_id);
        $this->assertSame('queued', $strategy->fresh()->deployment_status);
        app(SubmittedCampaign::class)->reconcile($strategy->fresh());
        $this->assertSame($resource, $strategy->fresh()->reusableGoogleCampaignId());
        $this->assertSame($resource, $campaign->fresh()->google_ads_campaign_id);
        $this->assertSame(CampaignLaunchIntent::WAITING, $strategy->fresh()->deployment_status);
        $this->assertSame($id, $strategy->fresh()->launch_intent['id']);
        $this->assertArrayNotHasKey('started_at', $strategy->fresh()->launch_intent);
    }

    public function test_a_lost_meta_reply_recovers_its_only_paused_external_id_once(): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_61234']);
        $campaign = Campaign::factory()->for($customer)->create(['facebook_ads_campaign_id' => null]);
        $id = (string) Str::uuid();
        $leg = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads', 'deployment_status' => 'queued',
            'launch_intent' => ['version' => 2, 'id' => $id, 'funding' => ['account' => '61234'], 'started_at' => now()->toISOString()]]);
        $name = SubmittedCampaign::name($leg, 'facebook', 'act_61234', 'Test signup');
        config(['services.facebook.system_user_token' => 'fixture-no-network']);
        \Illuminate\Support\Facades\Http::fake([
            '*/act_61234/campaigns*' => \Illuminate\Support\Facades\Http::response(['data' => [['id' => '771', 'name' => $name, 'status' => 'PAUSED', 'account_id' => '61234']]]),
            '*/771/adsets*' => \Illuminate\Support\Facades\Http::response(['data' => []]),
        ]);
        $this->travel(36)->minutes();
        app(SubmittedCampaign::class)->reconcile($leg->fresh());
        $this->assertSame('771', $leg->fresh()->facebook_campaign_id);
        $this->assertSame('771', $campaign->fresh()->facebook_ads_campaign_id);
        $this->assertSame(CampaignLaunchIntent::WAITING, $leg->fresh()->deployment_status);
        $this->assertSame(1, $leg->fresh()->launch_intent['empty_campaign_resumes']);
        try {
            SubmittedCampaign::name($leg->fresh(), 'facebook', '61234', 'Duplicate');
            $this->fail('A retried worker was allowed to create a second Meta campaign.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('reconciled', $failure->getMessage());
        }
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
