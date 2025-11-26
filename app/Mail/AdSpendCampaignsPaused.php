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
 * Sent when campaigns have been paused due to payment failure.
 */
class AdSpendCampaignsPaused extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public AdSpendCredit $credit
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⛔ Campaigns Paused - Payment Required',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-spend-campaigns-paused',
        );
    }
}
