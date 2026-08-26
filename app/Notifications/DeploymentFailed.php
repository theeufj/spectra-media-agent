<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentFailed extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
    use Queueable;

    public function __construct(
        protected Campaign $campaign,
        protected string $error,
    ) {}

    public function via(object $notifiable): array
    {
        // Database too: a deploy failure the user only hears about if they
        // happen to open the right email is a deploy failure they never hear
        // about. The bell is the in-product record.
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deployment Failed: '.$this->campaign->name)
            ->error()
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your campaign \"{$this->campaign->name}\" failed to deploy.")
            ->line('Error: '.$this->error)
            ->action('View Details', $this->tenantUrl(route('campaigns.show', $this->campaign->id, false)))
            ->line('Our team has been notified. You can also try redeploying from the campaign page.')
            ->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        // title/message/action_* are lifted into columns by the custom
        // Notification model's creating hook — they are what the bell shows.
        return [
            'title' => "Deployment failed: {$this->campaign->name}",
            'message' => $this->error,
            'action_url' => $this->tenantUrl(route('campaigns.show', $this->campaign->id, false)),
            'action_text' => 'View Campaign',
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'error' => $this->error,
            'type' => 'deployment_failed',
        ];
    }
}
