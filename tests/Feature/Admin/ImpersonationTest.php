<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin who starts impersonating has to be able to stop.
 *
 * ImpersonationMiddleware runs in the web group and swaps the authenticated user
 * to the person being impersonated — who is, by definition, not an admin. Every
 * admin-gated route therefore 403s from the moment impersonation begins, and the
 * stop route was one of them, so the only way back was to log out entirely.
 *
 * The escape hatch is safe on `auth` alone because the controller reads
 * impersonate_admin_id from the session: a user who was never impersonated gains
 * nothing by calling it.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    private function customerUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        return $user;
    }

    public function test_an_admin_can_stop_impersonating(): void
    {
        // The regression: this returned 403 because the stop route was
        // admin-gated and the caller was, at that moment, not an admin.
        $admin = $this->admin();
        $target = $this->customerUser();

        $this->actingAs($admin)->post(route('admin.impersonation.start', $target));

        $this->assertSame($target->id, session('impersonate_user_id'));

        $this->post(route('admin.impersonation.stop'))->assertRedirect();

        $this->assertNull(session('impersonate_user_id'));
    }

    public function test_stopping_restores_the_admin(): void
    {
        $admin = $this->admin();
        $target = $this->customerUser();

        $this->actingAs($admin)->post(route('admin.impersonation.start', $target));
        $this->post(route('admin.impersonation.stop'));

        $this->assertSame($admin->id, auth()->id());
        $this->assertTrue(auth()->user()->hasRole('admin'));
    }

    public function test_admin_pages_work_again_after_stopping(): void
    {
        // The point of the fix: not merely that stop returns 200, but that the
        // admin is genuinely back to being an admin afterwards.
        $admin = $this->admin();
        $target = $this->customerUser();

        $this->actingAs($admin)->post(route('admin.impersonation.start', $target));
        $this->get(route('admin.users.index'))->assertForbidden();

        $this->post(route('admin.impersonation.stop'));
        $this->get(route('admin.users.index'))->assertSuccessful();
    }

    public function test_a_user_who_is_not_impersonating_gains_nothing(): void
    {
        // Why the looser middleware is safe: without the session key the route
        // is inert.
        $user = $this->customerUser();

        $this->actingAs($user)->post(route('admin.impersonation.stop'))->assertRedirect();

        $this->assertSame($user->id, auth()->id());
        $this->assertFalse(auth()->user()->hasRole('admin'));
    }

    public function test_starting_impersonation_still_requires_an_admin(): void
    {
        $user = $this->customerUser();
        $target = $this->customerUser();

        $this->actingAs($user)
            ->post(route('admin.impersonation.start', $target))
            ->assertForbidden();
    }

    public function test_an_admin_can_impersonate_a_customer(): void
    {
        // Impersonating a customer means stepping in as its owner with that
        // customer pinned as the active workspace — the owner may belong to
        // several customers, and landing in the wrong one defeats the point.
        $admin = $this->admin();
        $owner = $this->customerUser();
        $member = $this->customerUser();
        $customer = Customer::factory()->create();
        $customer->users()->attach($member->id, ['role' => 'member']);
        $customer->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($admin)
            ->post(route('admin.impersonation.start-customer', $customer))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($owner->id, session('impersonate_user_id'));
        $this->assertSame($customer->id, session('active_customer_id'));
    }

    public function test_customer_impersonation_skips_admin_users(): void
    {
        // An admin attached to a customer account must never be the body the
        // impersonation lands in — that would be an admin impersonating an
        // admin with extra steps.
        $admin = $this->admin();
        $adminOwner = $this->admin();
        $member = $this->customerUser();
        $customer = Customer::factory()->create();
        $customer->users()->attach($adminOwner->id, ['role' => 'owner']);
        $customer->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin)->post(route('admin.impersonation.start-customer', $customer));

        $this->assertSame($member->id, session('impersonate_user_id'));
    }

    public function test_a_customer_with_no_impersonatable_user_errors_cleanly(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.impersonation.start-customer', $customer))
            ->assertRedirect();

        $this->assertNull(session('impersonate_user_id'));
    }

    public function test_stopping_clears_the_borrowed_customer_context(): void
    {
        // The active customer belonged to the impersonated user, not the
        // admin — leaving it in the session would keep the admin browsing a
        // workspace that isn't theirs.
        $admin = $this->admin();
        $owner = $this->customerUser();
        $customer = Customer::factory()->create();
        $customer->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($admin)->post(route('admin.impersonation.start-customer', $customer));
        $this->assertSame($customer->id, session('active_customer_id'));

        $this->post(route('admin.impersonation.stop'));

        $this->assertNull(session('active_customer_id'));
    }

    public function test_an_admin_cannot_impersonate_another_admin(): void
    {
        // Otherwise impersonation is a privilege-laundering path rather than a
        // support tool.
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->post(route('admin.impersonation.start', $other));

        $this->assertNull(session('impersonate_user_id'));
    }
}
