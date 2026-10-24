<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ApplyConversionValueRules;
use App\Services\GoogleAds\CommonServices\CallAssetPhoneNumber;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreateConversionValueRule;
use App\Services\GoogleAds\CommonServices\CreateConversionValueRuleSet;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\CallConversionReportingStateEnum\CallConversionReportingState;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleSetAttachmentTypeEnum\ValueRuleSetAttachmentType;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleSetDimensionEnum\ValueRuleSetDimension;
use Google\Ads\GoogleAds\V26\Services\Client\AssetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\ConversionValueRuleSetServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateAssetResult;
use Google\Ads\GoogleAds\V26\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAssetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetResult;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetsResponse;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Unsaved customers and mocked SDK transports: no database or provider access. */
class OptionalGoogleAdsAssetsTest extends TestCase
{
    private const CUSTOMER = '1234567890';

    private const CAMPAIGN = 'customers/1234567890/campaigns/7';

    public static function phoneNumbers(): array
    {
        return [
            'Australian number beats wrong US country' => ['+61 412 345 678', 'US', 'AU', '+61412345678'],
            'shared plus one resolves Canadian area code' => ['+1 416 555 0123', 'US', 'CA', '+14165550123'],
            'British international number beats AU country' => ['+44 20 7031 3000', 'AU', 'GB', '+442070313000'],
            'British national number uses stored region' => ['020 7031 3000', 'GB', 'GB', '+442070313000'],
            'Australian national number uses stored region' => ['(02) 9374 4000', 'AU', 'AU', '+61293744000'],
            'international number needs no fallback' => ['+61 412 345 678', null, 'AU', '+61412345678'],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_numbering_country_comes_from_the_phone_not_campaign_geography(string $phone, ?string $country, string $expectedCountry, string $expectedNumber): void
    {
        $this->assertSame(['phone_number' => $expectedNumber, 'country_code' => $expectedCountry],
            (new CallAssetPhoneNumber)->resolve($phone, $country));
    }

    public static function unresolvedNumbers(): array
    {
        return ['missing national country' => ['0412 345 678', null], 'unknown country' => ['0412 345 678', 'ZZ'],
            'too short' => ['123', 'US'], 'invalid international prefix' => ['+999123456789', 'AU'],
            'non-geographic number' => ['+80012345678', null], 'text is not a phone number' => ['Call me tomorrow', 'AU']];
    }

    #[DataProvider('unresolvedNumbers')]
    public function test_unresolved_numbers_are_not_guessed(string $phone, ?string $country): void
    {
        $this->assertNull((new CallAssetPhoneNumber)->resolve($phone, $country));
    }

    public function test_call_asset_transports_correct_country_phone_and_dry_run_without_enabling_call_conversions(): void
    {
        foreach ([false, true] as $dryRun) {
            $api = $this->createMock(AssetServiceClient::class);
            $api->expects($this->once())->method('mutateAssets')->willReturnCallback(function (MutateAssetsRequest $request) use ($dryRun) {
                $this->assertSame(self::CUSTOMER, $request->getCustomerId());
                $this->assertSame($dryRun, $request->getValidateOnly());
                $call = $request->getOperations()[0]->getCreate()->getCallAsset();
                $this->assertSame('AU', $call->getCountryCode());
                $this->assertSame('+61412345678', $call->getPhoneNumber());
                $this->assertSame(CallConversionReportingState::DISABLED, $call->getCallConversionReportingState());

                return new MutateAssetsResponse($dryRun ? [] : ['results' => [new MutateAssetResult(['resource_name' => 'customers/'.self::CUSTOMER.'/assets/9'])]]);
            });
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getAssetServiceClient')->willReturn($api);
            $service = $this->service(CreateCallAsset::class, $client)->dryRun($dryRun);
            $this->assertSame($dryRun ? null : 'customers/'.self::CUSTOMER.'/assets/9', $service(self::CUSTOMER, '+61 412 345 678', 'US'));
        }
    }

    public function test_invalid_phone_or_foreign_account_never_reaches_the_asset_transport(): void
    {
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($this->never())->method('getAssetServiceClient');
        $service = $this->service(CreateCallAsset::class, $client);
        $this->assertNull($service(self::CUSTOMER, '123', 'US'));
        $this->assertNull($service('9999999999', '+61412345678', 'AU'));
    }

    public function test_call_provider_type_errors_are_reported_and_remain_optional_failures(): void
    {
        Exceptions::fake();
        $error = new \TypeError('Mock transport failure');
        $api = $this->createMock(AssetServiceClient::class);
        $api->method('mutateAssets')->willThrowException($error);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAssetServiceClient')->willReturn($api);
        $this->assertNull(($this->service(CreateCallAsset::class, $client))(self::CUSTOMER, '+61412345678'));
        Exceptions::assertReported(\TypeError::class);
    }

    public function test_explicit_rule_set_uses_campaign_attachment_and_preserves_resources_dimensions_and_dry_run(): void
    {
        foreach ([false, true] as $dryRun) {
            $api = $this->createMock(ConversionValueRuleSetServiceClient::class);
            $api->expects($this->once())->method('mutateConversionValueRuleSets')->willReturnCallback(function (MutateConversionValueRuleSetsRequest $request) use ($dryRun) {
                $this->assertSame(self::CUSTOMER, $request->getCustomerId());
                $this->assertSame($dryRun, $request->getValidateOnly());
                $set = $request->getOperations()[0]->getCreate();
                $this->assertSame(ValueRuleSetAttachmentType::CAMPAIGN, $set->getAttachmentType());
                $this->assertSame(self::CAMPAIGN, $set->getCampaign());
                $this->assertSame(['customers/'.self::CUSTOMER.'/conversionValueRules/3'], iterator_to_array($set->getConversionValueRules()));
                $this->assertSame([ValueRuleSetDimension::DEVICE], iterator_to_array($set->getDimensions()));

                return new MutateConversionValueRuleSetsResponse($dryRun ? [] : ['results' => [new MutateConversionValueRuleSetResult(['resource_name' => 'customers/'.self::CUSTOMER.'/conversionValueRuleSets/5'])]]);
            });
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getConversionValueRuleSetServiceClient')->willReturn($api);
            $service = $this->service(CreateConversionValueRuleSet::class, $client)->dryRun($dryRun);
            $this->assertSame($dryRun ? null : 'customers/'.self::CUSTOMER.'/conversionValueRuleSets/5', $service(self::CUSTOMER,
                self::CAMPAIGN, ['customers/'.self::CUSTOMER.'/conversionValueRules/3'], [ValueRuleSetDimension::DEVICE]));
        }
    }

    public function test_rule_set_rejects_cross_account_resources_and_missing_configuration_before_transport(): void
    {
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($this->never())->method('getConversionValueRuleSetServiceClient');
        $service = $this->service(CreateConversionValueRuleSet::class, $client);
        $rule = 'customers/'.self::CUSTOMER.'/conversionValueRules/3';
        foreach ([['9999999999', self::CAMPAIGN, [$rule], [ValueRuleSetDimension::DEVICE]],
            [self::CUSTOMER, 'customers/9999999999/campaigns/7', [$rule], [ValueRuleSetDimension::DEVICE]],
            [self::CUSTOMER, self::CAMPAIGN, ['customers/9999999999/conversionValueRules/3'], [ValueRuleSetDimension::DEVICE]],
            [self::CUSTOMER, self::CAMPAIGN, [], [ValueRuleSetDimension::DEVICE]],
            [self::CUSTOMER, self::CAMPAIGN, [$rule], []],
            [self::CUSTOMER, self::CAMPAIGN, [$rule], [ValueRuleSetDimension::UNSPECIFIED]]] as $args) {
            $this->assertNull($service(...$args));
        }
    }

    public function test_automatic_deployment_creates_no_unapproved_value_rule_even_with_a_customer_match_list(): void
    {
        $rule = $this->createMock(CreateConversionValueRule::class);
        $rule->expects($this->never())->method('__invoke');
        $set = $this->createMock(CreateConversionValueRuleSet::class);
        $set->expects($this->never())->method('__invoke');
        $this->app->instance(CreateConversionValueRule::class, $rule);
        $this->app->instance(CreateConversionValueRuleSet::class, $set);
        foreach ([null, 'customers/'.self::CUSTOMER.'/userLists/8'] as $list) {
            $customer = new Customer(['google_ads_customer_id' => self::CUSTOMER]);
            $customer->setAttribute('google_ads_customer_match_list_id', $list);
            // No client at all: a deliberate skip must precede ensureClient().
            $service = $this->service(ApplyConversionValueRules::class, null, $customer);
            $this->assertFalse($service(self::CUSTOMER, self::CAMPAIGN, $customer));
        }
    }

    /**
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, ?GoogleAdsClient $client, ?Customer $customer = null): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service,
            $customer ?? new Customer(['google_ads_customer_id' => self::CUSTOMER, 'country' => 'US']));

        return $service;
    }
}
