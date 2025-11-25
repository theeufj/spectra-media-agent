<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SitemapCrawlCompleted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $sitemapUrl;
    public $totalPages;
    public $userName;

    /**
     * Create a new message instance.
     */
    public function __construct(string $sitemapUrl, int $totalPages, string $userName)
    {
        $this->sitemapUrl = $sitemapUrl;
        $this->totalPages = $totalPages;
        $this->userName = $userName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Sitemap Has Been Processed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.sitemap-crawl-completed',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
