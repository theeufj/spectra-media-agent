<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Support can look at the console; only a full admin can change it.
 *
 * Production had two roles — admin and user — with four admins and no users.
 * Anyone who needed to read AI costs or answer a ticket also had the ability to
 * delete customers and rotate the MCC credentials the entire platform
 * authenticates with. There was no way to grant the first without the second.
 *
 * The split is on HTTP method rather than a per-route list, because a list is
 * something somebody has to maintain, and a rule enforced in one place holds
 * where a rule remembered at every call site does not.
 */
class SupportRoleTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => $role])));

        return $user;
    }

    public function test_support_can_view_the_admin_console(): void
    {
        $this->actingAs($this->withRole('support'))
            ->get(route('admin.users.index'))
            ->assertSuccessful();
    }

    public function test_support_cannot_delete_a_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->withRole('support'))
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'Acme'])
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_support_cannot_change_platform_settings(): void
    {
        // Settings and MCC credentials are the reason the role exists.
        $this->actingAs($this->withRole('support'))
            ->post(route('admin.settings.update'), ['x' => 'y'])
            ->assertForbidden();
    }

    public function test_a_full_admin_can_still_do_both(): void
    {
        $admin = $this->withRole('admin');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertSuccessful();
        $this->actingAs($admin)->post(route('admin.settings.update'), ['x' => 'y'])->assertRedirect();
    }

    public function test_an_ordinary_user_reaches_nothing(): void
    {
        // The support check must not accidentally widen access: a user with
        // neither role fails both, rather than passing the looser one.
        $user = $this->withRole('user');

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.settings.update'), ['x' => 'y'])->assertForbidden();
    }

    public function test_the_role_helpers_do_not_conflate_the_two(): void
    {
        $support = $this->withRole('support');
        $admin = $this->withRole('admin');

        $this->assertTrue($support->canAccessAdmin());
        $this->assertFalse($support->isFullAdmin());

        $this->assertTrue($admin->canAccessAdmin());
        $this->assertTrue($admin->isFullAdmin());
    }
}
