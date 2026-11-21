<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConversionTrackingReady extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
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
        $setupUrl = $this->tenantUrl(route('customers.gtm.setup', $this->customer, false));

        return $this->brandedMail()
            ->subject('Action required: install your conversion tracking snippet')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your site measurement container for **{$this->customer->name}** is ready to install.")
            ->line('Add the snippet to your website and choose the successful signup, enquiry or purchase that each campaign should measure.')
            ->action('Install snippet →', $setupUrl)
            ->line('After installation, run the exact-event test from the campaign and check that the selected advertising destination saw it.')
            ->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversion_tracking_ready',
            'customer_id' => $this->customer->id,
        ];
    }
}
