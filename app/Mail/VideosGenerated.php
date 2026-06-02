<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideosGenerated extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public Campaign $campaign;
    public int $videoCount;

    public function __construct(User $user, Campaign $campaign, int $videoCount = 1)
    {
        $this->user = $user;
        $this->campaign = $campaign;
        $this->videoCount = $videoCount;
    }

    public function envelope(): Envelope
    {
        $plural = $this->videoCount === 1 ? 'Video' : 'Videos';
        return new Envelope(
            subject: "{$this->videoCount} {$plural} Ready for \"{$this->campaign->name}\"",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.videos_generated',
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
