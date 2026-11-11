<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Campaigns\CampaignMarketResearchProvider;
use App\Services\FacebookAds\FacebookMarketResearchProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookMarketResearchTest extends TestCase
{
    public function test_meta_budget_uses_account_currency_and_live_audience_without_inventing_a_forecast(): void
    {
        config(['services.facebook.system_user_token' => 'test-system-user', 'services.facebook.graph_version' => 'v24.0']);
        $base = 'https://graph.facebook.com/v24.0/act_123456';
        Http::fake([
            $base.'/minimum_budgets' => Http::response(['data' => [
                ['currency' => 'AED', 'min_daily_budget_imp' => 369],
                ['currency' => 'AUD', 'min_daily_budget_imp' => 635],
            ]]),
            $base.'/delivery_estimate*' => Http::response(['data' => [[
                'estimate_ready' => true, 'estimate_mau_lower_bound' => 12000, 'estimate_mau_upper_bound' => 14000,
            ]]]),
            $base.'?*' => Http::response(['id' => 'act_123456', 'account_status' => 1, 'currency' => 'AUD', 'timezone_name' => 'Australia/Sydney']),
        ]);
        $campaign = (new Campaign(['geographic_targeting' => [['country' => 'AU']]]))->setRelation('customer', new Customer([
            'facebook_ads_account_id' => '123456', 'facebook_bm_owned' => true, 'currency_code' => 'AUD',
        ]));
        $strategy = new Strategy(['platform' => 'Facebook Ads']);
        $provider = collect(app()->tagged('campaign.market_research.providers'))->first(fn (CampaignMarketResearchProvider $item) => $item->supports($strategy));
        $this->assertInstanceOf(FacebookMarketResearchProvider::class, $provider);

        $result = $provider->propose($campaign, $strategy, [], ['daily_budget_micros' => 10_000_000]);
        $this->assertSame('ready', $result['status']);
        $this->assertArrayNotHasKey('forecast', $result);
        $this->assertSame(12000, $result['evidence']['meta']['monthly_audience_lower_bound']);
        $this->assertSame(6_350_000, $result['evidence']['meta']['minimum_daily_budget_micros']);
        $this->assertSame('needs_review', $provider->propose($campaign, $strategy, [], ['daily_budget_micros' => 5_000_000])['status']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/delivery_estimate?')
            && $request->hasHeader('Authorization', 'Bearer test-system-user')
            && json_decode($request['targeting_spec'], true) === ['geo_locations' => ['countries' => ['AU']]]);

        // Strategy generation stores country names, while older drafts use codes.
        $campaign->geographic_targeting = null;
        $strategy->setRelation('targetingConfig', new TargetingConfig(['geo_locations' => ['Australia']]));
        $this->assertSame('ready', $provider->propose($campaign, $strategy, [], ['daily_budget_micros' => 10_000_000])['status']);

        $strategy->targetingConfig->geo_locations = ['Sydney'];
        $this->assertSame('needs_review', $provider->propose($campaign, $strategy, [], ['daily_budget_micros' => 10_000_000])['status']);
    }
}
