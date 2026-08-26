<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class DailyPerformanceReport extends AppMailable
{
    use Queueable, SerializesModels;

    public User $user;

    public array $summary;

    public function __construct(User $user, array $summary)
    {
        $this->user = $user;
        $this->summary = $summary;

        // SendDailyPerformanceReports queues one of these per user in a tight
        // loop, so they all hit the 'resend' limiter (4/sec) in the same instant.
        // Spread them, mirroring CriticalAgentAlert.
        $this->delay(now()->addSeconds(rand(0, 30)));

        // The 'default' supervisor runs tries:1. RateLimited *releases* a job when
        // the limit is hit and that release counts as an attempt, so the first
        // rate-limited send exceeded tries and died with
        // MaxAttemptsExceededException. 'notifications' is the queue sized for
        // this (tries:3) and is where the other transactional mail already goes.
        $this->onQueue('notifications');
    }

    /**
     * Retry on a deadline rather than an attempt count.
     *
     * With RateLimited, every release burns an attempt, so a fixed $tries budget
     * is really a budget of "times we were allowed to be rate limited" — not a
     * budget of genuine failures. A deadline lets the limiter defer this mail as
     * often as it needs to within the window.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
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

    public function middleware(): array
    {
        return [new RateLimited('resend')];
    }

    public function attachments(): array
    {
        return [];
    }
}
