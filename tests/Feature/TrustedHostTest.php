<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The host a caller claims must never reach URL generation.
 *
 * ResetPassword is not queued, so its link is rendered in-request against
 * $request->root(). While X-Forwarded-Host was in the trusted-proxy bitmask —
 * and at: '*' on a single-box fastcgi site trusts the caller as its own proxy —
 * POST /forgot-password with a victim's email and `X-Forwarded-Host: evil.com`
 * sent that victim a genuine, correctly signed reset link pointing at the
 * attacker's host. One click handed over the account.
 */
class TrustedHostTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_forwarded_host_header_does_not_move_the_root_of_a_generated_url(): void
    {
        Route::middleware('web')->get('/_test/request-root', fn () => request()->root());

        $this->get('/_test/request-root', ['X-Forwarded-Host' => 'evil.example.com'])
            ->assertOk()
            ->assertDontSee('evil.example.com');
    }

    public function test_a_password_reset_link_ignores_a_forwarded_host(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email], ['X-Forwarded-Host' => 'evil.example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification) => ! str_contains($notification->toMail($user)->actionUrl, 'evil.example.com')
        );
    }

    public function test_only_tenant_domains_and_the_forge_host_are_trusted(): void
    {
        $answered = [
            'sitetospend.com',
            'www.sitetospend.com',
            'realpropertyads.com',
            'www.realpropertyads.com',
            config('tenants.forge_domain'),
        ];

        foreach ($answered as $host) {
            $this->assertTrue($this->isTrusted($host), "{$host} serves this app and must stay trusted");
        }

        // Anchored, so neither a prefix nor a suffix of a real domain passes.
        foreach (['evil.example.com', 'sitetospend.com.evil.example.com', 'notsitetospend.com'] as $host) {
            $this->assertFalse($this->isTrusted($host), "{$host} must not be trusted");
        }
    }

    /**
     * The same match Symfony performs in Request::getHost(). TrustHosts itself
     * no-ops under runningUnitTests(), so the patterns are checked directly.
     */
    private function isTrusted(string $host): bool
    {
        foreach ((new TrustHosts($this->app))->hosts() as $pattern) {
            if (preg_match('{'.$pattern.'}i', $host)) {
                return true;
            }
        }

        return false;
    }
}
