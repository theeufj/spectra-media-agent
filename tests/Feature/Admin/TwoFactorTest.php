<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A password was the only thing between a stolen session and everything.
 *
 * The admin console can change billing, delete customers and rotate the MCC
 * credentials the entire platform authenticates with. There was no second factor
 * and, until this session, no rate limit either.
 *
 * Enforcement is staged rather than absolute: a hard requirement applied at
 * deploy time locks out every existing admin, including whoever would need to be
 * logged in to fix it.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    private function enrolled(): User
    {
        $user = $this->admin();
        $user->forceFill([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['AAAAA-BBBBB'],
        ])->save();

        return $user->fresh();
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    }

    public function test_an_admin_without_two_factor_is_sent_to_enrol(): void
    {
        config(['auth.admin_require_2fa' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertRedirect(route('admin.two-factor.show'));
    }

    public function test_an_unconfirmed_secret_does_not_count_as_enrolled(): void
    {
        // Generating a secret proves nothing — the authenticator may never have
        // been set up, and treating it as enrolment would lock someone out over
        // a QR code they never scanned.
        $user = $this->admin();
        $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey()])->save();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_an_enrolled_admin_must_pass_the_challenge(): void
    {
        $this->actingAs($this->enrolled())
            ->get(route('admin.users.index'))
            ->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_a_valid_code_grants_access_for_the_session(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)->post(route('admin.two-factor.verify'), [
            'code' => $this->currentCode($user),
        ])->assertRedirect();

        $this->actingAs($user)->get(route('admin.users.index'))->assertSuccessful();
    }

    public function test_a_wrong_code_does_not(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->post(route('admin.two-factor.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_a_recovery_code_works_once_and_is_consumed(): void
    {
        // Otherwise a leaked list stays valid forever.
        $user = $this->enrolled();

        $this->actingAs($user)->post(route('admin.two-factor.verify'), ['code' => 'AAAAA-BBBBB']);

        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_the_enrolment_screen_stays_reachable(): void
    {
        // Or the requirement is a locked door with the key behind it.
        config(['auth.admin_require_2fa' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.two-factor.show'))
            ->assertSuccessful();
    }

    public function test_confirming_requires_a_real_code(): void
    {
        $user = $this->admin();
        $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey()])->save();

        $this->actingAs($user)
            ->post(route('admin.two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_requires_a_current_code(): void
    {
        // A hijacked session must not be able to simply switch off the thing
        // standing in its way.
        $user = $this->enrolled();

        $this->actingAs($user)
            ->post(route('admin.two-factor.disable'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enforcement_can_be_staged_off_for_rollout(): void
    {
        // The window between deploying this and everyone having set it up.
        config(['auth.admin_require_2fa' => false]);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertSuccessful();
    }

    public function test_those_already_enrolled_are_challenged_even_during_rollout(): void
    {
        config(['auth.admin_require_2fa' => false]);

        $this->actingAs($this->enrolled())
            ->get(route('admin.users.index'))
            ->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        // A database dump should not hand over the second factor along with the
        // first.
        $user = $this->enrolled();

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $raw);
    }
}
