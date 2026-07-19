<?php

namespace Tests\Feature\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * @group integration
 * @group google-ads
 */
class ConversionActionsIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer   = Customer::whereNotNull('google_ads_customer_id')->firstOrFail();
        $this->customerId = $this->customer->cleanGoogleCustomerId();
    }

    public function test_creates_conversion_action_and_returns_resource_name(): void
    {
        $service  = new CreateConversionAction($this->customer);
        $resource = $service->create(
            customerId: $this->customerId,
            name:       'PHPUnit Test Conversion ' . now()->timestamp,
            type:       'WEBPAGE',
            category:   'SUBMIT_LEAD_FORM',
            value:      10.0,
        );

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/conversionActions/', $resource);
    }

    public function test_gets_conversion_action_details(): void
    {
        $service  = new GetConversionActionDetails($this->customer);
        $actions  = $service->get($this->customerId);

        $this->assertIsArray($actions);
        // Account should have at least one conversion action
        $this->assertNotEmpty($actions);

        $first = reset($actions);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('category', $first);
    }

    public function test_provision_conversions_command_runs_successfully(): void
    {
        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('...');
        }

        $exitCode = Artisan::call('conversions:provision');

        $this->assertEquals(0, $exitCode);
    }

    public function test_provision_command_stores_labels_in_settings(): void
    {
        Artisan::call('conversions:provision');

        // At least one of the conversion labels should be set after provisioning
        $labels = ['signup', 'try_now', 'pricing_visit', 'sandbox_launched'];
        $found  = false;

        foreach ($labels as $label) {
            if (\App\Models\Setting::get("conversion_label.{$label}")) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected at least one conversion label to be stored in settings after provisioning');
    }

    public function test_provision_command_is_idempotent(): void
    {
        // Running twice should not throw
        Artisan::call('conversions:provision');
        $exitCode = Artisan::call('conversions:provision');

        $this->assertEquals(0, $exitCode);
    }
}
