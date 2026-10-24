<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Support\ConfirmedBudgetChanges;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email per customer per maintenance run summarising everything
 * the agents did. Replaces per-agent individual emails.
 *
 * Carries the customer so the email wears their skin (TenantAware) and
 * files under them in the email log — this is a customer-facing
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
        return $this->confirmedSummary() !== [] ? ['mail'] : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Older queued jobs already carry their channels; via() is not called again.
        return $this->confirmedSummary() !== [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Revalidate here as well as via(): already queued legacy notifications
        // can contain only a count, which is not evidence of a budget change.
        $changeSummary = $this->confirmedSummary();
        $totalChanges = array_sum(array_column($changeSummary, 'total_changes'));
        $campaignsChanged = count($changeSummary);
        $totals = [
            'healed' => 'delivery issue(s) resolved',
            'keywords_added' => 'new keyword(s) mined from real searches',
            'negatives_added' => 'wasted-spend term(s) blocked',
            'budget_adjustments' => 'budget adjustment(s)',
            'creative_adjustments' => 'new ad variation(s) generated',
        ];

        $mail = $this->brandedMail()
            ->subject("{$totalChanges} change(s) made across your campaigns today")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("During this maintenance run, our agents made **{$totalChanges} change(s)** across {$campaignsChanged} campaign(s):");

        foreach ($totals as $key => $label) {
            $count = array_sum(array_map(fn ($r) => (int) ($r[$key] ?? 0), $changeSummary));
            if ($count > 0) {
                $mail->line("- {$count} {$label}");
            }
        }

        $mail->line('By campaign:');

        foreach ($changeSummary as $campaignName => $results) {
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
            foreach ($results['budget_changes'] as $change) {
                $platform = ConfirmedBudgetChanges::PLATFORMS[$change['platform']];
                $old = ConfirmedBudgetChanges::dailyAmount($change['old_budget_micros'], $change['currency']);
                $new = ConfirmedBudgetChanges::dailyAmount($change['new_budget_micros'], $change['currency']);
                $mail->line("- {$platform} budget: **{$old} → {$new}**. Reason: ".$change['reason']);
            }
            if (! empty($results['creative_adjustments'])) {
                $mail->line('- Generated '.$results['creative_adjustments'].' new ad creative variation(s)');
            }
            if (! empty($results['strategy_graduated'])) {
                $mail->line('- Upgraded bidding strategy: '.$results['strategy_graduated']);
            }
        }

        $mail->line('The changes listed above have been applied. You can review your campaign settings in your dashboard.');

        return $mail->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'maintenance_summary',
            'campaigns_processed' => $this->campaignsProcessed,
            'changes' => $this->confirmedSummary(),
        ];
    }

    private function confirmedSummary(): array
    {
        $summary = [];
        foreach ($this->changeSummary as $campaign => $results) {
            $results['budget_changes'] = ConfirmedBudgetChanges::from($results['budget_changes'] ?? []);
            $results['budget_adjustments'] = count($results['budget_changes']);
            // Recompute the total rather than trusting an old count-only payload.
            $results['total_changes'] = $results['budget_adjustments'];
            foreach (['healed', 'keywords_added', 'negatives_added', 'creative_adjustments'] as $key) {
                $results[$key] = max(0, (int) ($results[$key] ?? 0));
                $results['total_changes'] += $results[$key];
            }
            $results['total_changes'] += empty($results['strategy_graduated']) ? 0 : 1;
            if ($results['total_changes'] > 0) {
                $summary[$campaign] = $results;
            }
        }

        return $summary;
    }
}
