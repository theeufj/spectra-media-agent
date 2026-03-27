<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support-ticket-created',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
