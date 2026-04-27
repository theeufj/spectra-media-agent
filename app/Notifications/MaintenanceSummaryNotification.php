<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email per customer per maintenance run summarising everything
 * the agents did. Replaces per-agent individual emails.
 */
class MaintenanceSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private array $changeSummary,
        private int $campaignsProcessed,
    ) {}

    public function via(object $notifiable): array
    {
        // Only send if something actually changed
        $total = array_sum(array_column($this->changeSummary, 'total_changes'));
        return $total > 0 ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your Campaign Optimisation Summary')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line("Our agents completed their daily optimisation run across {$this->campaignsProcessed} campaign(s). Here's what changed:");

        foreach ($this->changeSummary as $campaignName => $results) {
            if (($results['total_changes'] ?? 0) === 0) {
                continue;
            }

            $mail->line("**{$campaignName}**");

            if (!empty($results['healed'])) {
                $mail->line('- Resolved ' . $results['healed'] . ' delivery issue(s)');
            }
            if (!empty($results['keywords_added'])) {
                $mail->line('- Added ' . $results['keywords_added'] . ' new keyword(s) from search term data');
            }
            if (!empty($results['negatives_added'])) {
                $mail->line('- Added ' . $results['negatives_added'] . ' negative keyword(s) to reduce wasted spend');
            }
            if (!empty($results['budget_adjustments'])) {
                $mail->line('- Made ' . $results['budget_adjustments'] . ' budget adjustment(s)');
            }
            if (!empty($results['creative_adjustments'])) {
                $mail->line('- Generated ' . $results['creative_adjustments'] . ' new ad creative variation(s)');
            }
            if (!empty($results['strategy_graduated'])) {
                $mail->line('- Upgraded bidding strategy: ' . $results['strategy_graduated']);
            }
        }

        $mail->line('No action is required on your part. All changes are applied automatically to improve campaign performance.');

        return $mail->salutation('— Site to Spend');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'                => 'maintenance_summary',
            'campaigns_processed' => $this->campaignsProcessed,
            'changes'             => $this->changeSummary,
        ];
    }
}
