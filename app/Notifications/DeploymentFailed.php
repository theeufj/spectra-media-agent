<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Campaign $campaign,
        protected string $error,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deployment Failed: ' . $this->campaign->name)
            ->error()
            ->line("Your campaign \"{$this->campaign->name}\" failed to deploy.")
            ->line('Error: ' . $this->error)
            ->action('View Details', url('/campaigns/' . $this->campaign->id))
            ->line('Our team has been notified. You can also try redeploying from the campaign page.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'error' => $this->error,
            'type' => 'deployment_failed',
        ];
    }
}
