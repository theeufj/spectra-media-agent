<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\DisplayBiddingConfiguration;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignBudgetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisplayInitialBiddingTransportTest extends TestCase
{
    /**
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, ?GoogleAdsClient $client = null): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, new Customer(['google_ads_customer_id' => '123']));

        return $service;
    }

    public function test_campaign_without_explicit_supported_bidding_is_rejected_before_budget_or_campaign_requests(): void
    {
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($this->never())->method('getCampaignBudgetServiceClient');
        $client->expects($this->never())->method('getCampaignServiceClient');
        $client->expects($this->never())->method('getGoogleAdsServiceClient');
        $service = $this->service(CreateDisplayCampaign::class, $client);
        foreach ([[], ['biddingStrategyType' => 'MANUAL_CPC'], ['biddingStrategyType' => 'TARGET_CPA', 'targetCpaMicros' => 0]] as $data) {
            $this->assertNull($service('123', ['businessName' => 'Review Display', 'budget' => 5, ...$data]));
        }
    }

    public static function automatedStrategies(): array
    {
        return [['MAXIMIZE_CONVERSIONS', null], ['TARGET_CPA', 2_000_000]];
    }

    #[DataProvider('automatedStrategies')]
    public function test_campaign_is_created_paused_with_exact_supported_bidding_and_no_manual_fallback(string $type, ?int $target): void
    {
        $budget = $this->createMock(CampaignBudgetServiceClient::class);
        $budget->method('mutateCampaignBudgets')->willReturn(new MutateCampaignBudgetsResponse(['results' => [
            new MutateCampaignBudgetResult(['resource_name' => 'customers/123/campaignBudgets/90']),
        ]]));
        $campaign = $this->createMock(CampaignServiceClient::class);
        $campaign->expects($this->once())->method('mutateCampaigns')->willReturnCallback(function (MutateCampaignsRequest $request) use ($type, $target): MutateCampaignsResponse {
            $created = $request->getOperations()[0]->getCreate();
            $this->assertSame(3, $created->getStatus());
            $this->assertFalse($created->hasManualCpc());
            if ($type === 'TARGET_CPA') {
                $this->assertSame($target, $created->getTargetCpa()->getTargetCpaMicros());
            } else {
                $this->assertTrue($created->hasMaximizeConversions());
            }

            return new MutateCampaignsResponse(['results' => [new MutateCampaignResult(['resource_name' => 'customers/123/campaigns/61'])]]);
        });
        $empty = $this->createMock(PagedListResponse::class);
        $empty->method('getIterator')->willReturnCallback(function (): \Generator {
            yield from [];
        });
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturn($empty);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getCampaignBudgetServiceClient')->willReturn($budget);
        $client->method('getCampaignServiceClient')->willReturn($campaign);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $service = $this->service(CreateDisplayCampaign::class, $client);

        $this->assertSame('customers/123/campaigns/61', $service('123', ['businessName' => 'Explicit Display', 'budget' => 5,
            'startDate' => '2026-11-01', 'endDate' => '2026-11-30', 'biddingStrategyType' => $type, 'targetCpaMicros' => $target]));
    }

    public function test_ad_group_rejects_manual_live_and_unknown_campaigns_before_any_mutation(): void
    {
        $service = $this->service(CreateDisplayAdGroup::class);
        foreach ([[], ['status' => 'PAUSED', 'channel' => 'DISPLAY', 'bidding_strategy' => 'MANUAL_CPC'],
            ['status' => 'ENABLED', 'channel' => 'DISPLAY', 'bidding_strategy' => 'MAXIMIZE_CONVERSIONS']] as $actual) {
            $read = $this->createMock(DisplayBiddingConfiguration::class);
            $read->method('read')->willReturn($actual);
            $this->app->bind(DisplayBiddingConfiguration::class, fn () => $read);
            $this->assertNull($service('123', 'customers/123/campaigns/61', 'Review Display'));
        }
    }

    public function test_readback_is_scoped_and_reports_actual_paused_automated_bidding_budget(): void
    {
        $row = new GoogleAdsRow(['campaign' => new Campaign(['resource_name' => 'customers/123/campaigns/61',
            'status' => 3, 'advertising_channel_type' => 3, 'bidding_strategy_type' => 10]),
            'campaign_budget' => new CampaignBudget(['amount_micros' => 5_000_000, 'explicitly_shared' => false])]);
        $response = $this->createMock(PagedListResponse::class);
        $response->method('getIterator')->willReturnCallback(function () use ($row): \Generator {
            yield $row;
        });
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->expects($this->once())->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request) use ($response) {
            $this->assertSame('123', $request->getCustomerId());
            $this->assertStringContainsString("campaign.resource_name = 'customers/123/campaigns/61'", $request->getQuery());
            $this->assertStringContainsString('campaign.bidding_strategy_type', $request->getQuery());

            return $response;
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $read = $this->service(DisplayBiddingConfiguration::class, $client);
        $actual = $read->read('123', 'customers/123/campaigns/61');
        $this->assertTrue(DisplayBiddingConfiguration::isPausedAutomated($actual));
        $this->assertSame(5_000_000, $actual['daily_budget_micros']);
        $this->expectException(\InvalidArgumentException::class);
        $read->read('123', 'customers/999/campaigns/61');
    }
}
