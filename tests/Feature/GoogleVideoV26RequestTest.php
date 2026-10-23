<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\VideoServices\CreateResponsiveVideoAd;
use App\Services\GoogleAds\VideoServices\CreateVideoAdGroup;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupAdServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsResponse;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleVideoV26RequestTest extends TestCase
{
    use DatabaseTransactions;

    public function test_video_request_uses_v26_brand_assets_and_ad_level_final_urls(): void
    {
        $transport = new MockTransport;
        $resource = 'customers/123/adGroupAds/456~789';
        $transport->addResponse(new MutateAdGroupAdsResponse([
            'results' => [new MutateAdGroupAdResult(['resource_name' => $resource])],
        ]), new MockStatus(0));
        $service = $this->service($transport)->dryRun();

        $this->assertSame($resource, $service('123', 'customers/123/adGroups/456', $this->adData()));

        $calls = $transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $request = $calls[0]->getRequestObject();
        $this->assertInstanceOf(MutateAdGroupAdsRequest::class, $request);
        $this->assertSame('123', $request->getCustomerId());
        $this->assertTrue($request->getValidateOnly());
        $ad = $request->getOperations()[0]->getCreate()->getAd();
        $this->assertSame(['https://example.com/offer'], iterator_to_array($ad->getFinalUrls()));
        $video = $ad->getVideoResponsiveAd();
        $this->assertSame('Example Brand', $video->getBusinessName()->getText());
        $this->assertSame('customers/123/assets/11', $video->getLogoImages()[0]->getAsset());
        $this->assertSame('customers/123/assets/22', $video->getVideos()[0]->getAsset());
        $this->assertSame('A longer headline', $video->getLongHeadlines()[0]->getText());
        $this->assertSame('Learn more', $video->getCallToActions()[0]->getText());
    }

    public function test_an_existing_video_campaigns_ad_group_uses_the_actual_v26_type(): void
    {
        $transport = new MockTransport;
        $resource = 'customers/123/adGroups/456';
        $transport->addResponse(new MutateAdGroupsResponse([
            'results' => [new MutateAdGroupResult(['resource_name' => $resource])],
        ]), new MockStatus(0));
        $generated = new AdGroupServiceClient(['transport' => $transport, 'credentials' => new InsecureCredentials]);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupServiceClient')->willReturn($generated);
        $service = new class(new Customer, $client) extends CreateVideoAdGroup
        {
            public function __construct(Customer $customer, GoogleAdsClient $client)
            {
                $this->customer = $customer;
                $this->client = $client;
            }
        };

        $this->assertSame($resource, $service->dryRun()('123', 'customers/123/campaigns/1', 'Video group'));

        $calls = $transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $request = $calls[0]->getRequestObject();
        $this->assertInstanceOf(MutateAdGroupsRequest::class, $request);
        $this->assertTrue($request->getValidateOnly());
        $this->assertSame(AdGroupType::VIDEO_RESPONSIVE, $request->getOperations()[0]->getCreate()->getType());
    }

    #[DataProvider('missingBrandData')]
    public function test_missing_required_brand_assets_are_rejected_before_an_rpc(string $key, mixed $value): void
    {
        $transport = new MockTransport;
        $service = $this->service($transport);
        $data = $this->adData();
        $data[$key] = $value;

        try {
            $service('123', 'customers/123/adGroups/456', $data);
            $this->fail('The incomplete video ad must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('business name and square logo', $e->getMessage());
            $this->assertSame(0, $transport->getReceivedCallCount());
        }
    }

    public static function missingBrandData(): array
    {
        return [['businessName', ''], ['logoAssets', []]];
    }

    private function service(MockTransport $transport): CreateResponsiveVideoAd
    {
        $generated = new AdGroupAdServiceClient([
            'transport' => $transport,
            'credentials' => new InsecureCredentials,
        ]);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupAdServiceClient')->willReturn($generated);

        return new class(new Customer, $client) extends CreateResponsiveVideoAd
        {
            public function __construct(Customer $customer, GoogleAdsClient $client)
            {
                $this->customer = $customer;
                $this->client = $client;
            }
        };
    }

    private function adData(): array
    {
        return [
            'headlines' => ['A short headline'],
            'longHeadlines' => ['A longer headline'],
            'descriptions' => ['An example description'],
            'callToActions' => ['Learn more'],
            'videoAssets' => ['customers/123/assets/22'],
            'logoAssets' => ['customers/123/assets/11'],
            'businessName' => 'Example Brand',
            'finalUrls' => ['https://example.com/offer'],
        ];
    }
}
