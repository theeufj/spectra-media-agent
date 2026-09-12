<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * /settings/google-api and /settings/facebook-api are the flows Spectra walks
 * Google and Meta through when submitting the app for API verification. They
 * ask for `adwords` and `ads_management` respectively and store the resulting
 * tokens on a per-user Connection row.
 *
 * That is a per-customer OAuth flow — the pattern CLAUDE.md forbids outright,
 * because every ad platform call goes through Spectra's own management account
 * and customer tokens are a credential we must never be holding. The group sat
 * behind `auth` alone under a /settings/ path, so any signed-in customer could
 * walk it and grant us access to their Google Ads account.
 *
 * Nothing links here. It is reached by typing the URL, which is what an admin
 * doing a verification recording does.
 */
class PlatformApiOAuthAccessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'the settings page' => ['get', '/settings/google-api'],
            'the connect redirect' => ['get', '/settings/google-api/connect'],
            'the callback' => ['get', '/settings/google-api/callback'],
            'the verify page' => ['get', '/settings/google-api/verify'],
            'the disconnect action' => ['post', '/settings/google-api/disconnect'],

            'the facebook settings page' => ['get', '/settings/facebook-api'],
            'the facebook connect redirect' => ['get', '/settings/facebook-api/connect'],
            'the facebook callback' => ['get', '/settings/facebook-api/callback'],
            'the facebook verify page' => ['get', '/settings/facebook-api/verify'],
            'the facebook disconnect action' => ['post', '/settings/facebook-api/disconnect'],
            // Creates a real campaign on Meta.
            'the facebook test-campaign action' => ['post', '/settings/facebook-api/test-campaign'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_an_ordinary_customer_cannot_reach_it(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->{$method}($url)
            ->assertForbidden();
    }

    public function test_an_admin_can_still_open_the_pages(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $this->actingAs($admin)->get('/settings/google-api')->assertOk();
        $this->actingAs($admin)->get('/settings/facebook-api')->assertOk();
    }
}
