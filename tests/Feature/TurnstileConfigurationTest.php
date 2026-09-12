<?php

namespace Tests\Feature;

use App\Rules\CloudflareTurnstile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bot protection is one switch, or it is a lockout.
 *
 * The widget used to render whenever the SITE key was set, while the validation
 * rules in RegisteredUserController and LoginRequest engaged whenever the
 * SECRET key was set. Two conditions for one feature, on the unauthenticated
 * front door:
 *
 *   secret only  → the server demands a `cf_turnstile_response` that no widget
 *                  is rendered to produce. Registration and login are closed to
 *                  everyone, and the visitor gets a validation error naming a
 *                  field that is not on the page.
 *   site only    → a challenge is shown and nothing enforces it.
 *
 * Both halves now read CloudflareTurnstile::enabled(), and this fails if they
 * ever drift apart again.
 */
class TurnstileConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    private function setKeys(?string $site, ?string $secret): void
    {
        config([
            'services.cloudflare.turnstile_site_key' => $site,
            'services.cloudflare.turnstile_secret_key' => $secret,
        ]);
    }

    public static function halfConfigured(): array
    {
        return [
            'secret without site key' => [null, 'secret-only'],
            'site key without secret' => ['site-only', null],
            'neither' => [null, null],
        ];
    }

    #[DataProvider('halfConfigured')]
    public function test_a_half_configured_install_never_blocks_the_front_door(?string $site, ?string $secret): void
    {
        $this->setKeys($site, $secret);

        $this->assertFalse(CloudflareTurnstile::enabled());

        // No widget offered...
        $props = $this->get('/register')->assertOk()->viewData('page')['props'];
        $this->assertNull($props['turnstileSiteKey']);

        // ...and nothing demanding a token from one.
        $this->post('/register', [
            'name' => 'Jamie Client',
            'email' => 'jamie'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertSessionHasNoErrors();
    }

    public function test_both_keys_turn_it_on_at_both_ends(): void
    {
        $this->setKeys('site-key', 'secret-key');

        $this->assertTrue(CloudflareTurnstile::enabled());

        // The widget is offered to the page...
        $props = $this->get('/register')->assertOk()->viewData('page')['props'];
        $this->assertSame('site-key', $props['turnstileSiteKey']);

        // ...and the server will not take a registration without its token.
        $this->post('/register', [
            'name' => 'Jamie Client',
            'email' => 'jamie'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertSessionHasErrors('cf_turnstile_response');
    }

    public function test_the_widget_is_never_asked_for_without_a_key_to_render_it(): void
    {
        // Whatever the configuration, the page must not be able to mount a
        // challenge it has no site key for — TurnstileField returns null on a
        // falsy key, and this is the prop that decides it.
        foreach ([[null, 'secret'], ['site', null], [null, null], ['site', 'secret']] as [$site, $secret]) {
            $this->setKeys($site, $secret);

            $props = $this->get('/login')->assertOk()->viewData('page')['props'];

            $this->assertSame(
                CloudflareTurnstile::enabled() ? $site : null,
                $props['turnstileSiteKey'],
            );
        }
    }
}
