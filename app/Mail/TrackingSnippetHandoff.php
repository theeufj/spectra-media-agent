<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The tracking snippet, sent to whoever actually manages the website.
 *
 * Installing it is the one step on the critical path that an owner-operator
 * usually cannot do themselves, and the setup page previously offered them no
 * way out of it. Without the snippet there are no conversions, so the product
 * can never show the customer what their advertising earned.
 *
 * Addressed to a third party who has no account with us, so it says who asked,
 * what the code does, and nothing that assumes familiarity with the product.
 */
class TrackingSnippetHandoff extends AppMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{head: string, body: string}  $snippet
     */
    public function __construct(
        public Customer $customer,
        public array $snippet,
        public string $requestedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tracking code for {$this->customer->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tracking-snippet-handoff',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
