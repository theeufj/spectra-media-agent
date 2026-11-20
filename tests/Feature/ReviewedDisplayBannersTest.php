<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\DisplayServices\StageDisplayBanners;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V26\Resources\Campaign as RemoteCampaign;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupAdServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewedDisplayBannersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_retry_reuses_the_same_approved_image_ad_and_changed_storage_bytes_cannot_be_staged(): void
    {
        Storage::fake('public');
        $canvas = imagecreatetruecolor(300, 250);
        ob_start();
        imagejpeg($canvas);
        $bytes = (string) ob_get_clean();
        $path = 'images/approved/300x250.jpg';
        Storage::disk('public')->put($path, $bytes);
        $digest = hash('sha256', $bytes);
        $campaignResource = 'customers/123/campaigns/17';
        $group = 'customers/123/adGroups/45';
        $resource = 'customers/123/adGroupAds/45~56';
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'landing_page_url' => 'https://example.org/offer', 'end_date' => now()->addWeek()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'campaign_type' => 'display', 'platform' => 'Google Ads', 'signed_off_at' => now(),
            'google_ads_campaign_id' => $campaignResource, 'google_ads_ad_group_id' => $group, 'conversion_goals' => ['primary_goal' => 'signup']]);
        $image = ImageCollateral::create(['platform' => 'Google Ads', 's3_path' => 'images/art.jpg', 'cloudfront_url' => '/images/art.jpg', 'campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'is_active' => true, 'should_deploy' => true,
            'google_display_banners' => ['cta' => 'Sign Up', 'brand' => 'Example', 'headline' => 'Our offer', 'sizes' => ['300x250' => $path], 'hashes' => ['300x250' => $digest]]]);
        $fingerprint = app(CreativeRelease::class)->selection($strategy)['fingerprint'];
        $strategy->update(['creative_approval' => ['fingerprint' => $fingerprint, 'image_ids' => [$image->id], 'google_native_cta' => 'Sign Up',
            'google_display_banner_hashes' => [$image->id => ['300x250' => $digest]]], 'creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint]]);

        $saved = [];
        $reader = $this->createMock(GoogleAdsServiceClient::class);
        $reader->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request) use ($campaignResource, $group, &$saved) {
            $q = $request->getQuery();
            $this->assertStringContainsString("ad_group.resource_name = '{$group}' AND campaign.resource_name = '{$campaignResource}'", $q);
            $rows = str_contains($q, 'FROM ad_group_ad') ? $saved : [new GoogleAdsRow([
                'campaign' => new RemoteCampaign(['status' => CampaignStatus::PAUSED, 'advertising_channel_type' => AdvertisingChannelType::DISPLAY]),
                'ad_group' => new AdGroup(['status' => AdGroupStatus::ENABLED]),
            ])];
            $page = $this->createMock(PagedListResponse::class);
            $page->method('iterateAllElements')->willReturnCallback(function () use ($rows): \Generator {
                yield from $rows;
            });

            return $page;
        });
        $writer = $this->createMock(AdGroupAdServiceClient::class);
        $writer->expects($this->once())->method('mutateAdGroupAds')->willReturnCallback(function (MutateAdGroupAdsRequest $request) use ($bytes, $resource, &$saved) {
            $this->assertCount(1, $request->getOperations());
            $ad = $request->getOperations()[0]->getCreate()->getAd();
            $this->assertSame($bytes, $ad->getImageAd()->getData());
            $saved[] = new GoogleAdsRow(['ad_group_ad' => new AdGroupAd(['resource_name' => $resource, 'status' => AdGroupAdStatus::ENABLED, 'ad' => $ad])]);

            return new MutateAdGroupAdsResponse(['results' => [new MutateAdGroupAdResult(['resource_name' => $resource])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($reader);
        $client->method('getAdGroupAdServiceClient')->willReturn($writer);
        $service = (new \ReflectionClass(StageDisplayBanners::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, $customer);

        $this->assertSame([$resource], $service->stage($strategy, '123', $campaignResource, $group));
        $this->assertSame([$resource], $service->stage($strategy, '123', $campaignResource, $group));
        Storage::disk('public')->put($path, $bytes.'different content');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be verified');
        $service->stage($strategy, '123', $campaignResource, $group);
    }
}
