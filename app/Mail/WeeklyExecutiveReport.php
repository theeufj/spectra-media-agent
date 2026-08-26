<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class WeeklyExecutiveReport extends AppMailable
{
    use Queueable, SerializesModels;

    public User $user;

    public array $report;

    public function __construct(User $user, array $report)
    {
        $this->user = $user;
        $this->report = $report;

        // Queued one-per-user in a loop, so these all hit the 'resend' limiter
        // (4/sec) at once. Spread them out.
        $this->delay(now()->addSeconds(rand(0, 30)));

        // RateLimited releases the job when the limit is hit, and each release
        // counts as an attempt — on the 'default' supervisor (tries:1) the first
        // rate-limited send died with MaxAttemptsExceededException.
        $this->onQueue('notifications');
    }

    /**
     * Retry on a deadline, not an attempt count: with RateLimited a $tries budget
     * is really a budget of "times we may be rate limited", not of real failures.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
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

    public function middleware(): array
    {
        return [new RateLimited('resend')];
    }

    public function attachments(): array
    {
        return [];
    }
}
