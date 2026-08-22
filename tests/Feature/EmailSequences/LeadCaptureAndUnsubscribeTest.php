<?php

namespace Tests\Feature\EmailSequences;

use App\Models\EmailSequenceReply;
use App\Models\LandingLead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LeadCaptureAndUnsubscribeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        DB::table('landing_leads')->delete();
    }

    // ── Unsubscribe ──────────────────────────────────────────────────────────

    public function test_a_lead_can_unsubscribe_without_logging_in(): void
    {
        $lead = LandingLead::create(['email' => 'lead@example.com']);

        // Someone stopping email they did not want must never be asked to log
        // in first — and a lead has no account to log into anyway.
        $this->get(URL::signedRoute('email.sequence.unsubscribe', ['type' => 'lead', 'id' => $lead->id]))
            ->assertStatus(200);

        $this->assertNotNull($lead->fresh()->unsubscribed_at);
        $this->assertFalse($lead->fresh()->isContactable());
    }

    public function test_a_user_can_unsubscribe_from_sequences(): void
    {
        $user = User::factory()->create();

        $this->get(URL::signedRoute('email.sequence.unsubscribe', ['type' => 'user', 'id' => $user->id]))
            ->assertStatus(200);

        $preferences = $user->fresh()->notification_preferences;
        $this->assertIsArray($preferences);
        $this->assertFalse($preferences['sequences']);
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        $lead = LandingLead::create(['email' => 'lead@example.com']);

        // Otherwise anyone could unsubscribe anyone by walking ids.
        $this->get("/email/sequences/unsubscribe/lead/{$lead->id}")->assertStatus(403);

        $this->assertNull($lead->fresh()->unsubscribed_at);
    }

    public function test_unsubscribing_an_unknown_lead_reveals_nothing(): void
    {
        // A signed link that says "no such lead" is an address-enumeration
        // oracle. It answers the same either way.
        $this->get(URL::signedRoute('email.sequence.unsubscribe', ['type' => 'lead', 'id' => 999999]))
            ->assertStatus(200);
    }

    // ── Lead capture ─────────────────────────────────────────────────────────

    public function test_registering_hands_a_lead_over_to_the_signup_chain(): void
    {
        $lead = LandingLead::create(['email' => 'new@example.com', 'first_name' => 'Sam']);

        $this->post(route('register'), [
            'name' => 'Sam Smith',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $lead->refresh();

        // Both chains running at once would tell someone what they are missing
        // after they have joined.
        $this->assertNotNull($lead->converted_at);
        $this->assertNotNull($lead->converted_user_id);
        $this->assertFalse($lead->isContactable());
    }

    // ── Replies ──────────────────────────────────────────────────────────────

    public function test_an_unsigned_inbound_webhook_is_refused(): void
    {
        // Public endpoint that writes records and sends email; an unsigned
        // request must never reach the handler.
        $this->postJson(route('webhooks.resend.inbound'), [
            'data' => ['from' => 'someone@example.com', 'subject' => 'Re: hello', 'text' => 'Interested'],
        ])->assertStatus(401);

        $this->assertSame(0, EmailSequenceReply::count());
    }

    public function test_a_signed_reply_is_stored_and_the_team_notified(): void
    {
        config(['services.resend.webhook_secret' => 'whsec_'.base64_encode('test-secret')]);

        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        User::factory()->count(3)->create()->each(fn ($u) => $u->roles()->attach($role));

        $payload = json_encode([
            'data' => ['from' => 'Sam <sam@example.com>', 'subject' => 'Re: I had a look', 'text' => 'Yes please'],
        ]);

        $id = 'msg_1';
        $timestamp = (string) now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", 'test-secret', true));

        $this->call('POST', route('webhooks.resend.inbound'), [], [], [], [
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $timestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(200);

        $reply = EmailSequenceReply::firstOrFail();

        // The display name is stripped so the address can be matched to a send.
        $this->assertSame('sam@example.com', $reply->from_email);
        $this->assertNotNull($reply->notified_at);

        // Every admin hears about it, not one configured address.
        Mail::assertQueued(\App\Mail\AdminNotification::class, 3);
    }
}
