<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Customers\DeactivateCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Deleting a customer must stop their spending first.
 *
 * This was one line — ActivityLogger, then `$customer->delete()` — against a
 * model with no soft deletes and an empty deleted() observer. The campaigns on
 * Google, Facebook, Microsoft and LinkedIn carried on running and charging,
 * while the rows recording which campaigns those were had just been destroyed.
 *
 * The action most likely to be taken *because* someone wanted the spending to
 * stop was the one that removed every means of stopping it.
 */
class CustomerDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    private function customerWithLiveCampaign(): Customer
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pty Ltd']);

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);

        return $customer;
    }

    public function test_campaigns_are_paused_before_the_record_goes(): void
    {
        $customer = $this->customerWithLiveCampaign();

        $deactivator = Mockery::mock(DeactivateCustomerService::class);
        $deactivator->shouldReceive('pauseAllCampaigns')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->id === $customer->id))
            ->andReturn(['paused' => 1, 'failed' => 0, 'errors' => []]);

        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->actingAs($this->admin())
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'Acme Pty Ltd']);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_whose_ads_could_not_be_stopped_is_not_deleted(): void
    {
        // Deleting anyway would leave a campaign spending with no record of who
        // it belongs to — the exact situation this guard exists to prevent.
        $customer = $this->customerWithLiveCampaign();

        $deactivator = Mockery::mock(DeactivateCustomerService::class);
        $deactivator->shouldReceive('pauseAllCampaigns')
            ->andReturn(['paused' => 0, 'failed' => 1, 'errors' => ['Campaign 1: Google: quota exceeded']]);

        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->actingAs($this->admin())
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'Acme Pty Ltd']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_deletion_requires_the_name_typed_exactly(): void
    {
        // A dialog is advice. This is the part an accidental or forged request
        // cannot satisfy.
        $customer = $this->customerWithLiveCampaign();

        $deactivator = Mockery::mock(DeactivateCustomerService::class);
        $deactivator->shouldNotReceive('pauseAllCampaigns');
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->actingAs($this->admin())
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'acme pty ltd']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_customer_can_be_restored(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->actingAs($this->admin())->post(route('admin.customers.restore', $customer->id));

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_customer_is_hidden_from_normal_queries(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->assertNull(Customer::find($customer->id));
        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_only_an_admin_can_delete(): void
    {
        $customer = $this->customerWithLiveCampaign();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'Acme Pty Ltd'])
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }
}
