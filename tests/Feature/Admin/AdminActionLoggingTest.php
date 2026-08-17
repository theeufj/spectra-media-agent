<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every admin action that changes something leaves a record.
 *
 * Three of nineteen admin controllers logged anything. Feature flags, platform
 * settings, MCC credentials and plan pricing could all be changed with no record
 * of who or when — and MccAccountController rotates the credentials the whole
 * platform authenticates with.
 *
 * Doing this per controller means remembering nineteen times, and three of
 * nineteen is the evidence for how that goes. It lives in the admin middleware
 * group instead, so a route is audited by virtue of being an admin route.
 */
class AdminActionLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['name' => 'Ops Admin', 'email' => 'ops@example.com']);
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_a_mutating_admin_request_is_recorded(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['some_setting' => 'value']);

        $this->assertDatabaseHas('activity_logs', [
            'user_email' => 'ops@example.com',
            'action' => 'admin.admin.settings.update',
        ]);
    }

    public function test_the_record_names_the_admin_who_acted(): void
    {
        // "Someone changed the pricing" is not an answer.
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.update'), ['x' => 'y']);

        $log = ActivityLog::latest('id')->first();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Ops Admin', $log->user_name);
        $this->assertNotNull($log->ip_address);
    }

    public function test_the_payload_is_recorded_so_the_change_is_answerable(): void
    {
        // The difference between "changed the plan" and "changed the price from
        // 250 to 25".
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['ad_spend_markup' => '0.01']);

        $log = ActivityLog::latest('id')->first();

        $this->assertSame('0.01', $log->properties['input']['ad_spend_markup'] ?? null);
    }

    public function test_secrets_are_never_written_to_the_log(): void
    {
        // MCC credentials and API keys pass through these endpoints. An audit
        // log that copies them turns one compromise into two.
        $this->actingAs($this->admin())->post(route('admin.settings.update'), [
            'name' => 'visible',
            'refresh_token' => 'ya29.super-secret',
            'client_secret' => 'GOCSPX-secret',
            'password' => 'hunter2',
        ]);

        $input = ActivityLog::latest('id')->first()->properties['input'];

        $this->assertSame('visible', $input['name'] ?? null);
        $this->assertArrayNotHasKey('refresh_token', $input);
        $this->assertArrayNotHasKey('client_secret', $input);
        $this->assertArrayNotHasKey('password', $input);
    }

    public function test_reads_are_not_recorded(): void
    {
        // The log answers "who changed this". A page view changes nothing, and
        // recording every one would bury the entries that matter.
        $this->actingAs($this->admin())->get(route('admin.users.index'));

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_a_failed_action_is_still_recorded(): void
    {
        // An attempt that was refused is exactly what an audit log is for.
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->admin())
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'wrong']);

        $log = ActivityLog::latest('id')->first();

        $this->assertSame('admin.admin.customers.delete', $log->action);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_the_subject_of_the_action_is_captured(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->admin())
            ->delete(route('admin.customers.delete', $customer), ['confirm_name' => 'wrong']);

        $log = ActivityLog::latest('id')->first();

        $this->assertSame(Customer::class, $log->subject_type);
        $this->assertSame($customer->id, $log->subject_id);
    }
}
