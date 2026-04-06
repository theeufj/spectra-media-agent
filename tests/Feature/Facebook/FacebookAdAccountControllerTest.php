<?php

namespace Tests\Feature\Facebook;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\FacebookAds\BusinessManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookAdAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($role);

        $this->customer = Customer::factory()->create([
            'name' => 'Test Business',
        ]);
        $this->admin->customers()->attach($this->customer->id, ['role' => 'owner']);

        session(['active_customer_id' => $this->customer->id]);

        // Configure BM credentials for tests
        config([
            'services.facebook.system_user_token' => 'test-system-token',
            'services.facebook.business_manager_id' => '123456',
        ]);
    }

    public function test_show_renders_facebook_setup_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('customers.facebook.setup', $this->customer));

        $response->assertOk();
    }

    public function test_assign_links_ad_account_to_customer(): void
    {
        Http::fake([
            'https://graph.facebook.com/v18.0/act_1991968421347247*' => Http::response([
                'id' => 'act_1991968421347247',
                'name' => 'Proveably Ad Account',
                'account_status' => 1,
                'currency' => 'USD',
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('customers.facebook.assign', $this->customer), [
                'ad_account_id' => '1991968421347247',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->customer->refresh();
        $this->assertEquals('1991968421347247', $this->customer->facebook_ads_account_id);
        $this->assertTrue($this->customer->facebook_bm_owned);
    }

    public function test_assign_rejects_non_numeric_account_id(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('customers.facebook.assign', $this->customer), [
                'ad_account_id' => 'act_123abc',
            ]);

        $response->assertSessionHasErrors('ad_account_id');
    }

    public function test_assign_returns_error_when_api_rejects(): void
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

        $response = $this->actingAs($this->admin)
            ->post(route('customers.facebook.assign', $this->customer), [
                'ad_account_id' => '999999999',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->customer->refresh();
        $this->assertNull($this->customer->facebook_ads_account_id);
    }

    public function test_verify_confirms_access_to_linked_account(): void
    {
        $this->customer->update([
            'facebook_ads_account_id' => '1991968421347247',
            'facebook_bm_owned' => true,
        ]);

        Http::fake([
            'https://graph.facebook.com/v18.0/act_1991968421347247*' => Http::response([
                'id' => 'act_1991968421347247',
                'name' => 'Proveably Ad Account',
                'account_status' => 1,
                'currency' => 'USD',
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('customers.facebook.verify', $this->customer));

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_verify_returns_error_when_no_account_linked(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('customers.facebook.verify', $this->customer));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'No ad account linked yet.');
    }

    public function test_unauthenticated_user_cannot_access_facebook_setup(): void
    {
        $response = $this->get(route('customers.facebook.setup', $this->customer));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_user_cannot_access_facebook_setup(): void
    {
        $nonAdmin = User::factory()->create();
        $nonAdmin->customers()->attach($this->customer->id, ['role' => 'member']);
        session(['active_customer_id' => $this->customer->id]);

        $response = $this->actingAs($nonAdmin)
            ->get(route('customers.facebook.setup', $this->customer));

        $response->assertForbidden();
    }
}
