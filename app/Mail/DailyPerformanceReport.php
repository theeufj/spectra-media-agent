<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyPerformanceReport extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public array $summary;

    public function __construct(User $user, array $summary)
    {
        $this->user = $user;
        $this->summary = $summary;
    }

    public function envelope(): Envelope
    {
        $date = $this->summary['date'] ?? 'Yesterday';

        return new Envelope(
            subject: "Daily Performance Report — {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-performance-report',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
