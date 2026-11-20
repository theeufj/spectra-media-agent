<?php

namespace Tests\Unit;

use App\Models\Strategy;
use App\Services\Creative\GoogleCreativeCta;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\StageDisplayBanners;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroupWithAssets;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupAdServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateAssetGroupResult;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateOperationResponse;
use Tests\TestCase;

class GoogleDisplayNativeCreativeTest extends TestCase
{
    public function test_button_matches_the_declared_goal_and_legacy_approval_keeps_automatic_selection(): void
    {
        foreach (['SIGNUP' => 'Sign Up', 'paid_subscription' => 'Subscribe', 'lead' => 'Contact Us', 'sales' => 'Shop Now', 'unknown' => 'Learn More'] as $goal => $label) {
            $this->assertSame($label, GoogleCreativeCta::label(new Strategy(['conversion_goals' => ['primary_goal' => $goal]])));
        }
        $this->assertNull(GoogleCreativeCta::approvedLabel(new Strategy));
    }

    public function test_responsive_display_sends_native_cta_and_distinct_image_types(): void
    {
        $api = $this->createMock(AdGroupAdServiceClient::class);
        $api->expects($this->once())->method('mutateAdGroupAds')->willReturnCallback(function (MutateAdGroupAdsRequest $request) {
            $this->assertTrue($request->getValidateOnly());
            $ad = $request->getOperations()[0]->getCreate()->getAd()->getResponsiveDisplayAd();
            $this->assertSame('Sign Up', $ad->getCallToActionText());
            $this->assertSame('customers/123/assets/1', $ad->getMarketingImages()[0]->getAsset());
            $this->assertSame('customers/123/assets/2', $ad->getSquareMarketingImages()[0]->getAsset());

            return new MutateAdGroupAdsResponse(['results' => [new MutateAdGroupAdResult(['resource_name' => 'customers/123/adGroupAds/1~2'])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupAdServiceClient')->willReturn($api);
        $service = (new \ReflectionClass(CreateResponsiveDisplayAd::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        $this->assertSame('customers/123/adGroupAds/1~2', $service->dryRun()('123', 'customers/123/adGroups/1', [
            'callToActionText' => 'Sign Up', 'imageAssets' => ['customers/123/assets/1'], 'squareImageAssets' => ['customers/123/assets/2'],
            'headlines' => ['Example'], 'longHeadlines' => ['The longer headline'], 'descriptions' => ['Use the service.'],
            'businessName' => 'Example', 'finalUrls' => ['https://example.org'],
        ]));
    }

    public function test_performance_max_creates_and_links_the_reviewed_native_cta_in_the_asset_group_transaction(): void
    {
        $api = $this->createMock(GoogleAdsServiceClient::class);
        $api->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) {
            $this->assertTrue($request->getValidateOnly());
            $ops = $request->getMutateOperations();
            $this->assertCount(3, $ops);
            $asset = $ops[1]->getAssetOperation()->getCreate();
            $link = $ops[2]->getAssetGroupAssetOperation()->getCreate();
            $this->assertSame(CallToActionType::SIGN_UP, $asset->getCallToActionAsset()->getCallToAction());
            $this->assertSame($ops[0]->getAssetGroupOperation()->getCreate()->getResourceName(), $link->getAssetGroup());
            $this->assertSame($asset->getResourceName(), $link->getAsset());
            $this->assertSame(AssetFieldType::CALL_TO_ACTION_SELECTION, $link->getFieldType());

            return new MutateGoogleAdsResponse(['mutate_operation_responses' => [new MutateOperationResponse([
                'asset_group_result' => new MutateAssetGroupResult(['resource_name' => 'customers/123/assetGroups/42']),
            ])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($api);
        $service = (new \ReflectionClass(CreateAssetGroupWithAssets::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        $this->assertSame('customers/123/assetGroups/42', $service->dryRun()('123', 'customers/123/campaigns/17', 'Reviewed', ['https://example.org'], [], 'Sign Up'));
    }

    public function test_uploaded_image_ads_preserve_approved_bytes_and_reject_tampered_or_wrong_size_files(): void
    {
        $canvas = imagecreatetruecolor(300, 250);
        ob_start();
        imagejpeg($canvas);
        $bytes = (string) ob_get_clean();
        $digest = hash('sha256', $bytes);
        $this->assertTrue(StageDisplayBanners::matches($bytes, '300x250', $digest));
        $this->assertFalse(StageDisplayBanners::matches($bytes.'changed', '300x250', $digest));
        $this->assertFalse(StageDisplayBanners::matches($bytes, '300x600', $digest));
        $oversize = $bytes.str_repeat('0', 150_000);
        $this->assertFalse(StageDisplayBanners::matches($oversize, '300x250', hash('sha256', $oversize)));
        $ad = StageDisplayBanners::operation('customers/123/adGroups/17', 'Reviewed banner', 'https://example.org', $bytes)->getCreate()->getAd();
        $this->assertSame($bytes, $ad->getImageAd()->getData());
        $this->assertSame(['https://example.org'], iterator_to_array($ad->getFinalUrls()));
        $this->assertNull($ad->getResponsiveDisplayAd());
    }

    public function test_no_static_ads_are_created_for_an_approval_that_only_covers_the_parent_image(): void
    {
        $service = (new \ReflectionClass(StageDisplayBanners::class))->newInstanceWithoutConstructor();
        $strategy = new Strategy(['creative_approval' => ['image_ids' => [5]]]);
        $this->assertSame([], $service->stage($strategy, '123', 'customers/123/campaigns/17', 'customers/123/adGroups/4'));
    }
}
