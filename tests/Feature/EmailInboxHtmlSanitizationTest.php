<?php

namespace Tests\Feature;

use App\Models\EmailInbox;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\EmailInboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Resend\Laravel\Facades\Resend;
use Tests\TestCase;

/**
 * Inbound mail is the least trusted HTML in the application: anyone who knows
 * an inbox address can send whatever markup they like, and the thread pane
 * renders it with dangerouslySetInnerHTML — on the app origin, in a signed-in
 * staff session.
 *
 * Two layers, and both are pinned here because they cover different rows.
 * processInboundWebhook() sanitises on the way in, which is also what makes the
 * forward safe; formatMessage() sanitises again on the way out, which is what
 * covers rows stored before the first layer existed.
 */
class EmailInboxHtmlSanitizationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Four separate vectors, because a sanitiser that catches `<script>` and
     * nothing else looks like it passes.
     */
    private const PAYLOAD = '<p onclick="alert(1)">Invoice attached</p>'
        .'<script>fetch("/admin/users")</script>'
        .'<a href="javascript:alert(1)">Pay now</a>'
        .'<img src="https://a.test/p.png" onerror="alert(1)">';

    public function test_inbound_html_is_sanitised_before_it_is_stored(): void
    {
        $inbox = $this->inbox();
        $this->fakeResend($inbox);

        app(EmailInboxService::class)->processInboundWebhook($this->webhookPayload($inbox));

        $stored = EmailMessage::where('inbox_id', $inbox->id)->sole()->html_body;

        $this->assertNoExecutableMarkup($stored);

        // The mail still has to arrive. A sanitiser that empties every inbound
        // message is a different outage, not a fix.
        $this->assertStringContainsString('Invoice attached', $stored);
        $this->assertStringContainsString('Pay now', $stored);
    }

    public function test_a_whole_document_email_keeps_the_markup_mail_actually_uses(): void
    {
        // Received mail is almost always a full document, so the sanitiser has
        // to survive one: the layout table and its text come through, while the
        // head — whose contents are the <title> line and a stylesheet no inbox
        // should apply — goes with it rather than being unwrapped into the body.
        $inbox = $this->inbox();
        $this->fakeResend($inbox, '<!DOCTYPE html><html lang="en"><head><title>Receipt</title>'
            .'<style>.x{color:red}</style></head><body style="margin:0">'
            .'<table width="100%"><tr><td><p>Paid <strong>$40.00</strong></p></td></tr></table>'
            .'</body></html>');

        app(EmailInboxService::class)->processInboundWebhook($this->webhookPayload($inbox));

        $stored = EmailMessage::where('inbox_id', $inbox->id)->sole()->html_body;

        $this->assertStringContainsString('<table', $stored);
        $this->assertStringContainsString('Paid', $stored);
        $this->assertStringContainsString('<strong>$40.00</strong>', $stored);
        $this->assertStringNotContainsString('Receipt', $stored);
        $this->assertStringNotContainsString('color:red', $stored);
    }

    public function test_forwarding_re_sends_the_sanitised_body(): void
    {
        $inbox = $this->inbox(['forward_to' => 'ops@example.test']);
        $emails = $this->fakeResend($inbox);

        app(EmailInboxService::class)->processInboundWebhook($this->webhookPayload($inbox));

        $this->assertCount(1, $emails->sent);
        $this->assertSame(['ops@example.test'], $emails->sent[0]['to']);
        $this->assertNoExecutableMarkup($emails->sent[0]['html']);
        $this->assertStringContainsString('Forwarded message', $emails->sent[0]['html']);
        $this->assertStringContainsString('Invoice attached', $emails->sent[0]['html']);
    }

    public function test_a_row_stored_before_sanitisation_is_cleaned_on_render(): void
    {
        $user = User::factory()->create();
        $inbox = $this->inbox(['user_id' => $user->id]);

        // Written straight to the table: this is the shape of every message
        // that landed while processInboundWebhook() stored the payload verbatim.
        EmailMessage::create([
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'attacker@example.test',
            'to_addresses' => [$inbox->email_address],
            'subject' => 'Invoice attached',
            'html_body' => self::PAYLOAD,
            'text_body' => 'Invoice attached',
            'thread_id' => 'thread-legacy',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/inbox');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Inbox/Index'));

        $this->assertNoExecutableMarkup($response->inertiaProps('threads.0.messages.0.html_body'));
    }

    private function assertNoExecutableMarkup(?string $html): void
    {
        $this->assertNotNull($html);

        foreach (['<script', 'onclick', 'onerror', 'javascript:', 'alert('] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inbox(array $attributes = []): EmailInbox
    {
        return EmailInbox::create(array_merge([
            // Lazily, so passing a user_id does not also create a stray one.
            'user_id' => $attributes['user_id'] ?? User::factory()->create()->id,
            'email_address' => 'support-'.uniqid().'@inbox.test',
            'display_name' => 'Support',
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(EmailInbox $inbox): array
    {
        return [
            'type' => 'email.received',
            'data' => [
                'email_id' => 're_inbound_1',
                'to' => [$inbox->email_address],
            ],
        ];
    }

    /**
     * Stand in for the Resend client.
     *
     * `Resend::emails()->receiving->get()` hops through a property, which a
     * Mockery demeter chain cannot express — hence the hand-rolled double. The
     * returned object collects whatever the forward sends.
     */
    private function fakeResend(EmailInbox $inbox, string $html = self::PAYLOAD): object
    {
        $received = (object) [
            'headers' => [],
            'from' => 'attacker@example.test',
            'to' => [$inbox->email_address],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Invoice attached',
            'html' => $html,
            'text' => 'Invoice attached',
            'message_id' => '<inbound-1@example.test>',
            'attachments' => [],
        ];

        $emails = new class($received)
        {
            public object $receiving;

            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function __construct(object $received)
            {
                $this->receiving = new class($received)
                {
                    public function __construct(private object $received) {}

                    public function get(string $id): object
                    {
                        return $this->received;
                    }
                };
            }

            /**
             * @param  array<string, mixed>  $payload
             */
            public function send(array $payload): object
            {
                $this->sent[] = $payload;

                return (object) ['id' => 'resend_'.count($this->sent)];
            }
        };

        Resend::swap(new class($emails)
        {
            public function __construct(private object $emails) {}

            public function emails(): object
            {
                return $this->emails;
            }
        });

        return $emails;
    }
}
