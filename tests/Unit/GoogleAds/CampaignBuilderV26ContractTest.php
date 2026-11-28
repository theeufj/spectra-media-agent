<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenCampaign;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\LocalServicesServices\CreateLocalServicesCampaign;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\GoogleAds\ShoppingServices\CreateShoppingCampaign;
use App\Services\GoogleAds\VideoServices\CreateVideoCampaign;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V26\Enums\BudgetPeriodEnum\BudgetPeriod;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignBudgetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateOperationResponse;
use Google\ApiCore\PagedListResponse;
use Tests\TestCase;

class CampaignBuilderV26ContractTest extends TestCase
{
    private const SEARCH_ACCOUNT = '1234567890';

    private const SEARCH_RESOURCE = 'customers/1234567890/campaigns/61';

    private const TOTAL_BUDGET = ['total_micros' => 154_000_000, 'currency' => 'AUD', 'start_date' => '2026-11-29', 'end_date' => '2026-12-05'];

    public function test_supported_builders_send_day_schedules_as_v26_campaign_timestamps(): void
    {
        foreach ([CreateSearchCampaign::class, CreateDisplayCampaign::class, CreateDemandGenCampaign::class,
            CreateShoppingCampaign::class, CreateLocalServicesCampaign::class] as $class) {
            $budget = $this->createMock(CampaignBudgetServiceClient::class);
            $budget->expects($this->once())->method('mutateCampaignBudgets')->willReturnCallback(function (MutateCampaignBudgetsRequest $request): MutateCampaignBudgetsResponse {
                $this->assertSame(10_000_000, $request->getOperations()[0]->getCreate()->getAmountMicros());
                $this->assertFalse($request->getOperations()[0]->getCreate()->hasTotalAmountMicros());

                return new MutateCampaignBudgetsResponse([
                    'results' => [new MutateCampaignBudgetResult(['resource_name' => 'customers/1234567890/campaignBudgets/90'])],
                ]);
            });
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

    public function test_a_search_total_budget_and_paused_campaign_are_created_in_one_atomic_operation(): void
    {
        foreach ([false, true] as $validateOnly) {
            $google = $this->createMock(GoogleAdsServiceClient::class);
            $google->method('search')->willReturn($this->queryRows());
            $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) use ($validateOnly): MutateGoogleAdsResponse {
                $this->assertSame(self::SEARCH_ACCOUNT, $request->getCustomerId());
                $this->assertSame($validateOnly, $request->getValidateOnly());
                $this->assertFalse($request->getPartialFailure());
                $operations = $request->getMutateOperations();
                $this->assertCount(2, $operations);
                $budget = $operations[0]->getCampaignBudgetOperation()->getCreate();
                $campaign = $operations[1]->getCampaignOperation()->getCreate();
                $this->assertSame('customers/1234567890/campaignBudgets/-1', $budget->getResourceName());
                $this->assertSame($budget->getResourceName(), $campaign->getCampaignBudget());
                $this->assertSame(BudgetPeriod::CUSTOM_PERIOD, $budget->getPeriod());
                $this->assertSame(BudgetDeliveryMethod::STANDARD, $budget->getDeliveryMethod());
                $this->assertFalse($budget->getExplicitlyShared());
                $this->assertFalse($budget->hasAmountMicros());
                $this->assertSame(154_000_000, $budget->getTotalAmountMicros());
                $this->assertSame(CampaignStatus::PAUSED, $campaign->getStatus());
                $this->assertSame('2026-11-29 00:00:00', $campaign->getStartDateTime());
                $this->assertSame('2026-12-05 23:59:59', $campaign->getEndDateTime());

                return new MutateGoogleAdsResponse($validateOnly ? [] : ['mutate_operation_responses' => [
                    new MutateOperationResponse(['campaign_budget_result' => new MutateCampaignBudgetResult(['resource_name' => 'customers/1234567890/campaignBudgets/91'])]),
                    new MutateOperationResponse(['campaign_result' => new MutateCampaignResult(['resource_name' => self::SEARCH_RESOURCE])]),
                ]]);
            });
            $service = $this->searchBuilder($google)->dryRun($validateOnly);

            $this->assertSame($validateOnly ? null : self::SEARCH_RESOURCE, $service(self::SEARCH_ACCOUNT, $this->totalBudgetInputs()));
        }
    }

    public function test_single_destination_stages_search_with_campaign_only_sitelink_and_url_expansion_controls(): void
    {
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->method('search')->willReturn($this->queryRows());
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request): MutateGoogleAdsResponse {
            $campaign = $request->getMutateOperations()[1]->getCampaignOperation()->getCreate();
            $this->assertSame([\Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType::SITELINK],
                iterator_to_array($campaign->getExcludedParentAssetFieldTypes()));
            $this->assertSame([['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT']],
                json_decode($campaign->serializeToJsonString(), true)['assetAutomationSettings']);

            return new MutateGoogleAdsResponse;
        });
        $this->searchBuilder($google)->dryRun()(self::SEARCH_ACCOUNT, [...$this->totalBudgetInputs(), 'single_landing_page' => true]);
    }

    public function test_a_same_name_search_campaign_must_match_the_approved_total_before_it_is_reused(): void
    {
        foreach ([true, false] as $matches) {
            $google = $this->createMock(GoogleAdsServiceClient::class);
            $google->method('search')->willReturn($this->queryRows(new GoogleAdsRow(['campaign' => new Campaign(['resource_name' => self::SEARCH_RESOURCE])])));
            $google->expects($this->never())->method('mutate');
            $service = $this->searchBuilder($google);

            $read = $this->createMock(ReadCampaignConfiguration::class);
            $read->expects($this->once())->method('hasVerifiedSearchTotalBudget')
                ->with(self::SEARCH_ACCOUNT, self::SEARCH_RESOURCE, self::TOTAL_BUDGET)->willReturn($matches);
            $this->app->bind(ReadCampaignConfiguration::class, fn () => $read);

            if (! $matches) {
                $this->expectExceptionMessage('The existing Search campaign does not match the approved total budget and schedule.');
            }
            $this->assertSame(self::SEARCH_RESOURCE, $service(self::SEARCH_ACCOUNT, $this->totalBudgetInputs()));
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

    private function searchBuilder(GoogleAdsServiceClient $google): CreateSearchCampaign
    {
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($this->never())->method('getCampaignBudgetServiceClient');
        $client->expects($this->never())->method('getCampaignServiceClient');
        $client->method('getGoogleAdsServiceClient')->willReturn($google);
        $customer = new Customer;
        $customer->setRawAttributes(['google_ads_customer_id' => self::SEARCH_ACCOUNT]);
        $service = (new \ReflectionClass(CreateSearchCampaign::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, $customer);

        return $service;
    }

    private function queryRows(GoogleAdsRow ...$rows): PagedListResponse
    {
        $result = $this->createMock(PagedListResponse::class);
        $result->method('getIterator')->willReturnCallback(function () use ($rows): \Generator {
            yield from $rows;
        });

        return $result;
    }

    private function totalBudgetInputs(): array
    {
        return ['businessName' => 'Reviewed total budget', 'budget' => '22.00', 'status' => 'ENABLED',
            'startDate' => self::TOTAL_BUDGET['start_date'], 'endDate' => self::TOTAL_BUDGET['end_date'],
            'google_total_budget' => self::TOTAL_BUDGET];
    }
}
