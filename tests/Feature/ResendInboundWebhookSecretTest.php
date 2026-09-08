<?php

namespace Tests\Feature;

use App\Models\EmailSequenceReply;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The inbound webhook is unauthenticated by necessity, so the Resend signature
 * is the only thing in front of it. It used to verify only *if* a secret
 * happened to be configured — and RESEND_WEBHOOK_SECRET is commented out in
 * .env.example, so "not configured" is the default state of any new box. On
 * one of those, anybody could POST a sequence reply attributed to a real lead
 * and have their text mailed to every admin. It now fails closed.
 */
class ResendInboundWebhookSecretTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_inbound_webhook_is_refused_when_no_secret_is_configured(): void
    {
        Mail::fake();

        config(['resend.webhook.secret' => null]);

        $this->postJson(route('resend.inbound'), [
            'type' => 'email.received',
            'data' => ['from' => 'someone@example.com', 'subject' => 'Re: hello', 'text' => 'Interested'],
        ])->assertStatus(401);

        $this->assertSame(0, EmailSequenceReply::count());
        Mail::assertNothingQueued();
    }

    public function test_an_inbound_webhook_is_refused_when_the_secret_is_an_empty_string(): void
    {
        // env() gives back '' for a key that is present but blank, which is
        // not the same value as null and would have passed the old `if`.
        config(['resend.webhook.secret' => '']);

        $this->postJson(route('resend.inbound'), [
            'type' => 'email.received',
            'data' => ['from' => 'someone@example.com', 'subject' => 'Re: hello', 'text' => 'Interested'],
        ])->assertStatus(401);

        $this->assertSame(0, EmailSequenceReply::count());
    }
}
