<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Onboarding on a tenant skin must stay on that skin. The stock
 * verification email broke it outright: Site to Spend dress regardless of
 * where the user signed up, with a link to APP_URL — where a skin-domain
 * signup has no session, so the first click of their life with us bounced
 * them to a login page for a brand they'd never heard of. And OAuth signups
 * carried the callback host's tenant, so the customer created at QuickStart
 * inherited the wrong skin for every lifecycle email after.
 */
class TenantOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verification_email_wears_the_tenant_skin_and_links_to_its_domain(): void
    {
        $user = User::factory()->create(['tenant_key' => 'realpropertyads', 'email_verified_at' => null]);

        $mail = (new VerifyEmailAddress)->toMail($user);

        $this->assertStringStartsWith('https://realpropertyads.com/email/verify/', $mail->actionUrl);
        $html = $mail->render();
        $this->assertStringContainsString('Real Property Ads', $html);
        $this->assertStringNotContainsString('Site to Spend', $html);
    }

    public function test_default_tenant_verification_email_keeps_the_app_domain(): void
    {
        $user = User::factory()->create(['tenant_key' => null, 'email_verified_at' => null]);

        $mail = (new VerifyEmailAddress)->toMail($user);

        $this->assertStringStartsWith(url('/email/verify/'), $mail->actionUrl);
    }

    public function test_the_tenant_domain_link_actually_verifies(): void
    {
        // The signature is relative, so it must hold after the domain prefix
        // is swapped for the skin's — the whole point of the change.
        $user = User::factory()->create(['tenant_key' => 'realpropertyads', 'email_verified_at' => null]);

        $url = (new VerifyEmailAddress)->toMail($user)->actionUrl;
        $relative = substr($url, strlen('https://realpropertyads.com'));

        $this->actingAs($user)->get($relative)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_welcome_email_follows_verification_not_registration(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->post('https://realpropertyads.com/register', [
            'name' => 'Test Agent',
            'email' => 'agent@example.com',
            'password' => 'long-enough-password-1!',
            'password_confirmation' => 'long-enough-password-1!',
        ]);

        // Registration's one email is the verification link — welcoming an
        // address nobody has proven they own read backwards.
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\WelcomeEmail::class);

        $user = User::where('email', 'agent@example.com')->firstOrFail();
        $url = (new VerifyEmailAddress)->toMail($user)->actionUrl;
        $this->actingAs($user)->get(substr($url, strlen('https://realpropertyads.com')));

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\WelcomeEmail::class,
            fn ($mail) => $mail->hasTo('agent@example.com') && $mail->tenantKey === 'realpropertyads'
        );
    }

    public function test_the_funnel_is_closed_to_unverified_emails(): void
    {
        // An unproven address must not be able to start crawls, build
        // campaigns, or deploy — registration's whole first step is the
        // verification link.
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/quick-start')
            ->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($user)->post('/quick-start', ['website_url' => 'https://example.com'])
            ->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($user)->post('/deployment/deploy', ['campaign_id' => 1])
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_quick_start_on_a_skin_host_stamps_the_skin_even_for_a_tenantless_user(): void
    {
        // OAuth signups can arrive with a null or wrong tenant_key (the
        // provider redirects to one registered callback URI). The host the
        // customer is created on is the truth.
        $user = User::factory()->create(['tenant_key' => null]);

        $this->actingAs($user)
            ->post('https://realpropertyads.com/quick-start', ['website_url' => 'https://example.net']);

        $customer = $user->customers()->first();
        $this->assertNotNull($customer);
        $this->assertSame('realpropertyads', $customer->tenant_key);
        $this->assertSame('realpropertyads', $user->fresh()->tenant_key);
    }
}
