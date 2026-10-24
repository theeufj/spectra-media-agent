<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\DSAServices\CreateDSACampaign;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Common\Metrics;
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
use Tests\TestCase;

class SupportedBiddingTransportTest extends TestCase
{
    /**
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, GoogleAdsClient $client): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, new Customer(['google_ads_customer_id' => '123']));

        return $service;
    }

    private function rows(array $rows): PagedListResponse
    {
        $response = $this->createMock(PagedListResponse::class);
        $response->method('getIterator')->willReturnCallback(function () use ($rows): \Generator {
            yield from $rows;
        });

        return $response;
    }

    public function test_dsa_creation_never_enables_retired_enhanced_cpc(): void
    {
        $budget = $this->createMock(CampaignBudgetServiceClient::class);
        $budget->method('mutateCampaignBudgets')->willReturn(new MutateCampaignBudgetsResponse(['results' => [
            new MutateCampaignBudgetResult(['resource_name' => 'customers/123/campaignBudgets/90']),
        ]]));
        $campaign = $this->createMock(CampaignServiceClient::class);
        $campaign->expects($this->once())->method('mutateCampaigns')->willReturnCallback(function (MutateCampaignsRequest $request): MutateCampaignsResponse {
            $created = $request->getOperations()[0]->getCreate();
            $this->assertTrue($created->hasManualCpc());
            $this->assertFalse($created->getManualCpc()->getEnhancedCpcEnabled());
            $this->assertFalse($created->getManualCpc()->hasEnhancedCpcEnabled());

            return new MutateCampaignsResponse(['results' => [new MutateCampaignResult(['resource_name' => 'customers/123/campaigns/456'])]]);
        });
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturn($this->rows([]));
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $client->method('getCampaignBudgetServiceClient')->willReturn($budget);
        $client->method('getCampaignServiceClient')->willReturn($campaign);
        $this->assertSame('customers/123/campaigns/456', $this->service(CreateDSACampaign::class, $client)('123', 'DSA', 'example.com', 'en', 50));
    }

    public function test_performance_reads_real_conversion_value_and_keeps_google_micros_units_explicit(): void
    {
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->expects($this->once())->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request): PagedListResponse {
            $this->assertStringContainsString('metrics.conversions_value', $request->getQuery());
            $this->assertStringContainsString("campaign.resource_name = 'customers/123/campaigns/456'", $request->getQuery());

            return $this->rows([new GoogleAdsRow(['metrics' => new Metrics(['cost_micros' => 600_000_000, 'conversions' => 30,
                'conversions_value' => 3000, 'cost_per_conversion' => 20_000_000])])]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $result = $this->service(GetCampaignPerformance::class, $client)('123', 'customers/123/campaigns/456', 'LAST_30_DAYS');
        $this->assertSame(3000.0, $result['conversion_value']);
        $this->assertEquals(20_000_000, $result['cost_per_conversion']);
        $this->assertEquals(600_000_000, $result['cost_micros']);
    }

    public function test_actual_google_state_normalizes_modern_target_strategies_and_ignores_unrelated_resources(): void
    {
        foreach ([['maximize_conversions', ['target_cpa_micros' => 18_000_000], 'MAXIMIZE_CONVERSIONS', 'TARGET_CPA'],
            ['maximize_conversion_value', ['target_roas' => 4.5], 'MAXIMIZE_CONVERSION_VALUE', 'TARGET_ROAS'],
            ['target_spend', ['cpc_bid_ceiling_micros' => 2_000_000], 'TARGET_SPEND', 'MAXIMIZE_CLICKS']] as [$field, $settings, $wire, $effective]) {
            $search = $this->createMock(GoogleAdsServiceClient::class);
            $row = new GoogleAdsRow;
            $row->mergeFromJsonString(json_encode(['campaign' => ['resourceName' => 'customers/123/campaigns/456', 'status' => 'ENABLED',
                'advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => $wire, $field => $settings],
                'customer' => ['currencyCode' => 'AUD']], JSON_THROW_ON_ERROR));
            $search->method('search')->willReturn($this->rows([$row]));
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getGoogleAdsServiceClient')->willReturn($search);
            $service = $this->service(UpdateCampaignBiddingStrategy::class, $client);
            $result = $service->readBiddingState('123', 'customers/123/campaigns/456');
            $this->assertSame($effective, $result['strategy']);
            $this->assertSame('AUD', $result['currency']);
            $this->assertSame('ENABLED', $result['status']);
            $this->assertNull($service->readBiddingState('123', 'customers/123/campaigns/999'));
        }
    }
}
