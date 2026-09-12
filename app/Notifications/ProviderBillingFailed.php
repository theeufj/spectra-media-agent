<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An AI provider has stopped serving because of billing.
 *
 * Not queued, deliberately: the queue is Redis, and this fires during an
 * outage — assuming a second dependency is healthy is how the alert about the
 * silence becomes silent too.
 */
class ProviderBillingFailed extends Notification
{
    public function __construct(
        protected string $provider,
        protected string $signal,
        protected ?string $detail = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("[Site to Spend] {$this->provider} has stopped — billing")
            ->error()
            ->greeting("{$this->provider} is refusing requests")
            ->line("It matched: \"{$this->signal}\".")
            ->line('While this lasts, everything that depends on that provider is down — for Gemini that is strategy generation, brand extraction, creative, the copilot and the public demo.')
            ->line('Time: '.now()->toDateTimeString().' ('.config('app.timezone').')');

        if ($this->provider === 'Gemini') {
            $project = config('services.google.project_id');
            $message->action(
                'Enable billing on the Google project',
                $project
                    ? "https://console.cloud.google.com/billing/enable?project={$project}"
                    : 'https://console.cloud.google.com/billing',
            );
        } else {
            $message->action('Top up OpenRouter', 'https://openrouter.ai/credits');
        }

        if ($this->detail) {
            $message->line('Upstream said:')
                ->line(mb_substr($this->detail, 0, 400));
        }

        return $message->line('You will not get another email about this provider for an hour.');
    }
}
