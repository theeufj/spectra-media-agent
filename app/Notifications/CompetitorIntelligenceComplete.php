<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompetitorIntelligenceComplete extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
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
        $mail = $this->brandedMail()
            ->subject('Competitor Intelligence Report: '.$this->customer->name)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your competitor intelligence analysis for **'.$this->customer->name.'** is complete.');

        foreach ($this->summary as $item) {
            $mail->line('• '.$item);
        }

        return $mail
            ->action('View Competitor Analysis', $this->tenantUrl('/seo/competitors'))
            ->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'summary' => $this->summary,
        ];
    }
}
