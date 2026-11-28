<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\SingleGoogleSearchLandingPage;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\VerifySingleSearchLandingPage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SingleSearchLandingPageVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '1234567890';

    private const RESOURCE = 'customers/1234567890/campaigns/61';

    private const URL = 'https://example.com/?utm_source=google#build-ads';

    private function state(string $url = self::URL): array
    {
        return [
            'settings' => [['campaign' => ['resourceName' => self::RESOURCE, 'advertisingChannelType' => 'SEARCH', 'status' => 'PAUSED',
                'assetAutomationSettings' => [['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT']],
                'excludedParentAssetFieldTypes' => ['SITELINK']]]],
            'ads' => [['adGroup' => ['type' => 'SEARCH_STANDARD'], 'adGroupAd' => ['ad' => ['type' => 'RESPONSIVE_SEARCH_AD', 'finalUrls' => [$url]]]]],
            'keywords' => [['adGroupCriterion' => ['finalMobileUrls' => [$url]]]],
            'customer_assets' => [['customerAsset' => ['fieldType' => 'SITELINK']]],
            'campaign_assets' => [['campaignAsset' => ['fieldType' => 'CALLOUT']]],
            'group_assets' => [['adGroupAsset' => ['fieldType' => 'STRUCTURED_SNIPPET']]],
            'customer_locations' => [], 'campaign_locations' => [], 'group_locations' => [],
        ];
    }

    private function reader(callable $snapshot): void
    {
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('singleLandingPageState')->with(self::ACCOUNT, self::RESOURCE)->willReturnCallback($snapshot);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
    }

    public function test_every_active_destination_must_match_and_only_excluded_account_sitelinks_are_ignored(): void
    {
        $state = $this->state();
        $this->reader(function () use (&$state) {
            return $state;
        });
        $service = new VerifySingleSearchLandingPage(new Customer(['google_ads_customer_id' => self::ACCOUNT]));
        $this->assertSame([], $service->inspect(self::ACCOUNT, self::RESOURCE, self::URL, true));
        foreach ([
            'settings.0.campaign.assetAutomationSettings' => [],
            'settings.0.campaign.assetAutomationSettings.0.assetAutomationStatus' => 'OPTED_IN',
            'settings.0.campaign.excludedParentAssetFieldTypes' => [],
            'settings.0.customer.trackingUrlTemplate' => 'https://example.com/signup',
            'settings.0.campaign.finalUrlSuffix' => 'other=destination',
            'ads' => [],
            'ads.0.adGroup.type' => 'SEARCH_DYNAMIC_ADS',
            'ads.0.adGroup.trackingUrlTemplate' => 'https://other.example',
            'ads.0.adGroupAd.ad.finalUrls.0' => 'https://example.com/?utm_source=google',
            'ads.0.adGroupAd.ad.finalMobileUrls' => ['https://example.com/register'],
            'ads.0.adGroupAd.ad.finalAppUrls' => [['url' => 'android-app://example']],
            'keywords.0.adGroupCriterion.finalUrls' => ['https://example.com/pricing'],
            'customer_assets.0.customerAsset.fieldType' => 'CALL',
            'campaign_assets.0.campaignAsset.fieldType' => 'SITELINK',
            'group_assets.0.adGroupAsset.fieldType' => 'LEAD_FORM',
            'customer_locations' => [['customerAssetSet' => ['resourceName' => 'locations']]],
            'campaign_locations' => [['campaignAssetSet' => ['resourceName' => 'locations']]],
            'group_locations' => [['adGroupAssetSet' => ['resourceName' => 'locations']]],
        ] as $path => $value) {
            $state = $this->state();
            data_set($state, $path, $value);
            $this->assertNotEmpty($service->inspect(self::ACCOUNT, self::RESOURCE, self::URL, true), $path);
        }
        $state = $this->state();
        $state['customer_locations'] = [['customerAssetSet' => ['resourceName' => 'locations']]];
        $state['settings'][0]['campaign']['excludedParentAssetSetTypes'] = ['LOCATION_SYNC'];
        $state['settings'][0]['campaign']['status'] = 'ENABLED';
        $this->assertSame([], $service->inspect(self::ACCOUNT, self::RESOURCE, self::URL));
        $this->assertNotEmpty($service->inspect(self::ACCOUNT, self::RESOURCE, self::URL, true));
    }

    public function test_enable_checks_only_the_bound_strategy_and_blocks_a_missing_or_changed_reviewed_destination(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => self::ACCOUNT]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'landing_page_url' => 'https://example.com/#build-ads', 'google_ads_campaign_id' => self::RESOURCE]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'campaign_type' => 'search', 'platform' => 'Google Ads (SEM)',
            'google_ads_campaign_id' => '61', 'bidding_strategy' => ['single_landing_page' => true]]);
        $service = new VerifySingleSearchLandingPage($customer);
        $this->assertNotEmpty($service->forEnable($campaign, self::ACCOUNT, self::RESOURCE));
        $url = SingleGoogleSearchLandingPage::url($campaign, $strategy);
        $strategy->update(['execution_result' => ['metadata' => ['google_search_baseline' => ['single_landing_page_url' => $url]]]]);
        $visits = 0;
        $this->reader(function () use ($url, &$visits) {
            $visits++;

            return $this->state($url);
        });
        $this->assertSame([], $service->forEnable($campaign, self::ACCOUNT, 'customers/1234567890/campaigns/80'));
        $this->assertSame(0, $visits);
        $this->assertSame([], $service->forEnable($campaign, self::ACCOUNT, self::RESOURCE));
        $this->assertSame(1, $visits);
        $strategy->update(['bidding_strategy' => []]);
        $this->assertNotEmpty($service->forEnable($campaign, self::ACCOUNT, self::RESOURCE));
        $this->assertSame(1, $visits);
        $strategy->update(['bidding_strategy' => ['single_landing_page' => true]]);
        $this->reader(function () use ($campaign, $url) {
            $campaign->update(['landing_page_url' => 'https://example.com/#pricing']);

            return $this->state($url);
        });
        $this->assertNotEmpty($service->forEnable($campaign, self::ACCOUNT, self::RESOURCE));
    }
}
