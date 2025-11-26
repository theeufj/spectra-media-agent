<?php

namespace App\Mail;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when payment continues to fail after grace period.
 * Budgets have been reduced to 50%.
 */
class AdSpendPaymentFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public AdSpendCredit $credit,
        public string $error
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🚨 Payment Still Failing - Budgets Reduced',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-spend-payment-failed',
        );
    }
}
