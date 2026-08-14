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
        ];
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
