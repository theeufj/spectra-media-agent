<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MccAccountCreationEligible extends Notification
{
    use Queueable;

    public function __construct(
        protected string $mccId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Site to Spend] MCC can now create accounts via the API 🎉')
            ->success()
            ->greeting('MCC Account Creation Unlocked')
            ->line("Manager account **{$this->mccId}** has passed Google's eligibility checks and can now create client accounts programmatically (CreateCustomerClient).")
            ->line('The linked account has crossed the ~US$1,000 lifetime spend threshold with a clean policy history.')
            ->line('You can now enable automated sub-account provisioning.')
            ->line('Detected: ' . now()->toDateTimeString() . ' UTC');
    }
}
