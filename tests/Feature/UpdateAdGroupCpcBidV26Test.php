<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\UpdateAdGroupCpcBid;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsResponse;
use Google\ApiCore\ApiException;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Rpc\Code;
use Google\Rpc\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real generated SDK requests and transport, without credentials or external requests. */
class UpdateAdGroupCpcBidV26Test extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER = '1234567890';

    private const GROUP = 'customers/1234567890/adGroups/987';

    private MockTransport $transport;

    private UpdateAdGroupCpcBid $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new MockTransport;
        $api = new AdGroupServiceClient(['transport' => $this->transport,
            'credentials' => new InsecureCredentials, 'disableRetries' => true]);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupServiceClient')->willReturn($api);
        $this->service = (new \ReflectionClass(UpdateAdGroupCpcBid::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($this->service,
            new Customer(['google_ads_customer_id' => '123-456-7890', 'currency_code' => 'AUD']));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($this->service, $client);
    }

    private function accepted(): void
    {
        $this->transport->addResponse(new MutateAdGroupsResponse(['results' => [
            new MutateAdGroupResult(['resource_name' => self::GROUP]),
        ]]), new MockStatus(Code::OK));
    }

    public function test_aud_bid_uses_integer_micros_and_changes_only_the_requested_cpc_field(): void
    {
        $this->accepted();

        $this->assertTrue(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.AdGroupService/MutateAdGroups', $calls[0]->getFuncCall());
        $request = $calls[0]->getRequestObject();
        $this->assertInstanceOf(MutateAdGroupsRequest::class, $request);
        $this->assertSame(self::CUSTOMER, $request->getCustomerId());
        $this->assertFalse($request->getValidateOnly());
        $this->assertFalse($request->getPartialFailure());
        $this->assertCount(1, $request->getOperations());
        $operation = $request->getOperations()[0];
        $this->assertSame(['cpc_bid_micros'], iterator_to_array($operation->getUpdateMask()->getPaths()));
        $this->assertSame(['resourceName' => self::GROUP, 'cpcBidMicros' => '1230000'],
            json_decode($operation->getUpdate()->serializeToJsonString(), true));
        $this->assertFalse($operation->hasCreate());
        $this->assertSame('', $operation->getRemove());
    }

    public function test_one_cent_is_ten_thousand_micros_without_currency_conversion_or_rounding(): void
    {
        $this->accepted();

        $this->assertTrue(($this->service)(self::CUSTOMER, self::GROUP, 10_000));

        $request = $this->transport->popReceivedCalls()[0]->getRequestObject();
        $this->assertSame(10_000, $request->getOperations()[0]->getUpdate()->getCpcBidMicros());
    }

    public function test_validate_only_is_explicit_and_does_not_require_a_created_result(): void
    {
        $this->transport->addResponse(new MutateAdGroupsResponse, new MockStatus(Code::OK));

        $this->assertTrue(($this->service->dryRun())(self::CUSTOMER, self::GROUP, 500_000));

        $request = $this->transport->popReceivedCalls()[0]->getRequestObject();
        $this->assertTrue($request->getValidateOnly());
        $this->assertSame(['cpc_bid_micros'], iterator_to_array($request->getOperations()[0]->getUpdateMask()->getPaths()));
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_cross_account_inputs_are_refused_before_transport(string $account, string $resource, int $bid): void
    {
        $this->assertFalse(($this->service)($account, $resource, $bid));
        $this->assertCount(0, $this->transport->popReceivedCalls());
    }

    public static function invalidInputs(): array
    {
        return [
            'dashed request account' => ['123-456-7890', self::GROUP, 10_000],
            'short account' => ['123', 'customers/123/adGroups/987', 10_000],
            'different bound account' => ['9876543210', 'customers/9876543210/adGroups/987', 10_000],
            'foreign resource account' => [self::CUSTOMER, 'customers/9876543210/adGroups/987', 10_000],
            'wrong resource kind' => [self::CUSTOMER, 'customers/1234567890/campaigns/987', 10_000],
            'non numeric group' => [self::CUSTOMER, 'customers/1234567890/adGroups/not-a-number', 10_000],
            'trailing resource data' => [self::CUSTOMER, self::GROUP."\n", 10_000],
            'zero group' => [self::CUSTOMER, 'customers/1234567890/adGroups/0', 10_000],
            'zero bid' => [self::CUSTOMER, self::GROUP, 0],
            'negative bid' => [self::CUSTOMER, self::GROUP, -10_000],
            'fractional cent' => [self::CUSTOMER, self::GROUP, 10_001],
        ];
    }

    public function test_provider_rejection_is_reported_and_returns_false_without_another_mutation(): void
    {
        Exceptions::fake();
        $this->transport->addResponse(new MutateAdGroupsResponse, new MockStatus(Code::PERMISSION_DENIED, 'Bid update denied'));

        $this->assertFalse(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        Exceptions::assertReported(ApiException::class);
        $this->assertCount(1, $this->transport->popReceivedCalls());
    }

    public function test_an_empty_live_response_does_not_claim_the_bid_was_updated(): void
    {
        Exceptions::fake();
        $this->transport->addResponse(new MutateAdGroupsResponse, new MockStatus(Code::OK));

        $this->assertFalse(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        Exceptions::assertReported(\UnexpectedValueException::class);
    }

    public function test_a_response_for_a_different_group_is_not_accepted_as_confirmation(): void
    {
        Exceptions::fake();
        $this->transport->addResponse(new MutateAdGroupsResponse(['results' => [
            new MutateAdGroupResult(['resource_name' => 'customers/1234567890/adGroups/988']),
        ]]), new MockStatus(Code::OK));

        $this->assertFalse(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        Exceptions::assertReported(\UnexpectedValueException::class);
    }

    public function test_an_sdk_type_error_is_reported_without_a_fallback_mutation(): void
    {
        Exceptions::fake();
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupServiceClient')->willThrowException(new \TypeError('Invalid SDK response'));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($this->service, $client);

        $this->assertFalse(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        Exceptions::assertReported(\TypeError::class);
        $this->assertCount(0, $this->transport->popReceivedCalls());
    }

    public function test_partial_failure_cannot_be_mistaken_for_a_successful_bid_update(): void
    {
        Exceptions::fake();
        $this->transport->addResponse(new MutateAdGroupsResponse(['results' => [new MutateAdGroupResult(['resource_name' => self::GROUP])],
            'partial_failure_error' => new Status(['code' => Code::INVALID_ARGUMENT, 'message' => 'Rejected bid'])]), new MockStatus(Code::OK));

        $this->assertFalse(($this->service)(self::CUSTOMER, self::GROUP, 1_230_000));

        Exceptions::assertReported(\UnexpectedValueException::class);
    }
}
