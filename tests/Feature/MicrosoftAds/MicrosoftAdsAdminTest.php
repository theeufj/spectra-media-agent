<?php

namespace Tests\Feature\MicrosoftAds;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicrosoftAdsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regularUser;
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

        $this->regularUser = User::factory()->create();
        $this->regularUser->customers()->attach($this->customer->id, ['role' => 'marketing']);
    }

    public function test_admin_can_update_microsoft_ads_ids(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('admin.customers.update-microsoft', $this->customer), [
                'microsoft_ads_customer_id' => '123456789',
                'microsoft_ads_account_id' => '987654321',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->customer->refresh();
        $this->assertEquals('123456789', $this->customer->microsoft_ads_customer_id);
        $this->assertEquals('987654321', $this->customer->microsoft_ads_account_id);
    }

    public function test_admin_can_clear_microsoft_ads_ids(): void
    {
        $this->customer->update([
            'microsoft_ads_customer_id' => '123456789',
            'microsoft_ads_account_id' => '987654321',
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.customers.update-microsoft', $this->customer), [
                'microsoft_ads_customer_id' => '',
                'microsoft_ads_account_id' => '',
            ]);

        $response->assertRedirect();

        $this->customer->refresh();
        $this->assertNull($this->customer->microsoft_ads_customer_id);
        $this->assertNull($this->customer->microsoft_ads_account_id);
    }

    public function test_non_admin_cannot_update_microsoft_ads_ids(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->put(route('admin.customers.update-microsoft', $this->customer), [
                'microsoft_ads_customer_id' => '123456789',
                'microsoft_ads_account_id' => '987654321',
            ]);

        $response->assertForbidden();
    }

    public function test_microsoft_ads_ids_validation_rejects_long_values(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('admin.customers.update-microsoft', $this->customer), [
                'microsoft_ads_customer_id' => str_repeat('1', 51),
                'microsoft_ads_account_id' => '987654321',
            ]);

        $response->assertSessionHasErrors('microsoft_ads_customer_id');
    }
}
