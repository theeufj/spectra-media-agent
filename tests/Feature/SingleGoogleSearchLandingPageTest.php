<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AdExtensionAgent;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Campaigns\SingleGoogleSearchLandingPage;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SingleGoogleSearchLandingPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_operator_destination_takes_precedence_for_approval_and_all_automated_repairs(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id,
            'landing_page_url' => 'https://example.test/?ref=summer#form', 'status' => 'active',
            'google_ads_campaign_id' => 'customers/123/campaigns/789', 'platform_status' => 'ENABLED', 'end_date' => now()->addWeek()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'signed_off_at' => now(), 'bidding_strategy' => ['single_landing_page' => true, 'landing_page_url' => 'https://example.test/pricing']]);
        $url = "https://example.test/?ref=summer&utm_source=google&utm_medium=search&utm_campaign=spectra_{$campaign->id}&utm_content=strategy_{$strategy->id}#form";
        $this->assertSame($url, (new LandingUrlBuilder($customer))->getFinalUrl($campaign, $strategy, new ExecutionPlan([])));
        $release = app(CreativeRelease::class)->selection($strategy);
        $this->assertSame('https://example.test/?ref=summer#form', $release['mandate']['destination']);
        $campaign->update(['landing_page_url' => 'https://example.test/#changed']);
        $this->assertNotSame($release['fingerprint'], app(CreativeRelease::class)->selection($strategy->fresh())['fingerprint']);
        $this->assertTrue(SingleGoogleSearchLandingPage::protectsResource($campaign, $campaign->googleAdsResourceName()));
        $this->assertFalse(SingleGoogleSearchLandingPage::protectsResource($campaign, 'customers/123/campaigns/790'));

        $this->assertSame('single_landing_page_restricts_extensions', app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy, true));
        $repair = app(AdExtensionAgent::class)->repairSitelinks($campaign, 4, $strategy);
        $this->assertSame([], $repair['created']);
        $this->assertContains('single_landing_page_restricts_extensions', $repair['unresolved']);
        $strategy->update(['bidding_strategy' => [], 'execution_result' => [
            'metadata' => ['google_search_baseline' => ['single_landing_page_url' => $url]],
        ]]);
        $this->assertTrue(SingleGoogleSearchLandingPage::protectsResource($campaign, $campaign->googleAdsResourceName()));
        $linker = new LinkCampaignAsset($customer);
        $this->assertNull($linker('123', $campaign->googleAdsResourceName(), 'customers/123/assets/888', AssetFieldType::SITELINK));
    }

    public function test_a_legacy_strategy_keeps_its_own_destination_and_opt_in_requires_a_valid_campaign_url(): void
    {
        $strategy = new Strategy(['platform' => 'Google Ads', 'campaign_type' => 'search',
            'bidding_strategy' => ['single_landing_page' => 'true', 'landing_page_url' => 'https://example.test/shop']]);
        $campaign = new Campaign(['landing_page_url' => 'javascript:alert(1)']);
        $builder = new LandingUrlBuilder(new Customer);
        $this->assertStringStartsWith('https://example.test/shop?', $builder->getFinalUrl($campaign, $strategy, new ExecutionPlan([])));
        $strategy->bidding_strategy = ['single_landing_page' => true];
        $this->expectException(\InvalidArgumentException::class);
        $builder->getFinalUrl($campaign, $strategy, new ExecutionPlan([]));
    }
}
