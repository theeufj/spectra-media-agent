<?php

namespace Tests\Feature;

use App\Models\Connection;
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

    public function test_the_google_connect_redirect_issues_a_state_parameter(): void
    {
        $response = $this->actingAs(User::factory()->create())
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
        $response = $this->actingAs(User::factory()->create())
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
        $user = User::factory()->create();

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
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('facebook-api.callback', [
            'code' => 'attacker-minted-code',
            'state' => 'attacker-state',
        ]));

        $this->assertSame(0, Connection::where('user_id', $user->id)->count());
    }
}
