<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a customer that a link request is waiting in their Google Ads account.
 *
 * The invitation itself lives in Google's interface — this email exists only
 * because nobody checks that interface unprompted, and an unaccepted invitation
 * is the difference between a customer we can advertise for and one we cannot.
 */
class GoogleAdsLinkInvitation extends AppMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $clientAccountId
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action needed: approve access to your Google Ads account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.google-ads-link-invitation',
            with: [
                'customerName' => $this->customer->name,
                // Google displays account ids in 123-456-7890 form; showing the
                // same shape lets the customer match it against what they see.
                'accountId' => $this->formatAccountId($this->clientAccountId),
            ],
        );
    }

    private function formatAccountId(string $id): string
    {
        return strlen($id) === 10
            ? substr($id, 0, 3).'-'.substr($id, 3, 3).'-'.substr($id, 6)
            : $id;
    }

    public function attachments(): array
    {
        return [];
    }
}
