<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt + roadmap for the one-time setup purchase. Sets expectations for
 * the whole engagement in one email: what we build, that campaigns arrive
 * paused, and that the account becomes theirs at handover.
 */
class SetupFeeReceived extends AppMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $userName,
    ) {}

    public function build()
    {
        return $this->subject('Payment received — your Google Ads setup is underway')
            ->view('emails.setup-fee-received');
    }
}
