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
 * Sent when credit balance is running low.
 */
class AdSpendLowBalance extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public AdSpendCredit $credit,
        public float $daysRemaining
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Low Ad Spend Balance - ' . number_format($this->daysRemaining, 1) . ' Days Remaining',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-spend-low-balance',
        );
    }
}
