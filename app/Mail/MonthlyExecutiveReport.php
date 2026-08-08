<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class MonthlyExecutiveReport extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;

    public array $report;

    protected ?string $pdfPath;

    public function __construct(User $user, array $report, ?string $pdfPath = null)
    {
        $this->user = $user;
        $this->report = $report;
        $this->pdfPath = $pdfPath;

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
        return new Envelope(
            subject: "Monthly Performance Report — {$this->report['customer_name']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.monthly-executive-report',
        );
    }

    public function middleware(): array
    {
        return [new RateLimited('resend')];
    }

    public function attachments(): array
    {
        if ($this->pdfPath) {
            $customerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->report['customer_name']);
            $date = $this->report['period']['end'] ?? now()->format('Y-m-d');

            return [
                Attachment::fromStorageDisk('local', $this->pdfPath)
                    ->as("Monthly_Report_{$customerName}_{$date}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
