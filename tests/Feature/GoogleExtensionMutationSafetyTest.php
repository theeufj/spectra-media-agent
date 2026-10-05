<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AdExtensionAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAssets;
use App\Services\GoogleAds\CommonServices\GetExtensionPerformance;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use App\Services\GoogleAds\PerformanceMaxServices\CreateTextAsset;
use App\Services\GoogleAds\PerformanceMaxServices\HealAssetGroupStrength;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Resources\AssetGroup;
use Google\Ads\GoogleAds\V22\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleExtensionMutationSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'signed_off_at' => now()]);
        AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        Feature::for($customer)->activate(AutoHealing::class);

        return $campaign->load('customer');
    }

    private function reader(string $status = 'ENABLED', array $links = []): void
    {
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('campaignStatus')->willReturn($status);
        $reader->method('sitelinks')->willReturn($links);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
    }

    public function test_cached_active_campaign_cannot_bypass_disabled_automation_in_daily_or_direct_extension_repairs(): void
    {
        $campaign = $this->campaign();
        Feature::for($campaign->customer)->deactivate(AutoHealing::class);
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->expects($this->never())->method('campaignStatus');
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        $agent = app(AdExtensionAgent::class);
        $this->assertSame(['auto_healing_disabled'], $agent->manage($campaign)['unresolved']);
        $this->assertSame(['auto_healing_disabled'], $agent->repairSitelinks($campaign, 2)['unresolved']);
        $this->assertSame(0, app(CreateSitelinkAssets::class, ['customer' => $campaign->customer])->heal($campaign));
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_feature_change_from_another_process_is_read_after_an_earlier_cached_decision(): void
    {
        $campaign = $this->campaign();
        $this->assertTrue(Feature::for($campaign->customer)->active(AutoHealing::class));
        \Illuminate\Support\Facades\DB::table('features')
            ->where('name', AutoHealing::class)->where('scope', Feature::serializeScope($campaign->customer))
            ->update(['value' => 'false']);
        $this->assertTrue(Feature::for($campaign->customer)->active(AutoHealing::class));
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        $result = app(AdExtensionAgent::class)->manage($campaign);
        $this->assertSame(['auto_healing_disabled'], $result['unresolved']);
        $this->assertSame([], $result['created']);
    }

    public function test_automation_disabled_during_generation_blocks_the_first_extension_write(): void
    {
        $campaign = $this->campaign();
        $this->reader();
        $perf = $this->createMock(GetExtensionPerformance::class);
        $perf->method('countByFieldType')->willReturnCallback(fn ($id, $resource, $field) => $field === AssetFieldType::CALLOUT ? 0 : 4);
        $this->app->bind(GetExtensionPerformance::class, fn () => $perf);
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->once())->method('generateContent')->willReturnCallback(function () use ($campaign) {
            Feature::for($campaign->customer)->deactivate(AutoHealing::class);

            return ['text' => '["Campaign Management"]'];
        });
        $this->app->instance(GeminiService::class, $ai);
        $creator = $this->createMock(CreateCalloutAsset::class);
        $creator->expects($this->never())->method('__invoke');
        $this->app->bind(CreateCalloutAsset::class, fn () => $creator);
        $result = app(AdExtensionAgent::class)->manage($campaign);
        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['auto_healing_disabled'], $result['unresolved']);
    }

    public function test_google_paused_and_unknown_campaigns_do_not_create_extensions(): void
    {
        $campaign = $this->campaign();
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        foreach (['PAUSED', 'UNKNOWN'] as $status) {
            $this->reader($status);
            $result = app(AdExtensionAgent::class)->manage($campaign);
            $this->assertSame([], $result['created']);
            $this->assertSame(['google_campaign_'.strtolower($status)], $result['unresolved']);
        }
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_hold_between_asset_creation_and_linking_does_not_attach_or_claim_a_sitelink(): void
    {
        $campaign = $this->campaign();
        $campaign->customer->update(['website' => 'https://example.com']);
        \App\Models\CustomerPage::create(['customer_id' => $campaign->customer_id, 'url' => 'https://example.com/pricing', 'title' => 'Pricing', 'content' => 'Verified pricing source.', 'page_type' => 'service']);
        $this->reader();
        $creator = $this->createMock(CreateSitelinkAsset::class);
        $creator->expects($this->once())->method('__invoke')->willReturnCallback(function () use ($campaign) {
            Campaign::whereKey($campaign->id)->update(['status' => 'paused']);

            return 'customers/123/assets/1';
        });
        $this->app->bind(CreateSitelinkAsset::class, fn () => $creator);
        $linker = $this->createMock(LinkCampaignAsset::class);
        $linker->expects($this->never())->method('__invoke');
        $this->app->bind(LinkCampaignAsset::class, fn () => $linker);
        $this->mock(GeminiService::class);
        $result = app(AdExtensionAgent::class)->repairSitelinks($campaign, 1);
        $this->assertSame([], $result['created']);
        $this->assertContains('campaign_not_active', $result['unresolved']);
    }

    public function test_pmax_pause_during_text_generation_prevents_all_google_asset_writes(): void
    {
        $campaign = $this->campaign();
        $this->reader();
        $group = new GoogleAdsRow(['asset_group' => new AssetGroup(['resource_name' => 'customers/123/assetGroups/77', 'id' => 77, 'name' => 'PMax', 'ad_strength' => 4])]);
        $existing = [];
        foreach ([AssetFieldType::LOGO, AssetFieldType::MARKETING_IMAGE, AssetFieldType::SQUARE_MARKETING_IMAGE, AssetFieldType::PORTRAIT_MARKETING_IMAGE, AssetFieldType::YOUTUBE_VIDEO] as $field) {
            $existing[] = new GoogleAdsRow(['asset_group_asset' => new AssetGroupAsset(['field_type' => $field])]);
        }
        $groups = $this->createMock(PagedListResponse::class);
        $groups->method('iterateAllElements')->willReturn(new \ArrayIterator([$group]));
        $assets = $this->createMock(PagedListResponse::class);
        $assets->method('iterateAllElements')->willReturn(new \ArrayIterator($existing));
        $service = $this->createPartialMock(HealAssetGroupStrength::class, ['ensureClient', 'searchQuery']);
        $service->method('ensureClient')->willReturnCallback(fn () => null);
        $service->method('searchQuery')->willReturnOnConsecutiveCalls($groups, $assets);
        (new \ReflectionProperty($service, 'customer'))->setValue($service, $campaign->customer);
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->once())->method('generateContent')->willReturnCallback(function () use ($campaign) {
            Campaign::whereKey($campaign->id)->update(['status' => 'paused']);

            return ['text' => '{"headlines":["Manage Your Ads"],"long_headlines":[],"descriptions":[]}'];
        });
        (new \ReflectionProperty(HealAssetGroupStrength::class, 'gemini'))->setValue($service, $ai);
        $creator = $this->createMock(CreateTextAsset::class);
        $creator->expects($this->never())->method('__invoke');
        $this->app->bind(CreateTextAsset::class, fn () => $creator);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('campaign_not_active');
        $service->heal($campaign);
    }

    public function test_distinct_existing_destinations_determine_coverage_instead_of_duplicate_asset_count(): void
    {
        $campaign = $this->campaign();
        $links = array_fill(0, 4, ['asset' => ['finalUrls' => ['https://example.com/pricing']]]);
        $this->reader('ENABLED', $links);
        $agent = $this->createMock(AdExtensionAgent::class);
        $agent->expects($this->once())->method('repairSitelinks')->with($campaign, 3, $this->anything())
            ->willReturn(['created' => [], 'errors' => [], 'unresolved' => ['not enough verified pages']]);
        $this->app->instance(AdExtensionAgent::class, $agent);
        $this->assertSame(0, app(CreateSitelinkAssets::class, ['customer' => $campaign->customer])->heal($campaign));
    }

    public function test_changed_strategy_target_is_held_even_when_caller_keeps_an_old_google_resource(): void
    {
        $campaign = $this->campaign();
        $strategy = $campaign->strategies()->first();
        $strategy->forceFill(['google_ads_campaign_id' => 'customers/123/campaigns/10'])->save();
        $this->assertSame('google_strategy_target_changed', app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy));
    }
}
