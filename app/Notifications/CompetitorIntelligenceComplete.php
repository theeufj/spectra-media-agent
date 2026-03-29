<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompetitorIntelligenceComplete extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Customer $customer,
        protected array $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Competitor Intelligence Report: ' . $this->customer->name)
            ->line('Your competitor intelligence analysis is complete.');

        foreach ($this->summary as $item) {
            $mail->line('• ' . $item);
        }

        return $mail
            ->action('View Dashboard', url('/customers/' . $this->customer->id))
            ->line('Thank you for using Spectra!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'summary' => $this->summary,
        ];
    }
}
