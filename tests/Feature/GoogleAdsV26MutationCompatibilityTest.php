<?php

namespace Tests\Feature;

use App\Jobs\UploadOfflineConversions;
use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ApplyConversionValueRules;
use App\Services\GoogleAds\CommonServices\CreateCombinedAudience;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\CreateConversionValueRule;
use App\Services\GoogleAds\CommonServices\CreateConversionValueRuleSet;
use App\Services\GoogleAds\CommonServices\PromoteCampaignExperiment;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;
use Google\Ads\GoogleAds\V26\Enums\UserListLogicalRuleOperatorEnum\UserListLogicalRuleOperator;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleSetDimensionEnum\ValueRuleSetDimension;
use Google\Ads\GoogleAds\V26\Services\Client\ConversionActionServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\ConversionValueRuleSetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\ExperimentServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\UserListServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateConversionActionResult;
use Google\Ads\GoogleAds\V26\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateConversionActionsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetResult;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateUserListResult;
use Google\Ads\GoogleAds\V26\Services\MutateUserListsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateUserListsResponse;
use Google\Ads\GoogleAds\V26\Services\PromoteExperimentRequest;
use Google\ApiCore\OperationResponse;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Real v26 messages captured at the SDK boundary, without live requests. */
class GoogleAdsV26MutationCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER = '1234567890';

    private const CAMPAIGN = 'customers/1234567890/campaigns/7';

    public function test_default_conversion_category_reaches_the_sdk_as_the_default_enum_value(): void
    {
        $resource = 'customers/'.self::CUSTOMER.'/conversionActions/9';
        $api = $this->createMock(ConversionActionServiceClient::class);
        $api->expects($this->once())->method('mutateConversionActions')->willReturnCallback(function (MutateConversionActionsRequest $request) use ($resource) {
            $this->assertSame(self::CUSTOMER, $request->getCustomerId());
            $this->assertSame(2, $request->getOperations()[0]->getCreate()->getCategory());

            return new MutateConversionActionsResponse(['results' => [new MutateConversionActionResult(['resource_name' => $resource])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($this->emptySearch());
        $client->method('getConversionActionServiceClient')->willReturn($api);

        $this->assertSame($resource, ($this->service(CreateConversionAction::class, $client))(self::CUSTOMER, 'Fixture conversion'));
    }

    public function test_reviewed_website_action_is_explicitly_secondary_with_the_correct_counting_and_no_invented_revenue(): void
    {
        foreach ([ConversionActionCategory::SUBMIT_LEAD_FORM => ConversionActionCountingType::ONE_PER_CLICK,
            ConversionActionCategory::PURCHASE => ConversionActionCountingType::MANY_PER_CLICK] as $category => $counting) {
            $resource = 'customers/'.self::CUSTOMER.'/conversionActions/9';
            $api = $this->createMock(ConversionActionServiceClient::class);
            $api->expects($this->once())->method('mutateConversionActions')->willReturnCallback(function (MutateConversionActionsRequest $request) use ($resource, $category, $counting) {
                $action = $request->getOperations()[0]->getCreate();
                $this->assertTrue($action->hasPrimaryForGoal());
                $this->assertFalse($action->getPrimaryForGoal());
                $this->assertSame($category, $action->getCategory());
                $this->assertSame($counting, $action->getCountingType());
                $this->assertSame(0.0, $action->getValueSettings()->getDefaultValue());
                $this->assertSame('AUD', $action->getValueSettings()->getDefaultCurrencyCode());
                $this->assertFalse($action->getValueSettings()->getAlwaysUseDefaultValue());

                return new MutateConversionActionsResponse(['results' => [new MutateConversionActionResult(['resource_name' => $resource])]]);
            });
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getGoogleAdsServiceClient')->willReturn($this->emptySearch());
            $client->method('getConversionActionServiceClient')->willReturn($api);
            ($this->service(CreateConversionAction::class, $client))(self::CUSTOMER, 'Reviewed action', $category,
                ['primary_for_goal' => false, 'counting_type' => $counting, 'default_value' => 0,
                    'default_currency_code' => 'AUD', 'always_use_default_value' => false]);
        }
    }

    public function test_offline_upload_can_create_and_resolve_its_missing_conversion_action(): void
    {
        $customer = Customer::factory()->create(['website' => null, 'google_ads_customer_id' => self::CUSTOMER]);
        $resource = 'customers/'.self::CUSTOMER.'/conversionActions/9';
        $create = $this->createMock(CreateConversionAction::class);
        $create->expects($this->once())->method('__invoke')
            ->with(self::CUSTOMER, 'Offline Conversion', ConversionActionCategory::PBDEFAULT)
            ->willReturn($resource);
        $this->app->bind(CreateConversionAction::class, fn () => $create);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($this->emptySearch());
        $resolve = new \ReflectionMethod(UploadOfflineConversions::class, 'getConversionActionResourceName');

        $this->assertSame($resource, $resolve->invoke(new UploadOfflineConversions($customer->id), $client, self::CUSTOMER));
    }

    public function test_combined_audience_preserves_the_intersection_of_all_input_lists(): void
    {
        $lists = ['customers/'.self::CUSTOMER.'/userLists/1', 'customers/'.self::CUSTOMER.'/userLists/2'];
        $resource = 'customers/'.self::CUSTOMER.'/userLists/3';
        $api = $this->createMock(UserListServiceClient::class);
        $api->expects($this->once())->method('mutateUserLists')->willReturnCallback(function (MutateUserListsRequest $request) use ($lists, $resource) {
            $rule = $request->getOperations()[0]->getCreate()->getLogicalUserList()->getRules()[0];
            $this->assertSame(UserListLogicalRuleOperator::ALL, $rule->getOperator());
            $this->assertSame($lists, array_map(fn ($operand) => $operand->getUserList(), iterator_to_array($rule->getRuleOperands())));

            return new MutateUserListsResponse(['results' => [new MutateUserListResult(['resource_name' => $resource])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getUserListServiceClient')->willReturn($api);

        $this->assertSame($resource, ($this->service(CreateCombinedAudience::class, $client))(self::CUSTOMER, 'Fixture audience', $lists));
    }

    public function test_rule_set_transports_the_correct_dimension_enum_and_retains_its_unconditional_default(): void
    {
        $rule = 'customers/'.self::CUSTOMER.'/conversionValueRules/1';
        $resource = 'customers/'.self::CUSTOMER.'/conversionValueRuleSets/2';
        foreach ([null, [ValueRuleSetDimension::DEVICE, ValueRuleSetDimension::AUDIENCE]] as $dimensions) {
            $api = $this->createMock(ConversionValueRuleSetServiceClient::class);
            $api->expects($this->once())->method('mutateConversionValueRuleSets')->willReturnCallback(function (MutateConversionValueRuleSetsRequest $request) use ($dimensions, $rule, $resource) {
                $set = $request->getOperations()[0]->getCreate();
                $this->assertSame(self::CAMPAIGN, $set->getCampaign());
                $this->assertSame([$rule], iterator_to_array($set->getConversionValueRules()));
                $this->assertSame($dimensions ?? [ValueRuleSetDimension::NO_CONDITION], iterator_to_array($set->getDimensions()));

                return new MutateConversionValueRuleSetsResponse(['results' => [new MutateConversionValueRuleSetResult(['resource_name' => $resource])]]);
            });
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getConversionValueRuleSetServiceClient')->willReturn($api);
            $service = $this->service(CreateConversionValueRuleSet::class, $client, new Customer(['google_ads_customer_id' => self::CUSTOMER]));
            $result = $dimensions === null
                ? $service(self::CUSTOMER, self::CAMPAIGN, [$rule])
                : $service(self::CUSTOMER, self::CAMPAIGN, [$rule], $dimensions);

            $this->assertSame($resource, $result);
        }
    }

    public function test_automatic_orchestration_does_not_invent_conversion_value_adjustments(): void
    {
        foreach ([null, 'customers/'.self::CUSTOMER.'/userLists/1'] as $list) {
            $customer = new Customer(['google_ads_customer_id' => self::CUSTOMER]);
            $customer->setAttribute('google_ads_customer_match_list_id', $list);
            $create = $this->createMock(CreateConversionValueRule::class);
            $create->expects($this->never())->method('__invoke');
            $set = $this->createMock(CreateConversionValueRuleSet::class);
            $set->expects($this->never())->method('__invoke');
            $this->app->bind(CreateConversionValueRule::class, fn () => $create);
            $this->app->bind(CreateConversionValueRuleSet::class, fn () => $set);
            $service = $this->service(ApplyConversionValueRules::class, $this->createMock(GoogleAdsClient::class), $customer);

            $this->assertFalse($service(self::CUSTOMER, self::CAMPAIGN, $customer));
        }
    }

    public function test_experiment_promotion_sends_a_typed_request_in_live_and_dry_run_modes_without_polling(): void
    {
        foreach ([false, true] as $dryRun) {
            $operation = $this->createMock(OperationResponse::class);
            $operation->expects($this->never())->method('pollUntilComplete');
            $api = $this->createMock(ExperimentServiceClient::class);
            $api->expects($this->once())->method('promoteExperiment')->willReturnCallback(function (PromoteExperimentRequest $request) use ($dryRun, $operation) {
                $this->assertSame('customers/'.self::CUSTOMER.'/experiments/8', $request->getResourceName());
                $this->assertSame($dryRun, $request->getValidateOnly());

                return $operation;
            });
            $client = $this->createMock(GoogleAdsClient::class);
            $client->method('getExperimentServiceClient')->willReturn($api);
            $service = $this->service(PromoteCampaignExperiment::class, $client)->dryRun($dryRun);

            $this->assertTrue($service(self::CUSTOMER, 'customers/'.self::CUSTOMER.'/experiments/8'));
        }
    }

    private function emptySearch(): GoogleAdsServiceClient
    {
        $response = $this->createMock(PagedListResponse::class);
        $response->method('iterateAllElements')->willReturnCallback(function () {
            yield from [];
        });
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->method('search')->willReturn($response);

        return $search;
    }

    /**
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, GoogleAdsClient $client, ?Customer $customer = null): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, $customer ?? new Customer);

        return $service;
    }
}
