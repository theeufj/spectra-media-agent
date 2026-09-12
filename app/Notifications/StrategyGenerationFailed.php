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
    use \App\Notifications\Concerns\TenantAware;
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
        return $this->brandedMail()
            ->subject('Strategy Generation Failed: '.$this->campaign->name)
            ->error()
            ->greeting('Hi '.$notifiable->name.',')
            ->line("We were unable to generate a strategy for your campaign \"{$this->campaign->name}\".")
            ->line('Reason: '.$this->error)
            ->action('View Campaign', $this->tenantUrl(route('campaigns.show', $this->campaign->id, false)))
            ->line('Please check your knowledge base content and try again.')
            ->salutation($this->teamSalutation());
    }

    /**
     * The bell reads title/message/action_url out of this payload.
     *
     * Without them the Notification model falls back to the literal string
     * "Notification" and an empty body to satisfy its NOT NULL columns, which
     * is what 273 rows in production say. The one thing the customer needs —
     * the reason, already carried in `error` — was never shown to them.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'We could not build a strategy for '.$this->campaign->name,
            'message' => $this->error,
            'action_url' => url('/campaigns/'.$this->campaign->id.'/strategies'),
            'action_text' => 'Open campaign',
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'error' => $this->error,
            'type' => 'strategy_generation_failed',
        ];
    }
}
