<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A platform ad account finished provisioning.
 *
 * Between signup and the MCC sub-account existing, the campaign wizard's
 * platform step is blocked with no ETA. This is the signal that it cleared —
 * in-app only; an email about internal account plumbing would be noise.
 */
class PlatformAccountReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Customer $customer,
        protected string $platform,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "{$this->platform} is ready",
            'message' => "Your {$this->platform} account is set up — you can now include it in campaigns.",
            'action_url' => url('/campaigns/wizard'),
            'action_text' => 'Create a Campaign',
            'customer_id' => $this->customer->id,
            'platform' => $this->platform,
            'type' => 'platform_account_ready',
        ];
    }
}
