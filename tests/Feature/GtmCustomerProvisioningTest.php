<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GtmCustomerProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test',
            'services.gtm.platform_refresh_token' => 'test', 'services.gtm.platform_account_id' => '111']);
    }

    private function fakeContainer(string $name): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test']),
            'https://tagmanager.googleapis.com/tagmanager/v2/accounts/111/containers/222/workspaces' => Http::response([
                'workspace' => [['workspaceId' => '1', 'name' => 'Default Workspace']]]),
            'https://tagmanager.googleapis.com/tagmanager/v2/accounts/111/containers*' => Http::response([
                'name' => $name, 'path' => 'accounts/111/containers/222', 'publicId' => 'GTM-CUSTOMER'])]);
    }

    public function test_new_container_always_has_customer_uuid_even_legacy_caller_passes_false(): void
    {
        $customer = Customer::factory()->create(['service_type' => 'managed', 'gtm_container_id' => null]);
        $this->fakeContainer('Site to Spend '.$customer->uuid);
        $result = app(GTMContainerService::class)->provisionContainerForCustomer($customer, false);
        $this->assertTrue($result['success']);
        $this->assertSame($customer->uuid, $customer->fresh()->gtm_config['customer_uuid']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/containers')
            && $request['name'] === 'Site to Spend '.$customer->uuid);
    }

    public function test_exclusive_legacy_container_is_verified_and_reused_without_replacing_installed_snippet(): void
    {
        $customer = Customer::factory()->create(['service_type' => 'managed', 'gtm_account_id' => '111', 'gtm_container_id' => 'GTM-CUSTOMER',
            'gtm_workspace_id' => '1', 'gtm_installed' => true, 'gtm_config' => ['container_path' => 'accounts/111/containers/222']]);
        $this->fakeContainer($customer->name.' — Site to Spend');
        $result = app(GTMContainerService::class)->provisionContainerForCustomer($customer, true);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['reused']);
        $this->assertSame($customer->uuid, $customer->fresh()->gtm_config['customer_uuid']);
        $this->assertTrue($customer->fresh()->gtm_installed);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'tagmanager.googleapis.com'));
    }

    public function test_unverified_legacy_binding_is_preserved_and_does_not_create_replacement(): void
    {
        $customer = Customer::factory()->create(['service_type' => 'managed', 'gtm_account_id' => '111', 'gtm_container_id' => 'GTM-CUSTOMER',
            'gtm_workspace_id' => '1', 'gtm_installed' => true, 'gtm_config' => ['container_path' => 'accounts/111/containers/222']]);
        $this->fakeContainer('Someone else');
        $result = app(GTMContainerService::class)->provisionContainerForCustomer($customer, true);
        $this->assertFalse($result['success']);
        $this->assertSame('GTM-CUSTOMER', $customer->fresh()->gtm_container_id);
        $this->assertTrue($customer->fresh()->gtm_installed);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'tagmanager.googleapis.com'));
    }
}
