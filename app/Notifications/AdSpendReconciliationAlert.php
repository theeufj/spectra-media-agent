<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts admins when a customer's platform ad spend and credit-ledger deductions
 * diverge beyond tolerance over the reconciliation window. Alert-only — a human
 * reviews and corrects; no automated ledger movement. (BILL-7)
 */
class AdSpendReconciliationAlert extends Notification
{
    use Queueable;

    /**
     * @param array<int,array{customer_id:int,customer:string,currency:string,platform_spend:float,deductions:float,discrepancy:float,relative:float}> $discrepancies
     */
    public function __construct(
        protected array $discrepancies,
        protected string $window,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[Site to Spend] Ad spend reconciliation discrepancies (' . count($this->discrepancies) . ')')
            ->error()
            ->greeting('Ad Spend Reconciliation Alert')
            ->line("The following accounts' platform spend and ledger deductions diverged over {$this->window}:");

        foreach ($this->discrepancies as $d) {
            $mail->line(sprintf(
                '• %s (#%d): platform %s%s vs deducted %s%s — off by %s%s (%.0f%%)',
                $d['customer'], $d['customer_id'],
                $d['currency'], number_format($d['platform_spend'], 2),
                $d['currency'], number_format($d['deductions'], 2),
                $d['currency'], number_format($d['discrepancy'], 2),
                $d['relative'] * 100,
            ));
        }

        return $mail
            ->line('These are flagged for manual review — no automated correction has been applied.')
            ->action('View Admin Billing', url('/admin'));
    }
}
