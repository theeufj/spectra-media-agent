<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class StrategyGenerationFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Campaign $campaign,
        protected string $error,
    ) {}

    public function via(object $notifiable): array
    {
        $cacheKey = "strategy_fail_mail:{$this->campaign->id}:{$notifiable->id}";
        if (Cache::has($cacheKey)) {
            return ['database'];
        }
        Cache::put($cacheKey, true, now()->addHours(24));

        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Strategy Generation Failed: ' . $this->campaign->name)
            ->error()
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line("We were unable to generate a strategy for your campaign \"{$this->campaign->name}\".")
            ->line('Reason: ' . $this->error)
            ->action('View Campaign', url('/campaigns/' . $this->campaign->id))
            ->line('Please check your knowledge base content and try again.')
            ->salutation('— Site to Spend');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'error' => $this->error,
            'type' => 'strategy_generation_failed',
        ];
    }
}
