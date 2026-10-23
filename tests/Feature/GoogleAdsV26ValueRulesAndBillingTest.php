<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\BillingSetupService;
use App\Services\GoogleAds\CommonServices\CreateConversionValueRule;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\TimeTypeEnum\TimeType;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleDeviceTypeEnum\ValueRuleDeviceType;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleOperationEnum\ValueRuleOperation;
use Google\Ads\GoogleAds\V26\Resources\PaymentsAccount;
use Google\Ads\GoogleAds\V26\Services\Client\BillingSetupServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\ConversionValueRuleServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\PaymentsAccountServiceClient;
use Google\Ads\GoogleAds\V26\Services\ListPaymentsAccountsRequest;
use Google\Ads\GoogleAds\V26\Services\ListPaymentsAccountsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateBillingSetupRequest;
use Google\Ads\GoogleAds\V26\Services\MutateBillingSetupResponse;
use Google\Ads\GoogleAds\V26\Services\MutateBillingSetupResult;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleResult;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRulesRequest;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRulesResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsResponse;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Decode real SDK responses and inspect serialized requests through a closed transport. */
class GoogleAdsV26ValueRulesAndBillingTest extends TestCase
{
    use DatabaseTransactions;

    private MockTransport $transport;

    private GoogleAdsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new MockTransport;
        $options = ['transport' => $this->transport, 'credentials' => new InsecureCredentials, 'disableRetries' => true];
        $this->client = $this->createMock(GoogleAdsClient::class);
        $this->client->method('getConversionValueRuleServiceClient')->willReturn(new ConversionValueRuleServiceClient($options));
        $this->client->method('getGoogleAdsServiceClient')->willReturn(new GoogleAdsServiceClient($options));
        $this->client->method('getPaymentsAccountServiceClient')->willReturn(new PaymentsAccountServiceClient($options));
        $this->client->method('getBillingSetupServiceClient')->willReturn(new BillingSetupServiceClient($options));
    }

    /**
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, new Customer);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $this->client);

        return $service;
    }

    private function response(Message $response): void
    {
        $this->transport->addResponse($response, new MockStatus(Code::OK));
    }

    public static function valueRuleConditions(): array
    {
        return [
            'audience addition' => [['action' => ['operation' => 'ADD', 'value' => 2.5], 'audience' => ['user_list' => 'customers/1234567890/userLists/42']], ValueRuleOperation::ADD, 'audience'],
            'device multiplier' => [['action' => ['operation' => 'MULTIPLY', 'value' => 1.75], 'device' => 'MOBILE'], ValueRuleOperation::MULTIPLY, 'device'],
            'geo fixed value' => [['action' => ['operation' => 'SET', 'value' => 10.0], 'geo_location' => ['geo_target_constant' => 'geoTargetConstants/1014044']], ValueRuleOperation::SET, 'geo'],
        ];
    }

    #[DataProvider('valueRuleConditions')]
    public function test_value_rule_actions_and_nested_conditions_reach_the_v26_sdk(array $config, int $operation, string $condition): void
    {
        $resource = 'customers/1234567890/conversionValueRules/5';
        $this->response(new MutateConversionValueRulesResponse(['results' => [new MutateConversionValueRuleResult(['resource_name' => $resource])]]));
        $service = $this->service(CreateConversionValueRule::class)->dryRun();

        $this->assertSame($resource, $service('1234567890', $config));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.ConversionValueRuleService/MutateConversionValueRules', $calls[0]->getFuncCall());
        $request = $calls[0]->getRequestObject();
        $this->assertInstanceOf(MutateConversionValueRulesRequest::class, $request);
        $this->assertSame('1234567890', $request->getCustomerId());
        $this->assertTrue($request->getValidateOnly());
        $this->assertCount(1, $request->getOperations());
        $rule = $request->getOperations()[0]->getCreate();
        $this->assertSame($operation, $rule->getAction()->getOperation());
        $this->assertSame($config['action']['value'], $rule->getAction()->getValue());
        if ($condition === 'audience') {
            $this->assertSame(['customers/1234567890/userLists/42'], iterator_to_array($rule->getAudienceCondition()->getUserLists()));
        } elseif ($condition === 'device') {
            $this->assertSame([ValueRuleDeviceType::MOBILE], iterator_to_array($rule->getDeviceCondition()->getDeviceTypes()));
        } else {
            $this->assertSame(['geoTargetConstants/1014044'], iterator_to_array($rule->getGeoLocationCondition()->getGeoTargetConstants()));
        }
    }

    private function paymentsAccount(): void
    {
        $this->response(new SearchGoogleAdsResponse);
        $this->response(new ListPaymentsAccountsResponse(['payments_accounts' => [new PaymentsAccount([
            'resource_name' => 'customers/9999999999/paymentsAccounts/1234567890123456',
            'payments_account_id' => '1234567890123456', 'name' => 'Spectra MCC billing', 'currency_code' => 'AUD',
        ])]]));
    }

    public function test_a_real_v26_payments_account_response_can_fund_a_subaccount_billing_setup(): void
    {
        $this->paymentsAccount();
        $this->response(new MutateBillingSetupResponse(['result' => new MutateBillingSetupResult([
            'resource_name' => 'customers/1234567890/billingSetups/8',
        ])]));

        $this->assertTrue($this->service(BillingSetupService::class)->setupBillingForSubAccount('1234567890'));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(3, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.PaymentsAccountService/ListPaymentsAccounts', $calls[1]->getFuncCall());
        $listing = $calls[1]->getRequestObject();
        $this->assertInstanceOf(ListPaymentsAccountsRequest::class, $listing);
        $this->assertSame('1234567890', $listing->getCustomerId());
        $this->assertSame('/google.ads.googleads.v26.services.BillingSetupService/MutateBillingSetup', $calls[2]->getFuncCall());
        $request = $calls[2]->getRequestObject();
        $this->assertInstanceOf(MutateBillingSetupRequest::class, $request);
        $this->assertSame('1234567890', $request->getCustomerId());
        $this->assertSame('customers/9999999999/paymentsAccounts/1234567890123456', $request->getOperation()->getCreate()->getPaymentsAccount());
        $this->assertSame(TimeType::NOW, $request->getOperation()->getCreate()->getStartTimeType());
    }

    public function test_a_billing_dry_run_lists_real_payments_accounts_without_issuing_an_unvalidated_mutation(): void
    {
        $this->paymentsAccount();

        $this->assertTrue($this->service(BillingSetupService::class)->dryRun()->setupBillingForSubAccount('1234567890'));

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.PaymentsAccountService/ListPaymentsAccounts', $calls[1]->getFuncCall());
    }

    public function test_an_empty_payments_listing_cannot_claim_a_billing_setup(): void
    {
        $this->response(new SearchGoogleAdsResponse);
        $this->response(new ListPaymentsAccountsResponse);

        $this->assertFalse($this->service(BillingSetupService::class)->setupBillingForSubAccount('1234567890'));
        $this->assertCount(2, $this->transport->popReceivedCalls());
    }
}
