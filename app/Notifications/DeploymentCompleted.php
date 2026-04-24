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
        $mail = (new MailMessage)
            ->subject('Campaign Deployed: ' . $this->campaign->name)
            ->greeting('Hi ' . $notifiable->name . ',');

        if ($this->failureCount === 0) {
            $mail->line("Your campaign \"{$this->campaign->name}\" has been successfully deployed to all platforms.")
                 ->line("{$this->successCount} strategy(ies) deployed successfully.");
        } else {
            $mail->line("Your campaign \"{$this->campaign->name}\" was partially deployed.")
                 ->line("{$this->successCount} succeeded, {$this->failureCount} failed.");
        }

        // Budget breakdown per platform
        if (!empty($this->strategies)) {
            $totalDaily = $this->campaign->daily_budget ?? 0;
            $mail->line('**Budget Breakdown**');

            foreach ($this->strategies as $strategy) {
                $platform = $strategy['platform'] ?? 'Unknown';
                $daily = isset($strategy['daily_budget']) ? '$' . number_format($strategy['daily_budget'], 2) . '/day' : 'not set';
                $duration = null;

                if (!empty($this->campaign->start_date) && !empty($this->campaign->end_date)) {
                    $start = \Carbon\Carbon::parse($this->campaign->start_date);
                    $end = \Carbon\Carbon::parse($this->campaign->end_date);
                    $days = $start->diffInDays($end) + 1;
                    $total = isset($strategy['daily_budget']) ? '$' . number_format($strategy['daily_budget'] * $days, 2) . " over {$days} days" : null;
                    $duration = $total;
                }

                $line = "• **{$platform}**: {$daily}";
                if ($duration) {
                    $line .= " ({$duration})";
                }
                $mail->line($line);
            }

            if (count($this->strategies) > 1) {
                $mail->line("**Total daily budget: \$" . number_format($totalDaily, 2) . "/day**");
            }
        }

        return $mail
            ->action('View Campaign', url('/campaigns/' . $this->campaign->id))
            ->salutation('— Site to Spend');
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
