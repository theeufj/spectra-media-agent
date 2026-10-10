<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureClickIds;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClickIdentifierRetentionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_first_touch_is_encrypted_host_only_and_expires_without_rolling_retention(): void
    {
        $this->freezeTime();
        config(['session.domain' => '.sitetospend.com']);
        $response = $this->get('https://sitetospend.com/?gclid=first-click');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === CaptureClickIds::COOKIE_NAME);
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString('first-click', $cookie->getValue());
        $expires = $cookie->getExpiresTime();
        $this->assertSame(now()->addDays(30)->timestamp, $expires);

        $this->travel(2)->days();
        $response = $this->get('https://sitetospend.com/?gclid=second-click');
        $this->assertSame('first-click', CaptureClickIds::get('gclid'));
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === CaptureClickIds::COOKIE_NAME);
        $this->assertSame($expires, $cookie->getExpiresTime());
    }

    public function test_returning_visitor_recovers_identifier_from_valid_cookie_after_session_loss(): void
    {
        $this->freezeTime();
        $cookie = json_encode(['host' => 'sitetospend.com', 'tenant' => 'sitetospend',
            'captured_at' => now()->subDays(2)->timestamp, 'ids' => ['wbraid' => 'return-braid']]);
        $this->withCookie(CaptureClickIds::COOKIE_NAME, $cookie)->get('https://sitetospend.com/pricing')->assertSuccessful();
        $this->assertSame('return-braid', CaptureClickIds::get('wbraid'));
        $this->assertSame(now()->subDays(2)->timestamp, session('click_ids_context.captured_at'));
    }

    public function test_expired_foreign_host_or_foreign_tenant_cookie_never_restores_ids(): void
    {
        foreach ([
            ['host' => 'other.example', 'tenant' => 'sitetospend', 'captured_at' => now()->timestamp],
            ['host' => 'sitetospend.com', 'tenant' => 'other-tenant', 'captured_at' => now()->timestamp],
            ['host' => 'sitetospend.com', 'tenant' => 'sitetospend', 'captured_at' => now()->subDays(31)->timestamp],
        ] as $context) {
            $this->withCookie(CaptureClickIds::COOKIE_NAME, json_encode($context + ['ids' => ['gclid' => 'foreign-click']]))
                ->get('https://sitetospend.com/')->assertSuccessful();
            $this->assertSame([], CaptureClickIds::all());
        }
    }

    public function test_shared_session_cannot_carry_identifiers_to_another_host(): void
    {
        $this->withSession(['click_ids' => ['gclid' => 'foreign-click'], 'click_ids_context' => [
            'host' => 'other.example', 'tenant' => 'sitetospend', 'captured_at' => now()->timestamp,
        ]])->get('https://sitetospend.com/')->assertSuccessful();
        $this->assertSame([], CaptureClickIds::all());
    }

    public function test_untrusted_cookie_arrays_and_non_url_parameters_do_not_become_click_ids(): void
    {
        $this->get('/?gclid%5B%5D=bad&gbraid='.str_repeat('a', 256).'&wbraid=white%20space')->assertSuccessful();
        $this->assertSame([], CaptureClickIds::all());
        $this->post('/login', ['gclid' => 'body-only', 'email' => 'invalid@example.com', 'password' => 'invalid']);
        $this->assertSame([], CaptureClickIds::all());
        $this->withUnencryptedCookie(CaptureClickIds::COOKIE_NAME, 'tampered-cleartext')
            ->get('/')->assertSuccessful();
        $this->assertSame([], CaptureClickIds::all());
    }
}
