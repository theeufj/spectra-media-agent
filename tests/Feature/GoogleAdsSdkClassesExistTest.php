<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Google Ads SDK enum classes referenced by the linking code must exist.
 *
 * CreateCustomerClientLink imported CustomerClientLinkStatusEnum, which the SDK
 * has no such class for — the enum is ManagerLinkStatus. PHP resolves a class
 * constant only at the moment it is evaluated, so the file parsed, passed lint,
 * and raised a fatal Error the first time a link was actually requested. PHPStan
 * did flag it, and the finding had been written into the baseline rather than
 * fixed, which is what let it survive to production.
 *
 * A class-existence assertion catches this at test time instead of at the one
 * moment it matters, which is mid-onboarding for a real customer.
 */
class GoogleAdsSdkClassesExistTest extends TestCase
{
    public static function sdkClasses(): array
    {
        return [
            'manager link status' => [\Google\Ads\GoogleAds\V22\Enums\ManagerLinkStatusEnum\ManagerLinkStatus::class],
            'customer client link' => [\Google\Ads\GoogleAds\V22\Resources\CustomerClientLink::class],
            'customer client link operation' => [\Google\Ads\GoogleAds\V22\Services\CustomerClientLinkOperation::class],

            // Singular. The link service takes one operation, unlike most Google
            // Ads services — MutateCustomerClientLinksRequest does not exist.
            'mutate request (singular)' => [\Google\Ads\GoogleAds\V22\Services\MutateCustomerClientLinkRequest::class],

            // Service clients live under Services\Client, not Services.
            'link service client' => [\Google\Ads\GoogleAds\V22\Services\Client\CustomerClientLinkServiceClient::class],
            'campaign service client' => [\Google\Ads\GoogleAds\V22\Services\Client\CampaignServiceClient::class],
        ];
    }

    public function test_the_link_service_takes_a_single_operation(): void
    {
        // Every plural spelling of this call names something the SDK does not
        // define, and PHP only finds out at the moment of the call.
        // get_class_methods() rather than method_exists(): the latter folds to a
        // constant at analysis time, so the assertion would stop describing the
        // installed SDK and start describing what PHPStan already believes.
        $clientMethods = get_class_methods(\Google\Ads\GoogleAds\V22\Services\Client\CustomerClientLinkServiceClient::class);

        $this->assertContains('mutateCustomerClientLink', $clientMethods);
        $this->assertNotContains('mutateCustomerClientLinks', $clientMethods, 'the plural method does not exist');

        $requestMethods = get_class_methods(\Google\Ads\GoogleAds\V22\Services\MutateCustomerClientLinkRequest::class);

        $this->assertContains('setOperation', $requestMethods);
        $this->assertNotContains('setOperations', $requestMethods);
    }

    /** @dataProvider sdkClasses */
    public function test_the_sdk_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), $class.' is not a real class in the installed SDK');
    }

    public function test_the_pending_status_constant_resolves(): void
    {
        // The specific expression that fataled in production.
        $this->assertSame(4, \Google\Ads\GoogleAds\V22\Enums\ManagerLinkStatusEnum\ManagerLinkStatus::PENDING);
    }
}
