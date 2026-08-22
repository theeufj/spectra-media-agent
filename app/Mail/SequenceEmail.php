<?php

namespace App\Mail;

use App\Models\EmailSequence;
use App\Models\EmailSequenceStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
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
class SequenceEmail extends Mailable implements ShouldQueue
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
        return new Content(
            view: 'emails.sequence',
            with: [
                'bodyHtml' => nl2br(e($this->substitute($this->step->body))),
                'signature' => nl2br(e($this->sequence->signature)),
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }

    /**
     * Substitute {{ first_name }} and friends.
     *
     * A placeholder with no value collapses to nothing rather than printing
     * its own name — "Hi {{ first_name }}," reaching a real prospect is worse
     * than "Hi," and there is no value in the middle.
     */
    private function substitute(string $text): string
    {
        foreach ($this->variables as $key => $value) {
            $text = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], (string) $value, $text);
        }

        // Anything still unresolved, and the space before it.
        return trim(preg_replace('/\s*\{\{\s*[a-z_]+\s*\}\}/i', '', $text) ?? $text);
    }

    public function attachments(): array
    {
        return [];
    }
}
