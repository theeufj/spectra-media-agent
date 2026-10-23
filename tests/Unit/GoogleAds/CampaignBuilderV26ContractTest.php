<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenCampaign;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\LocalServicesServices\CreateLocalServicesCampaign;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\GoogleAds\ShoppingServices\CreateShoppingCampaign;
use App\Services\GoogleAds\VideoServices\CreateVideoCampaign;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignBudgetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Google\ApiCore\PagedListResponse;
use Tests\TestCase;

class CampaignBuilderV26ContractTest extends TestCase
{
    public function test_supported_builders_send_day_schedules_as_v26_campaign_timestamps(): void
    {
        foreach ([CreateSearchCampaign::class, CreateDisplayCampaign::class, CreateDemandGenCampaign::class,
            CreateShoppingCampaign::class, CreateLocalServicesCampaign::class] as $class) {
            $budget = $this->createMock(CampaignBudgetServiceClient::class);
            $budget->expects($this->once())->method('mutateCampaignBudgets')->willReturn(new MutateCampaignBudgetsResponse([
                'results' => [new MutateCampaignBudgetResult(['resource_name' => 'customers/1234567890/campaignBudgets/90'])],
            ]));
            $campaign = $this->createMock(CampaignServiceClient::class);
            $campaign->expects($this->once())->method('mutateCampaigns')->willReturnCallback(function (MutateCampaignsRequest $request): MutateCampaignsResponse {
                $created = $request->getOperations()[0]->getCreate();
                $this->assertSame('2026-11-01 00:00:00', $created->getStartDateTime());
                $this->assertSame('2026-11-30 23:59:59', $created->getEndDateTime());

                return new MutateCampaignsResponse(['results' => [new MutateCampaignResult([
                    'resource_name' => 'customers/1234567890/campaigns/61',
                ])]]);
            });
            $empty = $this->createMock(PagedListResponse::class);
            $empty->method('getIterator')->willReturnCallback(function (): \Generator {
                yield from [];
            });
            $search = $this->createMock(GoogleAdsServiceClient::class);
            $search->method('search')->willReturn($empty);
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getCampaignServiceClient')->willReturn($campaign);
            $client->method('getCampaignBudgetServiceClient')->willReturn($budget);
            $client->method('getGoogleAdsServiceClient')->willReturn($search);
            $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
            (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, new Customer);

            $this->assertSame('customers/1234567890/campaigns/61', $service('1234567890', [
                'businessName' => 'Contract test', 'budget' => 10.0, 'merchantId' => 123,
                'startDate' => '2026-11-01', 'endDate' => '2026-11-30',
                'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
            ]), $class);
        }
    }

    public function test_legacy_video_creation_is_rejected_before_any_platform_budget_write(): void
    {
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($this->never())->method('getCampaignBudgetServiceClient');
        $client->expects($this->never())->method('getCampaignServiceClient');
        $service = (new \ReflectionClass(CreateVideoCampaign::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);

        $this->assertNull($service('1234567890', ['businessName' => 'Legacy video', 'budget' => 10.0]));
    }
}
