<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignBudgetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SearchCreationStagingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_low_level_search_creation_cannot_enable_a_campaign_even_when_the_caller_and_config_request_it(): void
    {
        Setting::updateOrCreate(['key' => 'campaign_testing_mode'], ['value' => '0', 'type' => 'boolean']);
        config(['campaigns.testing_mode_default' => false, 'campaigns.default_status' => 'ENABLED']);
        $budget = $this->createMock(CampaignBudgetServiceClient::class);
        $budget->expects($this->once())->method('mutateCampaignBudgets')->willReturn(new MutateCampaignBudgetsResponse([
            'results' => [new MutateCampaignBudgetResult(['resource_name' => 'customers/1234567890/campaignBudgets/90'])],
        ]));
        $campaign = $this->createMock(CampaignServiceClient::class);
        $campaign->expects($this->once())->method('mutateCampaigns')->willReturnCallback(function (MutateCampaignsRequest $request): MutateCampaignsResponse {
            $this->assertSame('1234567890', $request->getCustomerId());
            $this->assertSame(CampaignStatus::PAUSED, $request->getOperations()[0]->getCreate()->getStatus());

            return new MutateCampaignsResponse(['results' => [new MutateCampaignResult(['resource_name' => 'customers/1234567890/campaigns/61'])]]);
        });
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturn($this->rows([]));
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getCampaignServiceClient')->willReturn($campaign);
        $client->method('getCampaignBudgetServiceClient')->willReturn($budget);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $service = $this->service(CreateSearchCampaign::class, $client);

        $this->assertSame('customers/1234567890/campaigns/61', $service('1234567890', [
            'businessName' => 'Operator requested live', 'budget' => 5, 'status' => 'ENABLED',
            'startDate' => '2026-11-01', 'endDate' => '2026-11-30',
        ]));
    }

    public function test_search_ad_group_creation_requires_an_exact_known_paused_search_parent(): void
    {
        foreach ([null, $this->parent(CampaignStatus::ENABLED), $this->parent(CampaignStatus::UNKNOWN),
            $this->parent(CampaignStatus::PAUSED, AdvertisingChannelType::DISPLAY),
            $this->parent(CampaignStatus::PAUSED, AdvertisingChannelType::SEARCH, 'customers/9999999999/campaigns/61')] as $parent) {
            $search = $this->createMock(GoogleAdsServiceClient::class);
            $search->method('search')->willReturnCallback(fn () => $this->rows($parent ? [new GoogleAdsRow(['campaign' => $parent])] : []));
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getGoogleAdsServiceClient')->willReturn($search);
            $client->expects($this->never())->method('getAdGroupServiceClient');
            $service = $this->service(CreateSearchAdGroup::class, $client);

            $this->assertNull($service('1234567890', 'customers/1234567890/campaigns/61', 'New group'));
        }
    }

    public function test_search_ad_group_rejects_wrong_customer_scope_before_any_api_call(): void
    {
        foreach ([['9999999999', 'customers/9999999999/campaigns/61'], ['1234567890', 'customers/9999999999/campaigns/61'],
            ['1234567890', '61'], ['1234567890', "customers/1234567890/campaigns/61' OR true"]] as [$customerId, $resource]) {
            $client = $this->createMock(GoogleAdsClient::class);
            $client->expects($this->never())->method('getGoogleAdsServiceClient');
            $client->expects($this->never())->method('getAdGroupServiceClient');
            $service = $this->service(CreateSearchAdGroup::class, $client);

            $this->assertNull($service($customerId, $resource, 'New group'));
        }
    }

    public function test_search_ad_group_rechecks_paused_status_after_the_name_lookup_before_any_create(): void
    {
        $reads = 0;
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request) use (&$reads) {
            if (str_contains($request->getQuery(), 'FROM ad_group ')) {
                return $this->rows([]);
            }

            return $this->rows([new GoogleAdsRow(['campaign' => $this->parent(++$reads === 1 ? CampaignStatus::PAUSED : CampaignStatus::ENABLED)])]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $client->expects($this->never())->method('getAdGroupServiceClient');
        $service = $this->service(CreateSearchAdGroup::class, $client);

        $this->assertNull($service('1234567890', 'customers/1234567890/campaigns/61', 'New group'));
        $this->assertSame(2, $reads);
    }

    public function test_search_ad_group_can_be_staged_only_while_parent_is_still_paused(): void
    {
        $reads = 0;
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request) use (&$reads) {
            if (str_contains($request->getQuery(), 'FROM ad_group ')) {
                return $this->rows([]);
            }
            $reads++;

            return $this->rows([new GoogleAdsRow(['campaign' => $this->parent(CampaignStatus::PAUSED)])]);
        });
        $groups = $this->createMock(AdGroupServiceClient::class);
        $groups->expects($this->once())->method('mutateAdGroups')->willReturnCallback(function (MutateAdGroupsRequest $request): MutateAdGroupsResponse {
            $this->assertSame('1234567890', $request->getCustomerId());
            $this->assertSame('customers/1234567890/campaigns/61', $request->getOperations()[0]->getCreate()->getCampaign());
            // A provider default is temporary staging data, never a validated serving bid.
            $this->assertSame(0, $request->getOperations()[0]->getCreate()->getCpcBidMicros());

            return new MutateAdGroupsResponse(['results' => [new MutateAdGroupResult(['resource_name' => 'customers/1234567890/adGroups/62'])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $client->method('getAdGroupServiceClient')->willReturn($groups);
        $service = $this->service(CreateSearchAdGroup::class, $client);

        $this->assertSame('customers/1234567890/adGroups/62', $service('1234567890', 'customers/1234567890/campaigns/61', 'New group'));
        $this->assertSame(2, $reads);
    }

    private function parent(int $status, int $channel = AdvertisingChannelType::SEARCH, string $resource = 'customers/1234567890/campaigns/61'): Campaign
    {
        return new Campaign(['resource_name' => $resource, 'status' => $status, 'advertising_channel_type' => $channel]);
    }

    private function rows(array $rows): PagedListResponse
    {
        $response = $this->createMock(PagedListResponse::class);
        $response->method('getIterator')->willReturnCallback(function () use ($rows): \Generator {
            yield from $rows;
        });

        return $response;
    }

    /** @template T of BaseGoogleAdsService
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, GoogleAdsClient $client): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, new Customer(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed']));

        return $service;
    }
}
