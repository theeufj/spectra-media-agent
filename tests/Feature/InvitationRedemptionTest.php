<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An invitation token is a grant of access to somebody else's tenant.
 *
 * InvitationController::accept() has always applied two rules — the 7-day
 * expiry and the address the invite was sent to — but registration applied
 * neither: it looked the token up and attached the pivot. A leaked or stale
 * link therefore joined whoever held it to the tenant, under any email,
 * indefinitely. Both paths now go through InvitationController::redeem().
 */
class InvitationRedemptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration adds a Turnstile rule when a secret is configured, and
        // the rule calls Cloudflare. Nothing here is testing the captcha.
        config(['services.cloudflare.turnstile_secret_key' => null]);
    }

    private function invitation(string $email, ?Customer $customer = null): Invitation
    {
        return Invitation::create([
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'email' => $email,
            'role' => 'marketing',
            'token' => Str::random(32),
        ]);
    }

    private function register(string $email, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/register', array_merge([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password-that-is-long',
            'password_confirmation' => 'password-that-is-long',
        ], $extra));
    }

    public function test_registering_with_a_matching_invitation_joins_the_tenant(): void
    {
        $invitation = $this->invitation('invitee@example.com');

        $this->register('invitee@example.com', ['invitation_token' => $invitation->token])
            ->assertRedirect(route('verification.notice', absolute: false));

        $user = User::where('email', 'invitee@example.com')->firstOrFail();

        $this->assertTrue($user->customers()->where('customers.id', $invitation->customer_id)->exists());
        $this->assertSame('marketing', $user->customers()->first()->pivot->role);
        $this->assertNull(Invitation::find($invitation->id), 'a redeemed invitation is consumed');
    }

    public function test_a_token_addressed_to_someone_else_grants_nothing(): void
    {
        // The whole attack: hold a link that was mailed to someone at the
        // target company, sign up under your own address, land inside their
        // tenant. Registration succeeds; the tenant grant does not.
        $invitation = $this->invitation('finance@target-company.test');

        $this->register('attacker@example.com', ['invitation_token' => $invitation->token])
            ->assertRedirect(route('verification.notice', absolute: false));

        $attacker = User::where('email', 'attacker@example.com')->firstOrFail();

        $this->assertSame(0, $attacker->customers()->count());
        $this->assertNotNull(Invitation::find($invitation->id), 'the real invitee can still use their link');
    }

    public function test_an_expired_token_grants_nothing_and_goes_dead(): void
    {
        $invitation = $this->invitation('invitee@example.com');
        $invitation->created_at = now()->subDays(8);
        $invitation->save();

        $this->register('invitee@example.com', ['invitation_token' => $invitation->token])
            ->assertRedirect(route('verification.notice', absolute: false));

        $user = User::where('email', 'invitee@example.com')->firstOrFail();

        $this->assertSame(0, $user->customers()->count());
        $this->assertNull(Invitation::find($invitation->id), 'an expired invitation is deleted on sight');
    }

    public function test_an_invitation_token_that_is_not_a_string_is_rejected(): void
    {
        // It was read straight off the request and handed to a where() clause.
        $this->register('someone@example.com', ['invitation_token' => ['a', 'b']])
            ->assertSessionHasErrors('invitation_token');

        $this->assertNull(User::where('email', 'someone@example.com')->first());
    }

    public function test_the_accept_link_still_binds_an_existing_invited_account(): void
    {
        // The extraction must not have changed what accept() does for the
        // person it was addressed to.
        $user = User::factory()->create(['email' => 'teammate@example.com']);
        $invitation = $this->invitation('teammate@example.com');

        $this->actingAs($user)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->customers()->where('customers.id', $invitation->customer_id)->exists());
        $this->assertNull(Invitation::find($invitation->id));
    }

    public function test_the_accept_link_refuses_an_expired_invitation(): void
    {
        $user = User::factory()->create(['email' => 'teammate@example.com']);
        $invitation = $this->invitation('teammate@example.com');
        $invitation->created_at = now()->subDays(8);
        $invitation->save();

        $this->actingAs($user)
            ->get(route('invitations.accept', $invitation->token))
            ->assertStatus(410);

        $this->assertSame(0, $user->customers()->count());
    }
}
