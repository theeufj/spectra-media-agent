<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Campaign $campaign,
        protected int $successCount,
        protected int $failureCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Campaign Deployed: ' . $this->campaign->name);

        if ($this->failureCount === 0) {
            $mail->line("Your campaign \"{$this->campaign->name}\" has been successfully deployed to all platforms.")
                 ->line("{$this->successCount} strategy(ies) deployed successfully.");
        } else {
            $mail->line("Your campaign \"{$this->campaign->name}\" was partially deployed.")
                 ->line("{$this->successCount} succeeded, {$this->failureCount} failed.");
        }

        return $mail
            ->action('View Campaign', url('/campaigns/' . $this->campaign->id))
            ->line('Thank you for using Spectra!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'type' => $this->failureCount === 0 ? 'success' : 'partial_failure',
        ];
    }
}
