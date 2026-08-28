<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * The engagement-closing email for a one-time setup: the account is built,
 * it's theirs, and these are the two steps between them and live ads.
 */
class HandoverComplete extends AppMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
    ) {}

    public function build()
    {
        return $this->subject('Your Google Ads account is ready — here are the keys')
            ->view('emails.handover-complete');
    }
}
