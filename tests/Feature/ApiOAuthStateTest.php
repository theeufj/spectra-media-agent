<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Google/Facebook API connect flows are session-backed GET callbacks with
 * no CSRF token of their own, so the OAuth `state` round-trip is the only
 * thing tying a callback to the redirect that started it. Both controllers
 * called ->stateless(), which removes that check entirely: a victim lured to
 * the callback with an attacker-minted code had their Connection row silently
 * replaced by updateOrCreate().
 */
class ApiOAuthStateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Socialite builds the authorize URL from these; no network involved.
        config([
            'services.google.client_id' => 'test-google-client',
            'services.google.client_secret' => 'test-google-secret',
            'services.facebook.client_id' => 'test-facebook-client',
            'services.facebook.client_secret' => 'test-facebook-secret',
        ]);
    }

    /**
     * Both connect flows are admin-only — they request `adwords` /
     * `ads_management` and write a per-user token, which no customer may do.
     * What these tests are about is the state round-trip, not who may start it,
     * so they simply act as someone allowed through the door.
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_the_google_connect_redirect_issues_a_state_parameter(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('google-api.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('state');

        $this->assertStringContainsString(
            'state='.session('state'),
            $response->headers->get('Location'),
        );
    }

    public function test_the_facebook_connect_redirect_issues_a_state_parameter(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('facebook-api.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('state');

        $this->assertStringContainsString(
            'state='.session('state'),
            $response->headers->get('Location'),
        );
    }

    public function test_a_google_callback_the_user_never_started_binds_nothing(): void
    {
        // An admin, deliberately. A plain user is now refused by the route's
        // own middleware, and a test that passes because of that would keep
        // passing with the state check deleted — which is the thing it exists
        // to hold.
        $user = $this->admin();

        // No `state` in the session: this request did not come from our own
        // redirect. Socialite refuses before it will exchange the code, so
        // nothing is written.
        $this->actingAs($user)->get(route('google-api.callback', [
            'code' => 'attacker-minted-code',
            'state' => 'attacker-state',
        ]));

        $this->assertSame(0, Connection::where('user_id', $user->id)->count());
    }

    public function test_a_facebook_callback_the_user_never_started_binds_nothing(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get(route('facebook-api.callback', [
            'code' => 'attacker-minted-code',
            'state' => 'attacker-state',
        ]));

        $this->assertSame(0, Connection::where('user_id', $user->id)->count());
    }
}
