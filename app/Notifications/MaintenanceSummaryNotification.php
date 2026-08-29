<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email per customer per maintenance run summarising everything
 * the agents did. Replaces per-agent individual emails.
 *
 * Carries the customer so the email wears their skin (TenantAware) and
 * files under them in the email log — this is a nightly customer-facing
 * send, not an internal alert.
 */
class MaintenanceSummaryNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
    use Queueable;

    public function __construct(
        protected Customer $customer,
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
        // The receipt header: one number for the whole run. This email is
        // the recurring proof of work — the strongest answer to "what am I
        // paying for" is the specific list of what would have gone unfixed.
        $totalChanges = array_sum(array_column($this->changeSummary, 'total_changes'));
        $totals = [
            'healed' => 'delivery issue(s) resolved',
            'keywords_added' => 'new keyword(s) mined from real searches',
            'negatives_added' => 'wasted-spend term(s) blocked',
            'budget_adjustments' => 'budget adjustment(s)',
            'creative_adjustments' => 'new ad variation(s) generated',
        ];

        $mail = $this->brandedMail()
            ->subject("{$totalChanges} improvement(s) made across your campaigns today")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("While you slept, our agents made **{$totalChanges} change(s)** across {$this->campaignsProcessed} campaign(s):");

        foreach ($totals as $key => $label) {
            $count = array_sum(array_map(fn ($r) => (int) ($r[$key] ?? 0), $this->changeSummary));
            if ($count > 0) {
                $mail->line("- {$count} {$label}");
            }
        }

        $mail->line('By campaign:');

        foreach ($this->changeSummary as $campaignName => $results) {
            if (($results['total_changes'] ?? 0) === 0) {
                continue;
            }

            $mail->line("**{$campaignName}**");

            if (! empty($results['healed'])) {
                $mail->line('- Resolved '.$results['healed'].' delivery issue(s)');
            }
            if (! empty($results['keywords_added'])) {
                $mail->line('- Added '.$results['keywords_added'].' new keyword(s) from search term data');
            }
            if (! empty($results['negatives_added'])) {
                $mail->line('- Added '.$results['negatives_added'].' negative keyword(s) to reduce wasted spend');
            }
            if (! empty($results['budget_adjustments'])) {
                $mail->line('- Made '.$results['budget_adjustments'].' budget adjustment(s)');
            }
            if (! empty($results['creative_adjustments'])) {
                $mail->line('- Generated '.$results['creative_adjustments'].' new ad creative variation(s)');
            }
            if (! empty($results['strategy_graduated'])) {
                $mail->line('- Upgraded bidding strategy: '.$results['strategy_graduated']);
            }
        }

        $mail->line('No action is required on your part. All changes are applied automatically to improve campaign performance.');

        return $mail->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'maintenance_summary',
            'campaigns_processed' => $this->campaignsProcessed,
            'changes' => $this->changeSummary,
        ];
    }
}
