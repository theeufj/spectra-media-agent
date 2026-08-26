<?php

namespace App\Mail;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We built your first campaign — come and look."
 *
 * Replaces the crawl-completed notice for accounts that qualified. That email
 * told someone a job had finished, which is our news, not theirs. This one
 * tells them there is something waiting with their business in it.
 */
class FirstCampaignReady extends AppMailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your first campaign is ready to review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.first-campaign-ready',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
