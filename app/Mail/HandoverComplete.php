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

    /**
     * @param  list<string>  $invited  addresses Google has sent an access
     *                                 invitation to, so the email can tell them
     *                                 to accept it rather than implying they
     *                                 already have a way in
     */
    public function __construct(
        public Customer $customer,
        public array $invited = [],
    ) {}

    public function build()
    {
        return $this->subject('Your Google Ads account is ready — here are the keys')
            ->view('emails.handover-complete');
    }
}
