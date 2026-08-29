<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent the day we detect manager access to their Google Ads account was
 * revoked. Warm, factual, and concrete about what stops happening — the
 * strongest retention argument is the list of work that just went quiet.
 */
class LinkAccessLost extends AppMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
    ) {}

    public function build()
    {
        return $this->subject('We\'ve lost access to your Google Ads account')
            ->view('emails.link-access-lost');
    }
}
