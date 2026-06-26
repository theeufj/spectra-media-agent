<?php

namespace App\Notifications;

use App\Models\Campaign;
use Carbon\Carbon;
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
        protected array $strategies = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->failureCount > 0 && $this->successCount === 0) {
            return (new MailMessage)
                ->subject('Campaign deployment issue: ' . $this->campaign->name)
                ->greeting('Hi ' . $notifiable->name . ',')
                ->line("We ran into an issue deploying your campaign \"{$this->campaign->name}\" and our team has been notified.")
                ->line("We'll be in touch shortly to get this resolved.")
                ->action('View Campaign', url('/campaigns/' . $this->campaign->id))
                ->salutation('— The Site to Spend Team');
        }

        $mail = (new MailMessage)
            ->subject('Your campaign is live: ' . $this->campaign->name)
            ->greeting('Great news, ' . $notifiable->name . '!')
            ->line("Your campaign **\"{$this->campaign->name}\"** is now live and your ads are running.");

        if ($this->failureCount > 0) {
            $mail->line("({$this->successCount} platform(s) deployed successfully — {$this->failureCount} encountered an issue and our team has been notified.)");
        }

        // Budget breakdown per platform
        if (!empty($this->strategies)) {
            $totalDaily = $this->campaign->daily_budget ?? 0;
            $mail->line('**What\'s running:**');

            foreach ($this->strategies as $strategy) {
                $platform = $strategy['platform'] ?? 'Unknown';
                $daily = isset($strategy['daily_budget']) ? '$' . number_format($strategy['daily_budget'], 2) . '/day' : null;

                $line = "• {$platform}";
                if ($daily) {
                    $line .= " — {$daily}";
                }

                if (!empty($this->campaign->start_date) && !empty($this->campaign->end_date)) {
                    $start = \Carbon\Carbon::parse($this->campaign->start_date);
                    $end = \Carbon\Carbon::parse($this->campaign->end_date);
                    $days = $start->diffInDays($end) + 1;
                    if (isset($strategy['daily_budget'])) {
                        $line .= " ($" . number_format($strategy['daily_budget'] * $days, 2) . " over {$days} days)";
                    }
                }

                $mail->line($line);
            }

            if (count($this->strategies) > 1) {
                $mail->line("**Total: \$" . number_format($totalDaily, 2) . "/day**");
            }
        }

        return $mail
            ->line('Performance data will start appearing in your dashboard within a few hours as Google begins serving your ads.')
            ->action('View Your Dashboard', url(route('dashboard')))
            ->salutation('— The Site to Spend Team');
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
