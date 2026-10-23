<?php

namespace Tests\Feature;

use App\Jobs\ScrapeCustomerWebsite;
use App\Models\Customer;
use App\Services\GTM\GTMDetectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagedGtmDetectionIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const EXTERNAL_TAG = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-EXTERNAL123"></script>';

    private function managed(Customer $customer): array
    {
        return [
            'gtm_container_id' => 'GTM-MANAGED123',
            'gtm_account_id' => '111',
            'gtm_workspace_id' => '3',
            'gtm_config' => ['container_path' => 'accounts/111/containers/222', 'customer_uuid' => $customer->uuid,
                'workspace_id' => '3', 'other_metadata' => 'preserved'],
        ];
    }

    private function scan(Customer $customer, string $html, ?callable $duringFetch = null): void
    {
        $job = new class($customer, $html, $duringFetch) extends ScrapeCustomerWebsite
        {
            public function __construct(Customer $customer, private string $html, private mixed $duringFetch)
            {
                parent::__construct($customer);
            }

            protected function fetchWebsiteContent(string $url): string
            {
                if ($this->duringFetch) {
                    ($this->duringFetch)();
                }

                return $this->html;
            }
        };
        $job->handle(new GTMDetectionService);
        Http::assertNothingSent();
    }

    public function test_detecting_an_external_tag_preserves_the_assigned_managed_container_and_configuration(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://business.example', 'gtm_installed' => false]);
        $assigned = $this->managed($customer);
        $customer->update($assigned);

        $this->scan($customer, self::EXTERNAL_TAG);

        $fresh = $customer->fresh();
        $this->assertSame($assigned['gtm_container_id'], $fresh->gtm_container_id);
        $this->assertSame($assigned['gtm_account_id'], $fresh->gtm_account_id);
        $this->assertSame($assigned['gtm_workspace_id'], $fresh->gtm_workspace_id);
        $this->assertSame($assigned['gtm_config'], array_diff_key($fresh->gtm_config, ['detected_container_id' => true]));
        $this->assertSame('GTM-EXTERNAL123', $fresh->gtm_config['detected_container_id']);
        $this->assertTrue($fresh->gtm_detected);
        $this->assertNotNull($fresh->gtm_detected_at);
        $this->assertFalse($fresh->gtm_installed);
    }

    public function test_a_scan_without_a_tag_cannot_clear_a_provisioned_container(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://business.example']);
        $assigned = $this->managed($customer);
        $customer->update($assigned);

        $this->scan($customer, '<html><body>No tracking tag</body></html>');

        $fresh = $customer->fresh();
        $this->assertSame($assigned['gtm_container_id'], $fresh->gtm_container_id);
        $this->assertSame($assigned['gtm_config']['container_path'], $fresh->gtm_config['container_path']);
        $this->assertNull($fresh->gtm_config['detected_container_id']);
        $this->assertFalse($fresh->gtm_detected);
    }

    public function test_a_container_provisioned_during_the_scan_is_not_replaced_by_the_observed_external_tag(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://business.example']);
        $assigned = $this->managed($customer);

        $this->scan($customer, self::EXTERNAL_TAG, fn () => Customer::findOrFail($customer->id)->update($assigned));

        $fresh = $customer->fresh();
        $this->assertSame($assigned['gtm_container_id'], $fresh->gtm_container_id);
        $this->assertSame($assigned['gtm_config']['container_path'], $fresh->gtm_config['container_path']);
        $this->assertSame($customer->uuid, $fresh->gtm_config['customer_uuid']);
        $this->assertSame('GTM-EXTERNAL123', $fresh->gtm_config['detected_container_id']);
    }

    public function test_observing_a_tag_does_not_assign_it_to_an_unprovisioned_customer(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://business.example']);

        $this->scan($customer, self::EXTERNAL_TAG);

        $fresh = $customer->fresh();
        $this->assertNull($fresh->gtm_container_id);
        $this->assertNull($fresh->gtm_account_id);
        $this->assertSame('GTM-EXTERNAL123', $fresh->gtm_config['detected_container_id']);
        $this->assertFalse($fresh->gtm_installed);
    }

    public function test_a_website_changed_during_the_fetch_does_not_receive_observations_from_the_previous_site(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://old.example', 'gtm_detected' => false]);

        $this->scan($customer, self::EXTERNAL_TAG,
            fn () => Customer::whereKey($customer->id)->update(['website' => 'https://new.example']));

        $fresh = $customer->fresh();
        $this->assertSame('https://new.example', $fresh->website);
        $this->assertFalse($fresh->gtm_detected);
        $this->assertNull($fresh->gtm_container_id);
        $this->assertEmpty($fresh->gtm_config);
    }
}
