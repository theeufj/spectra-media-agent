<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConversionTrackingReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Customer $customer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $setupUrl = url(route('customers.gtm.setup', $this->customer->id, false));

        return (new MailMessage)
            ->subject('Action required: install your conversion tracking snippet')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line("We've set up conversion tracking for **{$this->customer->name}**. Your Google Tag Manager container is provisioned and all ad conversion tags are configured.")
            ->line('The final step is adding a small snippet to your website — it takes about two minutes.')
            ->action('Install snippet →', $setupUrl)
            ->line('Once installed, every lead and sale from your campaigns will be tracked automatically. You can verify installation directly from the setup page.')
            ->salutation('— Site to Spend');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'conversion_tracking_ready',
            'customer_id' => $this->customer->id,
        ];
    }
}
