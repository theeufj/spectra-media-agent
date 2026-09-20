<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerificationContinuationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verified_accounts_cannot_get_stuck_on_the_waiting_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['url.intended' => route('verification.notice')])
            ->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    }

    public function test_status_poll_tracks_verification_without_verifying_the_account_itself(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson(route('verification.notice'))
            ->assertOk()->assertExactJson(['verified' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull($user->fresh()->email_verified_at);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ], absolute: false);
        $this->get($url)->assertRedirect(route('quick-start'));

        $this->getJson(route('verification.notice'))->assertExactJson(['verified' => true]);
    }

    public function test_polling_requires_authentication(): void
    {
        $this->getJson(route('verification.notice'))->assertUnauthorized();
    }

    public function test_resending_after_verification_does_not_send_another_email_or_loop(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['url.intended' => route('verification.notice')])
            ->post(route('verification.send'))->assertRedirect(route('dashboard'));
        Notification::assertNothingSent();
    }

    public function test_unverified_accounts_can_still_resend_with_visible_confirmation(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }
}
