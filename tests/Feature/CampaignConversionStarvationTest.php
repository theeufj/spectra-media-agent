<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CampaignConversionStarvationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_meta_uses_attributed_signups_and_link_clicks_for_the_deployed_campaign(): void
    {
        $this->freezeTime();
        config(['services.facebook.system_user_token' => 'test-token']);
        $customer = Customer::factory()->create(['facebook_ads_account_id' => '111', 'google_ads_customer_id' => null]);
        $campaign = Campaign::factory()->for($customer)->create(['facebook_ads_campaign_id' => '222']);
        Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads', 'deployment_status' => 'active',
            'deployed_at' => now()->subDays(50)]);
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads', 'facebook_campaign_id' => '222',
            'deployment_status' => 'active', 'deployed_at' => now()->subDays(2), 'conversion_goals' => ['primary_goal' => 'Sign up']]);

        $actions = [['action_type' => 'purchase', 'value' => '1'], ['action_type' => 'link_click', 'value' => '5']];
        Http::fake(function (Request $request) use (&$actions) {
            $this->assertSame('GET', $request->method());
            if (str_contains($request->url(), '/222/insights')) {
                $this->assertContains('inline_link_clicks', explode(',', $request['fields']));

                return Http::response(['data' => [['impressions' => '944', 'clicks' => '9', 'inline_link_clicks' => '5',
                    'spend' => '110.95', 'actions' => $actions]]]);
            }

            return Http::response(['data' => []]);
        });
        $diagnostics = new CampaignDiagnosticsAgent;
        $this->assertNotContains('meta_conversion_starvation', array_column($diagnostics->diagnose($campaign->fresh()), 'type'),
            'The older Google strategy must not end Meta’s learning window.');

        $strategy->update(['deployed_at' => now()->subDays(14)]);
        $finding = collect($diagnostics->diagnose($campaign->fresh()))->firstWhere('type', 'meta_conversion_starvation');
        $this->assertNotNull($finding);
        $this->assertSame(5, $finding['details']['link_clicks']);
        $this->assertSame(0.0, $finding['details']['conversions']);
        $this->assertFalse($finding['can_auto_fix']);
        $this->assertNull($finding['auto_fix_action']);

        $actions[] = ['action_type' => 'offsite_conversion.fb_pixel_complete_registration', 'value' => '1'];
        $this->assertNotContains('meta_conversion_starvation', array_column($diagnostics->diagnose($campaign->fresh()), 'type'),
            'A real website signup must clear the finding.');
    }

    public function test_meta_repeat_exposure_requests_review_and_cannot_overwrite_approved_ads(): void
    {
        Notification::fake();
        config(['services.facebook.system_user_token' => 'test-token']);
        $customer = Customer::factory()->create(['facebook_ads_account_id' => '111', 'google_ads_customer_id' => null]);
        $customer->users()->attach(User::factory()->create()->id);
        $campaign = Campaign::factory()->for($customer)->create(['facebook_ads_campaign_id' => '222']);
        Http::fake(fn (Request $request) => Http::response(['data' => str_contains($request->url(), '/222/insights')
            ? array_fill(0, 7, ['impressions' => 2000, 'frequency' => 4, 'spend' => 25]) : []]));
        $finding = collect((new CampaignDiagnosticsAgent)->diagnose($campaign))->firstWhere('type', 'meta_audience_fatigue');
        $this->assertNotNull($finding);
        $this->assertFalse($finding['can_auto_fix']);
        $this->assertStringContainsString('approve', $finding['recommended_action']);

        // Even a finding created before the upgrade must respect creative approval.
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $results = (new CampaignRemediationAgent($gemini))->remediate($campaign, [
            [...$finding, 'can_auto_fix' => true, 'auto_fix_action' => 'refresh_meta_creative'],
        ]);
        $this->assertSame([], $results['actions_taken']);
        $this->assertSame(['meta_audience_fatigue'], array_column($results['alerts_sent'], 'type'));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_google_uses_the_age_of_the_matching_google_campaign(): void
    {
        $this->freezeTime();
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'facebook_ads_account_id' => null]);
        $campaign = Campaign::factory()->for($customer)->create(['status' => 'draft', 'google_ads_campaign_id' => '789']);
        Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads', 'google_ads_campaign_id' => 'customers/123/campaigns/456',
            'deployment_status' => 'active', 'deployed_at' => now()->subDays(30)]);
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads', 'google_ads_campaign_id' => 'customers/123/campaigns/789',
            'deployment_status' => 'active', 'deployed_at' => now()->subDays(3)]);
        $this->mock(ReconcileCampaignConversionGoals::class)->shouldReceive('inspect')->andReturn(['ready' => true]);
        $diagnostics = new class extends CampaignDiagnosticsAgent
        {
            protected function fetchGooglePerformance(Campaign $campaign): array
            {
                return ['channel_type' => 2, 'spend' => 100.0, 'impressions' => 100, 'clicks' => 6, 'conversions' => 0.0];
            }
        };
        $this->assertNotContains('conversion_starvation', array_column($diagnostics->diagnose($campaign->fresh()), 'type'));

        $strategy->update(['deployed_at' => now()->subDays(14)]);
        $finding = collect($diagnostics->diagnose($campaign->fresh()))->firstWhere('type', 'conversion_starvation');
        $this->assertNotNull($finding);
        $this->assertFalse($finding['can_auto_fix']);
        $this->assertNull($finding['auto_fix_action']);
    }
}
