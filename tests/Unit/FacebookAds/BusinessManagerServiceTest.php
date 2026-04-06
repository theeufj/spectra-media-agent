<?php

namespace Tests\Unit\FacebookAds;

use App\Models\Customer;
use App\Services\FacebookAds\BusinessManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessManagerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.system_user_token' => 'test-system-token',
            'services.facebook.business_manager_id' => '123456',
        ]);

        $this->service = new BusinessManagerService();
    }

    public function test_is_configured_returns_true_when_credentials_set(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    public function test_is_configured_returns_false_when_token_missing(): void
    {
        config(['services.facebook.system_user_token' => '']);
        $service = new BusinessManagerService();

        $this->assertFalse($service->isConfigured());
    }

    public function test_is_configured_returns_false_when_bm_id_missing(): void
    {
        config(['services.facebook.business_manager_id' => '']);
        $service = new BusinessManagerService();

        $this->assertFalse($service->isConfigured());
    }

    public function test_verify_ad_account_access_success(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_1991968421347247*' => Http::response([
                'id' => 'act_1991968421347247',
                'name' => 'Proveably Ad Account',
                'account_status' => 1,
                'currency' => 'USD',
            ], 200),
        ]);

        $result = $this->service->verifyAdAccountAccess('1991968421347247');

        $this->assertTrue($result['success']);
        $this->assertEquals('Proveably Ad Account', $result['name']);
        $this->assertEquals('USD', $result['currency']);
    }

    public function test_verify_ad_account_access_strips_act_prefix(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_1991968421347247*' => Http::response([
                'id' => 'act_1991968421347247',
                'name' => 'Test Account',
                'account_status' => 1,
                'currency' => 'USD',
            ], 200),
        ]);

        $result = $this->service->verifyAdAccountAccess('act_1991968421347247');

        $this->assertTrue($result['success']);
    }

    public function test_verify_ad_account_access_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_999999999*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request',
                    'type' => 'GraphMethodException',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $result = $this->service->verifyAdAccountAccess('999999999');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported get request', $result['error']);
    }

    public function test_assign_ad_account_saves_to_customer(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_1991968421347247*' => Http::response([
                'id' => 'act_1991968421347247',
                'name' => 'Proveably',
                'account_status' => 1,
                'currency' => 'USD',
            ], 200),
        ]);

        $customer = Customer::factory()->create();

        $result = $this->service->assignAdAccount($customer, '1991968421347247');

        $this->assertTrue($result['success']);
        $this->assertEquals('1991968421347247', $result['account_id']);

        $customer->refresh();
        $this->assertEquals('1991968421347247', $customer->facebook_ads_account_id);
        $this->assertTrue($customer->facebook_bm_owned);
    }

    public function test_assign_ad_account_returns_early_if_already_assigned(): void
    {
        $customer = Customer::factory()->create([
            'facebook_ads_account_id' => '1991968421347247',
            'facebook_bm_owned' => true,
        ]);

        $result = $this->service->assignAdAccount($customer, '1991968421347247');

        $this->assertTrue($result['success']);
        $this->assertEquals('1991968421347247', $result['account_id']);
    }

    public function test_assign_ad_account_fails_when_not_configured(): void
    {
        config(['services.facebook.system_user_token' => '']);
        $service = new BusinessManagerService();

        $customer = Customer::factory()->create();

        $result = $service->assignAdAccount($customer, '1991968421347247');

        $this->assertFalse($result['success']);
        $this->assertEquals('Facebook Business Manager not configured.', $result['error']);
    }

    public function test_assign_ad_account_fails_when_verification_fails(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_999999*' => Http::response([
                'error' => ['message' => 'No access', 'code' => 100],
            ], 403),
        ]);

        $customer = Customer::factory()->create();

        $result = $this->service->assignAdAccount($customer, '999999');

        $this->assertFalse($result['success']);
        $customer->refresh();
        $this->assertNull($customer->facebook_ads_account_id);
    }

    public function test_verify_system_user_token_success(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/me*' => Http::response([
                'id' => '112233',
                'name' => 'Platform System User',
            ], 200),
        ]);

        $result = $this->service->verifySystemUserToken();

        $this->assertTrue($result['success']);
        $this->assertEquals('Platform System User', $result['name']);
    }

    public function test_verify_system_user_token_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/me*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $result = $this->service->verifySystemUserToken();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid OAuth', $result['error']);
    }

    public function test_get_system_user_token_returns_token(): void
    {
        $this->assertEquals('test-system-token', $this->service->getSystemUserToken());
    }

    public function test_get_system_user_token_returns_null_when_empty(): void
    {
        config(['services.facebook.system_user_token' => '']);
        $service = new BusinessManagerService();

        $this->assertNull($service->getSystemUserToken());
    }
}
