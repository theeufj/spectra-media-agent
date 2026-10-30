<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/** Account access is confirmed; website event testing and billing remain separate. */
class HandoverComplete extends AppMailable
{
    use Queueable, SerializesModels;

    /** @param list<string> $invited Google identities with confirmed administrator access. */
    public function __construct(
        public Customer $customer,
        public array $invited = [],
    ) {}

    public function build()
    {
        return $this->subject('Your Google Ads account access is confirmed')
            ->view('emails.handover-complete');
    }
}
