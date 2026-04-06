<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonthlyExecutiveReport extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public array $report;
    protected ?string $pdfContent;

    public function __construct(User $user, array $report, ?string $pdfContent = null)
    {
        $this->user = $user;
        $this->report = $report;
        $this->pdfContent = $pdfContent;
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

    public function attachments(): array
    {
        if ($this->pdfContent) {
            $customerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->report['customer_name']);
            $date = $this->report['period']['end'] ?? now()->format('Y-m-d');

            return [
                Attachment::fromData(fn () => $this->pdfContent, "Monthly_Report_{$customerName}_{$date}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
