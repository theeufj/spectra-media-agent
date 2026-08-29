<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Assesses the early-exit terms when a bring-your-own-account customer
 * leaves inside the minimum period — by cancelling their subscription or by
 * revoking our manager access after we've built their campaigns.
 *
 * The fee is the one-time setup price less subscription already paid,
 * floored at zero. Deliberately NOT auto-charged: the assessment is
 * recorded (activity log + customer flag) and the admin gets the number
 * and the trigger. Collection is a human decision backed by the ToS.
 */
class EarlyExitFeeService
{
    /**
     * The early-exit terms only cover the drift case: a managed engagement
     * whose build lives in an account the customer owns, with real work
     * deployed, still inside the minimum period.
     */
    public function applies(Customer $customer): bool
    {
        if ($customer->service_type !== 'managed' || $customer->early_exit_assessed_at !== null) {
            return false;
        }

        // Only accounts THEY own. A customer on one of our sub-accounts
        // walks away from the build, not with it.
        if (! in_array($customer->google_ads_link_status, ['active', 'revoked'], true)) {
            return false;
        }

        if (! $customer->campaigns()->whereNotNull('google_ads_campaign_id')->exists()) {
            return false;
        }

        $months = (int) config('billing.early_exit.minimum_months', 3);

        return $customer->created_at->gt(now()->subMonths($months));
    }

    public function assess(Customer $customer, string $trigger): void
    {
        if (! $this->applies($customer)) {
            return;
        }

        $setupFeeCents = (int) config('services.stripe.setup_fee_usd_cents', 99900);
        $paidCents = $this->subscriptionPaidCents($customer);
        $feeCents = max(0, $setupFeeCents - $paidCents);

        $customer->forceFill(['early_exit_assessed_at' => now()])->save();

        ActivityLogger::customer('early_exit_assessed', $customer);
        Log::info('EarlyExitFeeService: assessment recorded', [
            'customer_id' => $customer->id,
            'trigger' => $trigger,
            'fee_cents' => $feeCents,
            'paid_cents' => $paidCents,
        ]);

        Mail::raw(
            "Early-exit assessment\n\n"
            ."Customer: {$customer->name} (#{$customer->id})\n"
            ."Trigger: {$trigger}\n"
            .'Setup fee: US$'.number_format($setupFeeCents / 100, 2)."\n"
            .'Subscription paid to date: US$'.number_format($paidCents / 100, 2)."\n"
            .'Assessed early-exit fee: US$'.number_format($feeCents / 100, 2)."\n\n"
            .'Per the ToS early-exit clause (build in a customer-owned account, inside the '
            .config('billing.early_exit.minimum_months', 3)."-month minimum).\n"
            .'Nothing has been charged — collect via Stripe if you decide to enforce.',
            fn ($m) => $m->to(config('app.admin_email'))->subject("Early-exit assessment: {$customer->name} — US$".number_format($feeCents / 100, 0))
        );
    }

    /**
     * Best-effort sum of what the customer's users have actually paid us,
     * from Stripe invoices. Stripe being unreachable must not kill the
     * assessment — it just makes the computed fee conservative (higher),
     * and the admin email carries the inputs for a human to correct.
     */
    private function subscriptionPaidCents(Customer $customer): int
    {
        $total = 0;

        foreach ($customer->users as $user) {
            try {
                if (! $user->hasStripeId()) {
                    continue;
                }
                foreach ($user->invoices() as $invoice) {
                    $total += (int) $invoice->rawTotal();
                }
            } catch (\Throwable $e) {
                Log::warning('EarlyExitFeeService: could not sum invoices', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }
}
