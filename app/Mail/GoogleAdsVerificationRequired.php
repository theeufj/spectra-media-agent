<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GoogleAdsVerificationRequired extends AppMailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Customer $customer,
        public ?Campaign $campaign = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action Required: Verify Your Google Ads Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.google-ads-verification-required',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
