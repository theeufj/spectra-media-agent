<?php

namespace Tests\Feature;

use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient;
use Google\Ads\GoogleAds\V22\Common\ImageAsset;
use Google\Ads\GoogleAds\V22\Common\ImageDimension;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Resources\CampaignAsset;
use Google\Ads\GoogleAds\V22\Services\Client\AdServiceClient;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Services\MutateAdsResponse;
use Google\ApiCore\PagedListResponse;
use Tests\TestCase;

class GoogleCreativeApiContractTest extends TestCase
{
    public function test_replacement_sends_exact_reviewed_unicode_through_ad_service(): void
    {
        $copy = GoogleAdStrengthRepair::copy([str_repeat('é', 30), '広告キャンペーンを管理', 'Explore Our Plans'],
            [str_repeat('é', 90), 'Manage your campaigns.']);
        $service = $this->createPartialMock(UpdateResponsiveSearchAd::class, ['ensureClient']);
        $service->method('ensureClient')->willReturnCallback(fn () => null);
        $adClient = $this->createMock(AdServiceClient::class);
        $adClient->expects($this->once())->method('mutateAds')->willReturnCallback(function ($request) use ($copy) {
            $this->assertSame('123', $request->getCustomerId());
            $operation = $request->getOperations()[0];
            $this->assertSame('customers/123/ads/789', $operation->getUpdate()->getResourceName());
            $headlines = [];
            foreach ($operation->getUpdate()->getResponsiveSearchAd()->getHeadlines() as $asset) {
                $headlines[] = $asset->getText();
            }
            $this->assertSame($copy['headlines'], $headlines);
            $this->assertSame($copy['descriptions'][0], $operation->getUpdate()->getResponsiveSearchAd()->getDescriptions()[0]->getText());
            $this->assertSame(['responsive_search_ad.headlines', 'responsive_search_ad.descriptions'], iterator_to_array($operation->getUpdateMask()->getPaths()));

            return new MutateAdsResponse;
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdServiceClient')->willReturn($adClient);
        (new \ReflectionProperty($service, 'client'))->setValue($service, $client);
        $this->assertTrue($service->replace('123', 'customers/123/adGroupAds/456~789', $copy['headlines'], $copy['descriptions']));
    }

    public function test_normalization_happens_before_review_and_replacement_cannot_silently_change_it(): void
    {
        $copy = GoogleAdStrengthRepair::copy([' Café Campaigns ', 'café campaigns', 'Manage Your Ads', 'See Your Results'],
            ['Verified business benefits.', 'Explore campaign management.']);
        $this->assertSame(['Café Campaigns', 'Manage Your Ads', 'See Your Results'], $copy['headlines']);
        $service = $this->createPartialMock(UpdateResponsiveSearchAd::class, ['ensureClient']);
        $service->method('ensureClient')->willReturnCallback(fn () => null);
        $this->expectException(\InvalidArgumentException::class);
        $service->replace('123', 'customers/123/adGroupAds/456~789', [' Café Campaigns ', 'Manage Your Ads', 'See Your Results'], $copy['descriptions']);
    }

    public function test_overlong_assets_are_rejected_instead_of_truncated_after_review(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleAdStrengthRepair::copy([str_repeat('é', 31), 'Another Headline', 'Third Headline'], ['First description.', 'Second description.']);
    }

    public function test_campaign_ads_read_retains_google_action_items_and_policy_review_status(): void
    {
        $reader = $this->createPartialMock(ReadCampaignConfiguration::class, ['rows']);
        $reader->expects($this->once())->method('rows')->willReturnCallback(function ($id, $query) {
            $this->assertSame('123', $id);
            foreach (['ad_group_ad.action_items', 'ad_group_ad.policy_summary.review_status', 'campaign.status', 'ad_group.status'] as $field) {
                $this->assertStringContainsString($field, $query);
            }

            return [];
        });
        $reader->ads('123', 'customers/123/campaigns/9');
        $this->expectException(\InvalidArgumentException::class);
        $reader->ads('999', 'customers/123/campaigns/9');
    }

    public function test_image_query_uses_supported_campaign_asset_fields_and_does_not_invent_a_rating(): void
    {
        $row = new GoogleAdsRow(['asset' => new Asset(['name' => 'Campaign image',
            'image_asset' => new ImageAsset(['full_size' => new ImageDimension(['url' => 'https://example.com/image.png'])])]),
            'campaign_asset' => new CampaignAsset(['status' => 2]), 'metrics' => new Metrics(['impressions' => 10, 'clicks' => 2])]);
        $response = $this->createMock(PagedListResponse::class);
        $response->method('getIterator')->willReturn(new \ArrayIterator([$row]));
        $source = $this->createPartialMock(GetAdPerformanceByAsset::class, ['ensureClient', 'searchQuery']);
        $source->method('ensureClient')->willReturnCallback(fn () => null);
        $source->method('searchQuery')->willReturnCallback(function ($id, $query) use ($response) {
            $this->assertStringNotContainsString('campaign_asset.performance_label', $query);
            $this->assertStringContainsString('campaign_asset.status', $query);

            return $response;
        });
        $result = $source->getImageAssetPerformance('123', 'customers/123/campaigns/9');
        $this->assertSame('UNKNOWN', $result[0]['performance_label']);
        $this->assertSame(20.0, $result[0]['ctr']);
    }

    public function test_failed_asset_read_remains_an_error_instead_of_empty_learning_data(): void
    {
        $source = $this->createPartialMock(GetAdPerformanceByAsset::class, ['ensureClient', 'searchQuery']);
        $source->method('ensureClient')->willReturnCallback(fn () => null);
        $source->method('searchQuery')->willThrowException(new \RuntimeException('Google query rejected'));
        $this->expectException(\RuntimeException::class);
        $source->getImageAssetPerformance('123', 'customers/123/campaigns/9');
    }

    public function test_current_campaign_status_is_account_scoped_and_empty_reads_are_unknown(): void
    {
        $reader = $this->createPartialMock(ReadCampaignConfiguration::class, ['rows']);
        $reader->expects($this->once())->method('rows')->willReturnCallback(function ($id, $query) {
            $this->assertSame('123', $id);
            $this->assertStringContainsString("SELECT campaign.id, campaign.status FROM campaign WHERE campaign.resource_name = 'customers/123/campaigns/9'", $query);

            return [];
        });
        $this->assertSame('UNKNOWN', $reader->campaignStatus('123', 'customers/123/campaigns/9'));
        $this->expectException(\InvalidArgumentException::class);
        $reader->campaignStatus('999', 'customers/123/campaigns/9');
    }

    public function test_sitelink_query_selects_campaign_identity_required_by_its_filter(): void
    {
        $reader = $this->createPartialMock(ReadCampaignConfiguration::class, ['rows']);
        $reader->expects($this->once())->method('rows')->willReturnCallback(function ($id, $query) {
            $this->assertStringContainsString('SELECT campaign.id, campaign_asset.asset', $query);
            $this->assertStringContainsString("campaign_asset.campaign = 'customers/123/campaigns/9'", $query);

            return [];
        });
        $this->assertSame([], $reader->sitelinks('123', 'customers/123/campaigns/9'));
    }
}
