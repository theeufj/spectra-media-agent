<?php

namespace Tests\Feature;

use App\Jobs\VerifyGoogleAdImprovement;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class GoogleAdImprovementVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_successfully_updated_but_still_poor_ad_is_not_called_an_improvement(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
        $resource = 'customers/123/adGroupAds/1~2';
        $reader = Mockery::mock(ReadCampaignConfiguration::class);
        $reader->shouldReceive('ads')->andReturn([['adGroupAd' => ['resourceName' => $resource,
            'adStrength' => 'POOR', 'ad' => ['responsiveSearchAd' => ['headlines' => [['text' => 'Google Ads Management']],
                'descriptions' => [['text' => 'Manage your campaigns.']]]]]]]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        (new VerifyGoogleAdImprovement($campaign, $resource, 4, ['Google Ads Management'], ['Manage your campaigns.']))->handle();
        $activity = AgentActivity::where('campaign_id', $campaign->id)->latest('id')->first();
        $this->assertSame('ad_copy_update_verified', $activity->action);
        $this->assertTrue($activity->details['copy_matches']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_strength_improved']);
    }

    public function test_changed_or_missing_copy_requires_review_even_if_strength_is_good(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
        $reader = Mockery::mock(ReadCampaignConfiguration::class);
        $reader->shouldReceive('ads')->andReturn([['adGroupAd' => ['resourceName' => 'customers/123/adGroupAds/1~2', 'adStrength' => 'GOOD', 'ad' => []]]]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        (new VerifyGoogleAdImprovement($campaign, 'customers/123/adGroupAds/1~2', 4, ['Expected headline'], ['Expected description']))->handle();
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'status' => 'needs_review']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_strength_improved']);
    }
}
