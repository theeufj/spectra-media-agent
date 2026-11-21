<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\PerformanceMaxServices\PublishPerformanceMaxRevision;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\AssetGroupStatusEnum\AssetGroupStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AgencyPerformanceMaxPublicationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_separate_group_is_staged_then_previous_is_paused_only_after_every_selected_role_is_eligible(): void
    {
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active', 'google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->for($customer)->create(['status' => 'active', 'approved_daily_budget' => 10, 'daily_budget' => 10, 'budget_confirmed_at' => now()]);
        $account = '1234567890';
        $base = "customers/{$account}";
        $campaignResource = $base.'/campaigns/999';
        $old = $base.'/assetGroups/100';
        $new = $base.'/assetGroups/200';
        $final = ['https://example.test/offer'];
        $stage = ['asset_group' => $old, 'campaign_resource' => $campaignResource, 'budget_micros' => 10000000, 'final_urls' => $final];
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads', 'campaign_type' => 'performance_max',
            'signed_off_at' => now(), 'deployment_status' => 'active', 'daily_budget' => 10, 'google_ads_campaign_id' => $campaignResource,
            'bidding_strategy' => ['landing_page_url' => $final[0]], 'execution_result' => ['metadata' => ['google_pmax_staging' => $stage]]]);
        $strategy->adCopies()->create(['platform' => 'Google Ads', 'headlines' => ['Test'], 'descriptions' => ['A reviewed offer.'], 'should_deploy' => true]);
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint, 'issues' => []]]);
        $release->approve($strategy, $fingerprint);
        CreativePublication::begin($strategy, $fingerprint, []);
        $name = 'Site to Spend reviewed '.$strategy->id.' '.$fingerprint;
        $before = ['resourceName' => $old, 'name' => 'Original', 'status' => 'ENABLED', 'finalUrls' => $final];
        $replacement = ['resourceName' => $new, 'name' => $name, 'status' => 'ENABLED', 'primaryStatus' => 'ELIGIBLE', 'finalUrls' => $final];
        $campaignData = ['campaign' => ['resourceName' => $campaignResource, 'status' => 'ENABLED', 'advertisingChannelType' => 'PERFORMANCE_MAX',
            'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS', 'assetAutomationSettings' => [
                ['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
                ['assetAutomationType' => 'TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
            ]], 'campaignBudget' => ['amountMicros' => 10000000]];
        $ctaName = \Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType::name(\App\Services\Creative\GoogleCreativeCta::type($strategy->creative_approval['google_native_cta']));
        $unrelatedGroup = ['resourceName' => $base.'/assetGroups/998', 'name' => 'Other offer', 'status' => 'ENABLED'];
        $snapshot = fn ($groups) => ['campaign' => $campaignData, 'groups' => array_map(fn ($group) => ['assetGroup' => $group], [...$groups, $unrelatedGroup]),
            'ctas' => [['assetGroup' => ['resourceName' => $new], 'asset' => ['callToActionAsset' => ['callToAction' => $ctaName]]]]];
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->expects($this->exactly(4))->method('performanceMax')->willReturn($snapshot([$before]), $snapshot([$before, $replacement]),
            $snapshot([$before, $replacement]), $snapshot([['status' => 'PAUSED'] + $before, $replacement]));
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $publisher = $this->getMockBuilder(PublishPerformanceMaxRevision::class)->disableOriginalConstructor()
            ->onlyMethods(['links', 'reviewedText', 'reviewedMedia', 'submitReviewedOperations', 'copySignals'])->getMock();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($publisher, $customer);
        $publisher->method('reviewedText')->willReturn([AssetFieldType::HEADLINE => ['Reviewed']]);
        $publisher->method('reviewedMedia')->willReturn([]);
        $publisher->method('copySignals')->willReturn([]);
        $link = fn ($field, $id, $key) => ['asset' => $base.'/assets/'.$id, 'resource' => $new.'/asset-'.$id, 'field' => $field, 'key' => $key, 'eligible' => true, 'advertiser' => true];
        $brand = $link(AssetFieldType::BUSINESS_NAME, 101, 'Business');
        $text = $link(AssetFieldType::HEADLINE, 102, 'reviewed');
        $cta = $link(AssetFieldType::CALL_TO_ACTION_SELECTION, 103, '');
        $external = $link(AssetFieldType::HEADLINE, 999, 'An existing approved headline');
        $publisher->method('links')->willReturn([$brand, $external], [$brand, $external], [$brand, $text, $cta],
            [$brand, $external], [$brand, $text, $cta, $external], [$brand, $external], [$brand, $text, $cta, $external]);
        $mutations = [];
        $publisher->expects($this->exactly(2))->method('submitReviewedOperations')->willReturnCallback(function ($strategy, $fingerprint, $account, $operations) use (&$mutations) {
            $mutations[] = $operations;
        });
        $this->assertFalse($publisher->advance($strategy->fresh(), $fingerprint));
        $this->assertTrue($mutations[0][0]->getAssetGroupOperation()->hasCreate());
        $this->assertTrue(collect($mutations[0])->contains(fn ($operation) => $operation->getAssetGroupAssetOperation()?->getCreate()?->getAsset() === $external['asset']));
        $this->assertFalse($publisher->advance($strategy->fresh(), $fingerprint));
        $this->assertCount(1, $mutations);
        $this->assertFalse($publisher->advance($strategy->fresh(), $fingerprint));
        $pause = $mutations[1][0]->getAssetGroupOperation()->getUpdate();
        $this->assertSame($old, $pause->getResourceName());
        $this->assertSame(AssetGroupStatus::PAUSED, $pause->getStatus());
        $this->assertTrue($publisher->advance($strategy->fresh(), $fingerprint));
        $this->assertSame($new, $strategy->fresh()->execution_result['metadata']['google_pmax_staging']['asset_group']);
        $this->assertSame('published', $strategy->fresh()->creative_publication['phase']);
    }
}
