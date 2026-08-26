<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentCompleted extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
    use Queueable;

    public function __construct(
        protected Campaign $campaign,
        protected int $successCount,
        protected int $failureCount,
        protected array $strategies = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->failureCount > 0 && $this->successCount === 0) {
            return (new MailMessage)
                ->subject('Campaign deployment issue: '.$this->campaign->name)
                ->greeting('Hi '.$notifiable->name.',')
                ->line("We ran into an issue deploying your campaign \"{$this->campaign->name}\" and our team has been notified.")
                ->line("We'll be in touch shortly to get this resolved.")
                ->action('View Campaign', $this->tenantUrl(route('campaigns.show', $this->campaign->id, false)))
                ->salutation($this->teamSalutation());
        }

        $mail = (new MailMessage)
            ->subject('Your campaign is live: '.$this->campaign->name)
            ->greeting('Great news, '.$notifiable->name.'!')
            ->line("Your campaign **\"{$this->campaign->name}\"** is now live and your ads are running.");

        if ($this->failureCount > 0) {
            $mail->line("({$this->successCount} platform(s) deployed successfully — {$this->failureCount} encountered an issue and our team has been notified.)");
        }

        // Budget breakdown per platform
        if (! empty($this->strategies)) {
            $totalDaily = $this->campaign->daily_budget ?? 0;
            $mail->line('**What\'s running:**');

            foreach ($this->strategies as $strategy) {
                $platform = $strategy['platform'] ?? 'Unknown';
                $daily = isset($strategy['daily_budget']) ? '$'.number_format($strategy['daily_budget'], 2).'/day' : null;

                $line = "• {$platform}";
                if ($daily) {
                    $line .= " — {$daily}";
                }

                if (! empty($this->campaign->start_date) && ! empty($this->campaign->end_date)) {
                    $start = \Carbon\Carbon::parse($this->campaign->start_date);
                    $end = \Carbon\Carbon::parse($this->campaign->end_date);
                    $days = $start->diffInDays($end) + 1;
                    if (isset($strategy['daily_budget'])) {
                        $line .= ' ($'.number_format($strategy['daily_budget'] * $days, 2)." over {$days} days)";
                    }
                }

                $mail->line($line);
            }

            if (count($this->strategies) > 1) {
                $mail->line('**Total: $'.number_format($totalDaily, 2).'/day**');
            }
        }

        return $mail
            ->line('Your ads are scheduled to begin serving from tomorrow (campaigns start the day after deployment), and performance data appears in your dashboard once they do.')
            ->action('View Your Dashboard', $this->tenantUrl(route('dashboard', absolute: false)))
            ->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        $allFailed = $this->failureCount > 0 && $this->successCount === 0;

        return [
            'title' => $allFailed
                ? "Deployment issue: {$this->campaign->name}"
                : "Your campaign is live: {$this->campaign->name}",
            'message' => $allFailed
                ? 'We ran into an issue deploying your campaign and our team has been notified.'
                : ($this->failureCount > 0
                    ? "{$this->successCount} platform(s) live, {$this->failureCount} had an issue."
                    : 'Your ads are running.'),
            'action_url' => $this->tenantUrl(route('campaigns.show', $this->campaign->id, false)),
            'action_text' => 'View Campaign',
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'type' => $this->failureCount === 0 ? 'success' : 'partial_failure',
        ];
    }
}
