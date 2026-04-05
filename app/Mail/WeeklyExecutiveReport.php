<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyExecutiveReport extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public array $report;

    public function __construct(User $user, array $report)
    {
        $this->user = $user;
        $this->report = $report;
    }

    public function envelope(): Envelope
    {
        $period = ucfirst($this->report['period']['type'] ?? 'Weekly');

        return new Envelope(
            subject: "{$period} Executive Report — {$this->report['customer_name']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-executive-report',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
