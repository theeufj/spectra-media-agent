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
        // The controller verifies only when a secret is configured, so the
        // test has to configure one — otherwise this passes for the wrong
        // reason. Production has it set; verified against the live config.
        config(['resend.webhook.secret' => 'whsec_'.base64_encode('test-secret')]);

        $this->postJson(route('resend.inbound'), [
            'type' => 'email.received',
            'data' => ['from' => 'someone@example.com', 'subject' => 'Re: hello', 'text' => 'Interested'],
        ])->assertStatus(401);

        $this->assertSame(0, EmailSequenceReply::count());
    }

    public function test_a_reply_is_stored_and_every_admin_is_notified(): void
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        User::factory()->count(3)->create()->each(fn ($u) => $u->roles()->attach($role));

        $step = \App\Models\EmailSequence::create([
            'key' => 'reply-test', 'label' => 'Test', 'audience' => 'landing_lead',
            'from_email' => 'james@sitetospend.com', 'from_name' => 'James',
            'signature' => 'James', 'enabled' => false,
        ])->steps()->create([
            'position' => 1, 'delay_hours' => 1, 'subject' => 'Hi', 'body' => 'Hi', 'enabled' => true,
        ]);

        \App\Models\EmailSequenceSend::create([
            'email_sequence_step_id' => $step->id,
            'recipient_type' => 'lead', 'recipient_id' => 1,
            'email' => 'sam@example.com', 'sent_at' => now(),
        ]);

        $reply = app(\App\Services\EmailSequences\SequenceReplyRecorder::class)->record([
            'from' => 'Sam <sam@example.com>',
            'subject' => 'Re: I had a look',
            'text' => 'Yes please',
        ]);

        // The display name is stripped so the address can be matched to a send.
        $this->assertSame('sam@example.com', $reply->from_email);
        $this->assertNotNull($reply->notified_at);

        // Every admin hears about it, not one configured address.
        Mail::assertQueued(\App\Mail\AdminNotification::class, 3);
    }

    public function test_an_inbound_email_that_is_not_a_reply_is_left_alone(): void
    {
        // Somebody writing to a customer inbox is not replying to a chain.
        // Recording it would put unrelated mail in front of the founders.
        $reply = app(\App\Services\EmailSequences\SequenceReplyRecorder::class)->record([
            'from' => 'stranger@example.com',
            'subject' => 'Hello',
            'text' => 'Unrelated',
        ]);

        $this->assertNull($reply);
        $this->assertSame(0, EmailSequenceReply::count());
    }
}
