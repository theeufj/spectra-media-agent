<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\RecordSiteConversion;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\SettleDeployedCampaign;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PausedDisplaySettlementTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(string $status = 'draft'): Campaign
    {
        $customer = Customer::factory()->create(['service_type' => 'managed']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => $status]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'display', 'deployment_status' => 'verified',
            'execution_result' => ['metadata' => ['display_initial_bidding' => [
                'campaign_resource' => 'customers/1234567890/campaigns/456',
                'verified_platform_status' => 'PAUSED', 'activation_ready' => false,
            ]]]]);
        $writer = $this->createMock(UpdateCampaignStatus::class);
        $writer->expects($this->never())->method('enable');
        $this->app->bind(UpdateCampaignStatus::class, fn () => $writer);

        return $campaign->load('customer');
    }

    public function test_verified_paused_display_build_does_not_become_a_live_campaign_or_conversion(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue(app(SettleDeployedCampaign::class)->settle($campaign));

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_retry_does_not_promote_a_paused_display_build_to_active(): void
    {
        $campaign = $this->campaign('paused');

        $this->assertFalse(app(SettleDeployedCampaign::class)->settle($campaign));
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_a_paused_display_addition_does_not_pause_or_reannounce_an_existing_live_campaign(): void
    {
        $campaign = $this->campaign('active');

        $this->assertFalse(app(SettleDeployedCampaign::class)->settle($campaign));
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }
}
