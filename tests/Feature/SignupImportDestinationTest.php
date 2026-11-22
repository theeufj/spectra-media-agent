<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\SignupImportDestination;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V26\Enums\EnhancedConversionsConfigEnum\EnhancedConversionsConfig;
use Google\Ads\GoogleAds\V26\Resources\ConversionAction;
use Google\Ads\GoogleAds\V26\Resources\ConversionTrackingSetting;
use Google\Ads\GoogleAds\V26\Resources\Customer;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsResponse;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Rpc\Code;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignupImportDestinationTest extends TestCase
{
    use DatabaseTransactions;

    public static function eligibility(): array
    {
        return [
            'enabled and every event' => [true, true, true, true],
            'terms unaccepted' => [false, true, true, false],
            'enhanced conversions off' => [true, false, true, false],
            'braid with one per click' => [true, true, false, null],
        ];
    }

    #[DataProvider('eligibility')]
    public function test_reads_effective_account_and_exact_import_action(bool $terms, bool $on, bool $many, ?bool $expected): void
    {
        $account = '1234567890';
        $resource = "customers/{$account}/conversionActions/456";
        Setting::set('conversion_resource_name.signup_import', $resource);
        $transport = new MockTransport;
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn(new GoogleAdsServiceClient(['credentials' => new InsecureCredentials, 'transport' => $transport]));
        $reader = (new \ReflectionClass(SignupImportDestination::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($reader, new \App\Models\Customer(['google_ads_customer_id' => $account]));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($reader, $client);
        $action = new ConversionAction(['resource_name' => $resource, 'owner_customer' => "customers/{$account}",
            'type' => ConversionActionType::UPLOAD_CLICKS, 'category' => ConversionActionCategory::SIGNUP, 'status' => ConversionActionStatus::ENABLED,
            'counting_type' => $many ? ConversionActionCountingType::MANY_PER_CLICK : ConversionActionCountingType::ONE_PER_CLICK]);
        $settings = new ConversionTrackingSetting(['google_ads_conversion_customer' => "customers/{$account}", 'accepted_customer_data_terms' => $terms,
            'effective_enablement_setting' => $on ? EnhancedConversionsConfig::ON : EnhancedConversionsConfig::OFF]);
        foreach ([new GoogleAdsRow(['conversion_action' => $action]), new GoogleAdsRow(['customer' => new Customer(['conversion_tracking_setting' => $settings])])] as $row) {
            $transport->addResponse(new SearchGoogleAdsResponse(['results' => [$row]]), new MockStatus(Code::OK));
        }
        if ($expected === null) {
            $this->expectExceptionMessage('GBRAID or WBRAID');
        }
        $this->assertSame($expected, $reader->allowsEmail($resource, ['wbraid' => 'fixture-braid']));
    }
}
