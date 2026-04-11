<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduledJobFailed extends Notification
{
    use Queueable;

    public function __construct(
        protected string $jobName,
        protected string $errorMessage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[Site to Spend] Scheduled Job Failed: {$this->jobName}")
            ->error()
            ->greeting('Scheduled Job Failure Alert')
            ->line("The scheduled job **{$this->jobName}** has failed.")
            ->line("Error: {$this->errorMessage}")
            ->line('Time: ' . now()->toDateTimeString())
            ->action('View Horizon Dashboard', url('/horizon'))
            ->line('Please investigate and resolve this issue promptly.');
    }
}
