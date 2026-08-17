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
     * @param  array<int,array{customer_id:int,customer:string,currency:string,platform_spend:float,deductions:float,discrepancy:float,relative:float}>  $discrepancies
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
        // Name the money in the subject line. The previous wording — "reconciliation
        // discrepancies" — described the mechanism rather than the consequence, and a
        // 100% billing miss arrived reading like a routine notice. It sat unactioned for
        // a week.
        $total = array_sum(array_column($this->discrepancies, 'discrepancy'));
        $currency = $this->discrepancies[0]['currency'] ?? '';

        $mail = (new MailMessage)
            ->subject(sprintf(
                '[Site to Spend] ACTION NEEDED: %s%s of ad spend was not billed',
                $currency,
                number_format($total, 2)
            ))
            ->error()
            ->greeting('Ad spend was not billed')
            ->line(sprintf(
                'Between %s, these accounts spent money on the ad platforms that was never deducted from their credit ledger. That is revenue not collected.',
                $this->window
            ));

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
            ->line('**Nothing has been corrected automatically.** Until someone deducts or writes off these amounts, the ledger does not reflect what was actually spent.')
            ->line('If a customer appears here repeatedly, the daily billing run is not selecting them — check that their campaigns are recorded as active locally, not only on the platform.')
            ->action('Review in Admin Billing', url('/admin'));
    }
}
