<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\GetAndLinkLocationAssets;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AssetSetTypeEnum\AssetSetType;
use Google\Ads\GoogleAds\V26\Resources\AssetSet;
use Google\Ads\GoogleAds\V26\Resources\CampaignAssetSet;
use Google\Ads\GoogleAds\V26\Resources\CustomerAssetSet;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignAssetSetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetSetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetSetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetSetsResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsResponse;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Location assets inherit from the customer; explicit subsets use CampaignAssetSet. */
class GoogleLocationAssetSetsV26Test extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER = '1234567890';

    private const CAMPAIGN = 'customers/1234567890/campaigns/999';

    private const GROUP = 'customers/1234567890/assetSets/7';

    private MockTransport $transport;

    private GetAndLinkLocationAssets $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new MockTransport;
        $options = ['transport' => $this->transport, 'credentials' => new InsecureCredentials, 'disableRetries' => true];
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn(new GoogleAdsServiceClient($options));
        $client->method('getCampaignAssetSetServiceClient')->willReturn(new CampaignAssetSetServiceClient($options));
        $client->expects($this->never())->method('getCampaignAssetServiceClient');
        $this->service = (new \ReflectionClass(GetAndLinkLocationAssets::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($this->service, new Customer(['google_ads_customer_id' => self::CUSTOMER]));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($this->service, $client);
    }

    private function response(Message $response): void
    {
        $this->transport->addResponse($response, new MockStatus(Code::OK));
    }

    private function group(int $type = AssetSetType::STATIC_LOCATION_GROUP): void
    {
        $this->response(new SearchGoogleAdsResponse(['results' => [new GoogleAdsRow([
            'asset_set' => new AssetSet(['resource_name' => self::GROUP, 'type' => $type]),
        ])]]));
    }

    private function created(): void
    {
        $this->response(new MutateCampaignAssetSetsResponse(['results' => [new MutateCampaignAssetSetResult([
            'resource_name' => 'customers/1234567890/campaignAssetSets/999~7',
        ])]]));
    }

    public function test_customer_location_sync_is_detected_without_redundant_campaign_mutations(): void
    {
        $this->response(new SearchGoogleAdsResponse(['results' => [new GoogleAdsRow([
            'customer_asset_set' => new CustomerAssetSet(['asset_set' => 'customers/1234567890/assetSets/1']),
        ])]]));

        $this->assertSame(0, ($this->service)(self::CUSTOMER, self::CAMPAIGN));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $request = $calls[0]->getRequestObject();
        $this->assertInstanceOf(SearchGoogleAdsRequest::class, $request);
        $this->assertSame(self::CUSTOMER, $request->getCustomerId());
        $this->assertStringContainsString('FROM customer_asset_set', $request->getQuery());
        $this->assertStringContainsString("asset_set.type = 'LOCATION_SYNC'", $request->getQuery());
        $this->assertStringContainsString("customer_asset_set.status = 'ENABLED'", $request->getQuery());
    }

    public static function locationGroups(): array
    {
        return [[AssetSetType::STATIC_LOCATION_GROUP], [AssetSetType::BUSINESS_PROFILE_DYNAMIC_LOCATION_GROUP], [AssetSetType::CHAIN_DYNAMIC_LOCATION_GROUP]];
    }

    #[DataProvider('locationGroups')]
    public function test_an_explicit_location_group_uses_a_real_v26_campaign_asset_set_mutation(int $type): void
    {
        $this->group($type);
        $this->response(new SearchGoogleAdsResponse);
        $this->created();

        $this->assertSame(1, ($this->service)(self::CUSTOMER, self::CAMPAIGN, self::GROUP));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('FROM asset_set', $calls[0]->getRequestObject()->getQuery());
        $this->assertStringContainsString('FROM campaign_asset_set', $calls[1]->getRequestObject()->getQuery());
        $this->assertSame('/google.ads.googleads.v26.services.CampaignAssetSetService/MutateCampaignAssetSets', $calls[2]->getFuncCall());
        $request = $calls[2]->getRequestObject();
        $this->assertInstanceOf(MutateCampaignAssetSetsRequest::class, $request);
        $this->assertSame(self::CUSTOMER, $request->getCustomerId());
        $this->assertFalse($request->getValidateOnly());
        $this->assertFalse($request->getPartialFailure());
        $this->assertCount(1, $request->getOperations());
        $this->assertSame(self::CAMPAIGN, $request->getOperations()[0]->getCreate()->getCampaign());
        $this->assertSame(self::GROUP, $request->getOperations()[0]->getCreate()->getAssetSet());
    }

    public function test_a_repeated_location_group_link_is_a_read_only_noop(): void
    {
        $this->group();
        $this->response(new SearchGoogleAdsResponse);
        $this->created();
        $this->assertSame(1, ($this->service)(self::CUSTOMER, self::CAMPAIGN, self::GROUP));
        $this->group();
        $this->response(new SearchGoogleAdsResponse(['results' => [new GoogleAdsRow([
            'campaign_asset_set' => new CampaignAssetSet(['resource_name' => 'customers/1234567890/campaignAssetSets/999~7']),
        ])]]));

        $this->assertSame(0, ($this->service)(self::CUSTOMER, self::CAMPAIGN, self::GROUP));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(5, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.GoogleAdsService/Search', $calls[4]->getFuncCall());
        $this->assertStringContainsString("campaign_asset_set.asset_set = '".self::GROUP."'", $calls[4]->getRequestObject()->getQuery());
    }

    public function test_dry_run_validates_the_link_without_claiming_a_created_link(): void
    {
        $this->group();
        $this->response(new SearchGoogleAdsResponse);
        $this->response(new MutateCampaignAssetSetsResponse);

        $this->assertSame(0, $this->service->dryRun()->__invoke(self::CUSTOMER, self::CAMPAIGN, self::GROUP));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(3, $calls);
        $request = $calls[2]->getRequestObject();
        $this->assertInstanceOf(MutateCampaignAssetSetsRequest::class, $request);
        $this->assertTrue($request->getValidateOnly());
    }

    public function test_a_rejected_location_group_link_is_not_counted_as_success(): void
    {
        $this->group();
        $this->response(new SearchGoogleAdsResponse);
        $this->transport->addResponse(new MutateCampaignAssetSetsResponse, new MockStatus(Code::PERMISSION_DENIED, 'Link rejected'));

        $this->assertSame(0, ($this->service)(self::CUSTOMER, self::CAMPAIGN, self::GROUP));
        $this->assertCount(3, $this->transport->popReceivedCalls());
    }

    public function test_a_location_sync_or_unrelated_asset_set_is_not_attached_as_a_campaign_group(): void
    {
        $this->group(AssetSetType::LOCATION_SYNC);

        $this->assertSame(0, ($this->service)(self::CUSTOMER, self::CAMPAIGN, self::GROUP));
        $this->assertCount(1, $this->transport->popReceivedCalls());
    }

    public function test_an_asset_set_from_another_customer_is_refused_before_any_request(): void
    {
        $this->assertSame(0, ($this->service)(self::CUSTOMER, self::CAMPAIGN, 'customers/9999999999/assetSets/7'));
        $this->assertSame([], $this->transport->popReceivedCalls());
    }
}
