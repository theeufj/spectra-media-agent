<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CriticalAgentAlert
 *
 * Real-time notification for critical campaign events detected by agents:
 * - Disapproval spikes (multiple ads disapproved in short time)
 * - Budget exhaustion (daily budget nearly or fully spent early in the day)
 * - Conversion drops (significant drop vs previous period)
 * - Spend anomalies (spend suddenly spikes or drops)
 */
class CriticalAgentAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public string $alertType;
    public string $title;
    public string $message;
    public array $details;

    public function __construct(string $alertType, string $title, string $message, array $details = [])
    {
        $this->alertType = $alertType;
        $this->title = $title;
        $this->message = $message;
        $this->details = $details;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("✨ {$this->title}")
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($this->message);

        if (!empty($this->details['campaign_name'])) {
            $mail->line("Campaign: {$this->details['campaign_name']}");
        }

        if (!empty($this->details['issues'])) {
            $mail->line("Here is what we fixed:");
            foreach ($this->details['issues'] as $issue) {
                $issueText = is_array($issue) ? ($issue['message'] ?? json_encode($issue)) : $issue;
                $mail->line("- {$issueText}");
            }
        }

        if (!empty($this->details['action_required'])) {
            $mail->line("Action Required: {$this->details['action_required']}");
        } else {
            $mail->line("You do not need to take any action. Our agents have automatically resolved these issues and optimized your campaign.");
        }

        if (!empty($this->details['campaign_id'])) {
            $mail->action('View Campaign', url('/campaigns/' . $this->details['campaign_id']));
        }

        return $mail->salutation('— Site to Spend');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'alert_type' => $this->alertType,
            'title' => $this->title,
            'message' => $this->message,
            'details' => $this->details,
            'severity' => $this->details['severity'] ?? 'critical',
        ];
    }
}
