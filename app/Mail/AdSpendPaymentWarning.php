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
 * Sent when a payment fails for the first time.
 * Customer has 24 hours to fix their payment method.
 */
class AdSpendPaymentWarning extends Mailable
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
            subject: '⚠️ Payment Failed - Action Required Within 24 Hours',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-spend-payment-warning',
        );
    }
}
