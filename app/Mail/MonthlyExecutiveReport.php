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
