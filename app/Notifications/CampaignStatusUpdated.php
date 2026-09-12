<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignStatusUpdated extends Notification
{
    use \App\Notifications\Concerns\TenantAware;
    use Queueable;

    public $campaign;

    /**
     * Create a new notification instance.
     */
    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /*
         * Database too, for the same reason DeploymentFailed gives: a change a
         * customer only hears about if they happen to open the right email is a
         * change they never hear about. This is the event that tells them their
         * ads have stopped serving.
         */
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->subject('Campaign Status Update: '.$this->campaign->name)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('The status of your campaign "'.$this->campaign->name.'" has changed.')
            ->line('New Status: '.$this->campaign->primary_status)
            ->action('View Campaign', $this->tenantUrl(route('campaigns.show', $this->campaign, false)))
            ->salutation($this->teamSalutation());
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    /**
     * Plain English for the statuses Google actually reports.
     *
     * primary_status holds the SDK's own enum names — ELIGIBLE, PAUSED,
     * MISCONFIGURED — which are not words to put in front of a customer. What
     * they need to know is whether their ads are running and, if not, what to
     * do about it.
     *
     * @return array{0: string, 1: string}
     */
    private function describe(): array
    {
        $name = $this->campaign->name;

        return match ($this->campaign->primary_status) {
            'ELIGIBLE' => ["{$name} is running", 'Your ads are live and serving.'],
            'PAUSED' => ["{$name} has been paused", 'It is not serving ads right now. Resume it when you are ready.'],
            'REMOVED' => ["{$name} was removed", 'This campaign no longer exists on the ad platform.'],
            'ENDED' => ["{$name} has finished", 'Its scheduled end date has passed.'],
            'PENDING' => ["{$name} has not started yet", 'It is scheduled to begin on its start date.'],
            'LIMITED' => ["{$name} is limited", 'It is held back — usually by budget. Raising the budget lets it serve more.'],
            'MISCONFIGURED' => ["{$name} needs attention", 'The ad platform has flagged a problem that stops it serving.'],
            'NOT_ELIGIBLE' => ["{$name} cannot serve", 'The ad platform will not run it in its current state.'],
            default => ["{$name} changed status", 'Open the campaign to see where it stands.'],
        };
    }

    /**
     * The bell reads title/message/action_url out of this payload.
     *
     * Without a title the Notification model falls back to the literal string
     * "Notification" to satisfy its NOT NULL column — which is what 702 rows in
     * production say, for the event that tells a customer their ads stopped.
     */
    public function toArray(object $notifiable): array
    {
        [$title, $message] = $this->describe();

        return [
            'title' => $title,
            'message' => $message,
            'action_url' => $this->tenantUrl(route('campaigns.show', $this->campaign, false)),
            'action_text' => 'Open campaign',
            'campaign_id' => $this->campaign->id,
            'status' => $this->campaign->primary_status,
        ];
    }
}
