<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The YouTube OAuth callback writes GOOGLE_YOUTUBE_REFRESH_TOKEN — the one
 * credential every customer's ad video is uploaded with.
 *
 * It used to be registered outside the auth group with no state check, so
 * anyone could drive it with a code minted for their own Google account and
 * every video ad the platform uploads would land on their channel, where it
 * could be swapped or deleted while still referenced by live ads.
 */
class YouTubeAuthAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_guest_cannot_reach_the_callback(): void
    {
        $this->get('/youtube/auth/callback?code=minted-elsewhere')
            ->assertRedirect('/login');
    }

    public function test_support_staff_cannot_complete_the_flow(): void
    {
        // AdminMiddleware waves GETs through for support, on the rule that a
        // read is safe. This one replaces a platform credential.
        $this->actingAs($this->userWithRole('support'))
            ->get('/youtube/auth/callback?code=minted-elsewhere')
            ->assertForbidden();
    }

    public function test_a_code_arriving_without_the_state_this_session_issued_is_refused(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/youtube/auth/callback?code=minted-elsewhere&state=guessed')
            ->assertForbidden();

        // Http::preventStrayRequests would have failed the test had the token
        // exchange run; asserting it explicitly says why that matters.
        Http::assertNothingSent();
    }

    public function test_the_redirect_issues_a_state_that_the_callback_then_accepts(): void
    {
        config([
            'services.youtube.client_id' => 'test-client-id',
            'services.youtube.client_secret' => 'test-client-secret',
        ]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test'], 200)]);

        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->get(route('youtube.auth'));

        $state = session('youtube_oauth_state');
        $this->assertNotEmpty($state, 'the redirect must issue a state to check the callback against');
        $response->assertRedirectContains('state='.$state);

        // Google's stubbed answer carries no refresh_token, so the flow stops
        // one step short of the .env write — far enough to prove the matching
        // state was accepted and the code exchanged.
        $this->actingAs($admin)->get('/youtube/auth/callback?code=real&state='.$state);

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => $role])));

        return $user;
    }
}
