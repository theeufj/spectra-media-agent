<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The admin console had no rate limit and no server-side confirmation.
 *
 * Public endpoints were held to 5 and 3 requests a minute; everything under
 * /admin was unlimited. And every destructive action relied on a dialog in the
 * UI — which is advice to the person clicking, not a control. A dialog does not
 * survive a mis-wired button, a replayed request, or a script written against
 * the endpoint.
 */
class AdminHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_a_destructive_action_without_confirmation_is_refused(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $target))
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_the_same_action_succeeds_when_confirmed(): void
    {
        $target = User::factory()->create();

        $response = $this->actingAs($this->admin())
            ->delete(route('admin.users.delete', $target), ['confirmed' => true]);

        $this->assertNotSame(422, $response->getStatusCode());
    }

    public function test_flushing_the_failed_job_queue_needs_confirmation(): void
    {
        // Destroys the record of everything that has broken, which is the last
        // thing you want done by accident.
        $this->actingAs($this->admin())
            ->post(route('admin.health.flush-jobs'))
            ->assertStatus(422);
    }

    public function test_activating_a_different_mcc_needs_confirmation(): void
    {
        // Repoints every Google Ads call the platform makes.
        $mcc = \App\Models\MccAccount::create([
            'name' => 'Standby MCC',
            'google_customer_id' => '1234567890',
            'refresh_token' => 'test-token',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.mcc-accounts.activate', $mcc))
            ->assertStatus(422);
    }

    public function test_ordinary_admin_pages_are_not_gated_by_confirmation(): void
    {
        // Guarding everything would train people to send the flag reflexively,
        // which is the same as not having it.
        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertSuccessful();
    }

    public function test_admin_requests_are_rate_limited(): void
    {
        $admin = $this->admin();

        // The limit is 120/minute: generous for a person, useless for a script
        // enumerating or mass-deleting through a stolen session.
        for ($i = 0; $i < 121; $i++) {
            $response = $this->actingAs($admin)->get(route('admin.users.index'));

            if ($response->getStatusCode() === 429) {
                $this->assertGreaterThan(100, $i, 'the limit should not bite during normal use');

                return;
            }
        }

        $this->fail('admin routes were not rate limited');
    }
}
