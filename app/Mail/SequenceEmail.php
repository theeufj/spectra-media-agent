<?php

namespace App\Mail;

use App\Models\EmailSequence;
use App\Models\EmailSequenceStep;
use App\Services\EmailSequences\SequenceBodyRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One step of a follow-up chain.
 *
 * Sent from a named founder rather than a noreply address, because it invites
 * a reply — so the Reply-To has to reach a human, and the whole point of the
 * chain is lost if it does not.
 */
class SequenceEmail extends AppMailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public EmailSequence $sequence,
        public EmailSequenceStep $step,
        public array $variables,
        public string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->sequence->from_email, $this->sequence->from_name),
            // Falls back to the From address so a reply always lands somewhere
            // a person reads, even if nobody has configured the alias.
            replyTo: [new Address($this->sequence->reply_to ?: $this->sequence->from_email)],
            subject: $this->substitute($this->step->subject),
        );
    }

    public function content(): Content
    {
        // Resolved through the container rather than constructed here so the
        // preview pane and the send share one renderer — a preview that
        // escapes differently from the email is worse than none, because it
        // gets believed.
        $renderer = app(SequenceBodyRenderer::class);

        return new Content(
            view: 'emails.sequence',
            with: [
                'bodyHtml' => $renderer->body($this->step, $this->variables),
                'signature' => nl2br(e($this->sequence->signature)),
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }

    /** Subject-line placeholders; the body goes through the same renderer. */
    private function substitute(string $text): string
    {
        return app(SequenceBodyRenderer::class)->substitute($text, $this->variables);
    }

    public function attachments(): array
    {
        return [];
    }
}
