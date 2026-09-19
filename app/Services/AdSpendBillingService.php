<?php

namespace App\Services;

use App\Mail\AdSpendCampaignsPaused;
use App\Mail\AdSpendCampaignsResumed;
use App\Mail\AdSpendLowBalance;
use App\Mail\AdSpendPaymentFailed;
use App\Mail\AdSpendPaymentWarning;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Notification;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Customers\DeactivateCustomerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\CardException;

/**
 * AdSpendBillingService
 *
 * Handles all ad spend billing logic:
 * - Initial credit capture on campaign creation
 * - Daily billing for actual ad spend
 * - Payment failure handling with grace periods
 * - Campaign pausing/resuming based on payment status
 *
 * BILLING FLOW:
 * 1. Campaign created → Charge 7 days estimated spend upfront
 * 2. Daily at midnight → Calculate actual spend, deduct from credit
 * 3. If credit < 3 days remaining → Auto-replenish from card
 * 4. If payment fails → Enter grace period (24h)
 * 5. If still failing after grace → Reduce budget 50%
 * 6. If failing after 48h → Pause all campaigns
 * 7. Payment recovered → Auto-resume campaigns
 */
class AdSpendBillingService
{
    private ?string $spendDateOverride = null;

    public function processBillingForDate(Customer $customer, string $spendDate): array
    {
        $this->spendDateOverride = $spendDate;
        try {
            return $this->processDailyBilling($customer);
        } finally {
            $this->spendDateOverride = null;
        }
    }

    protected BudgetIntelligenceAgent $budgetAgent;

    public function __construct(?BudgetIntelligenceAgent $budgetAgent = null)
    {
        $this->budgetAgent = $budgetAgent ?? app(BudgetIntelligenceAgent::class);
    }

    /**
     * `action_taken` when the run ended in an exception rather than a declined
     * card. The daily job releases its billing claim on this value.
     *
     * processDailyBilling() catches everything and returns, so a Facebook
     * Insights timeout read as an ordinary success=false — indistinguishable
     * from the grace/pause flow, which deliberately keeps the claim. That day's
     * spend was then never deducted, and nothing re-bills a skipped day.
     */
    public const ACTION_ERROR = 'error';

    /**
     * Initialize credit account for a customer when they create their first campaign.
     *
     * $daysToCharge is what the setup modal showed the customer: a campaign
     * that only runs three days is quoted three days of runway. This used to be
     * hardcoded to 7 here while the caller read the posted value and used it
     * only for top-ups, so a first-time customer shown $150 was charged $350.
     */
    public function initializeCreditAccount(Customer $customer, float $dailyBudget, int $daysToCharge = 7): AdSpendCredit
    {
        // Serialize per-customer: without a DB unique constraint, two concurrent calls
        // (racing deploys, a double-clicked deploy button) would both see "no account",
        // both charge the card, and create duplicate credit rows. The lock makes the
        // check-charge-create sequence atomic. (BILL-3)
        $lock = Cache::lock("adspend_init:{$customer->id}", 60);

        try {
            $lock->block(20);

            // Re-read inside the lock — the relation accessor may be stale.
            $existing = $customer->adSpendCredit()->first();
            if ($existing) {
                return $existing;
            }

            // Calculate initial credit (the quoted number of days of estimated spend)
            $initialCredit = AdSpendCredit::calculateInitialCredit($dailyBudget, $daysToCharge);

            // Charge the customer's card for the initial credit
            $chargeResult = $this->chargeCustomer(
                $customer,
                $initialCredit,
                "Initial ad spend credit ({$daysToCharge} days)",
                $this->idempotencyKey('initial', $customer, 'setup', $initialCredit)
            );

            if (! $chargeResult['success']) {
                throw new \Exception('Failed to charge initial ad spend credit: '.$chargeResult['error']);
            }

            $credit = DB::transaction(function () use ($customer, $initialCredit, $daysToCharge, $chargeResult) {
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($existing = $customer->adSpendCredit()->first()) {
                    return $existing;
                }
                // Create the credit account
                $credit = AdSpendCredit::create([
                    'customer_id' => $customer->id,
                    'initial_credit_amount' => $initialCredit,
                    'current_balance' => $initialCredit,
                    'currency' => $customer->billingCurrency(),
                    'status' => AdSpendCredit::STATUS_ACTIVE,
                    'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
                    'last_successful_charge_at' => now(),
                    'stripe_payment_method_id' => $chargeResult['payment_method_id'] ?? null,
                ]);

                // Record the initial credit transaction
                $credit->transactions()->create([
                    'type' => AdSpendTransaction::TYPE_CREDIT,
                    'amount' => $initialCredit,
                    'balance_after' => $initialCredit,
                    'description' => "Initial ad spend credit ({$daysToCharge} days prepaid)",
                    'stripe_charge_id' => $chargeResult['charge_id'] ?? null,
                ]);

                return $credit;
            });

            Log::info('AdSpendBilling: Initialized credit account', [
                'customer_id' => $customer->id,
                'initial_credit' => $initialCredit,
                'days_to_charge' => $daysToCharge,
            ]);

            return $credit;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Process daily billing for a customer.
     * Called by the scheduled job each day.
     */
    public function processDailyBilling(Customer $customer): array
    {
        return Cache::lock("adspend-settlement:{$customer->id}", 600)->block(10,
            fn () => $this->settleDailyBilling($customer));
    }

    private function settleDailyBilling(Customer $customer): array
    {
        $result = [
            'customer_id' => $customer->id,
            'success' => false,
            'actual_spend' => 0,
            'action_taken' => null,
            'error' => null,
        ];

        try {
            $credit = $customer->adSpendCredit;

            if (! $credit) {
                $result['error'] = 'No credit account found';

                return $result;
            }

            // If campaigns are already paused, check if we should try to recover
            if ($credit->payment_status === AdSpendCredit::PAYMENT_PAUSED) {
                $recovery = $this->attemptPaymentRecovery($customer, $credit);
                if (! $recovery['success']) {
                    return $recovery;
                }
                $credit->refresh();
            }

            // Get actual ad spend from yesterday
            $actualSpend = $this->getActualAdSpend($customer);
            $result['actual_spend'] = $actualSpend;

            // Subtract anything already taken for that same day.
            //
            // The idempotency marker upstream is per customer per *run* date, so
            // it does not stop a day being billed twice by two different paths.
            // A reconciliation catch-up settled 18 August at 21:53, then the
            // nightly run billed 18 August again the next morning — 246.56 taken
            // for 207.70 of spend, balance to zero, account into grace period,
            // while the campaign kept running.
            $alreadyDeducted = $this->deductionsFor($credit, $this->billingDate($customer));

            if ($alreadyDeducted > 0) {
                $actualSpend = round($actualSpend - $alreadyDeducted, 2);
                $result['actual_spend'] = $actualSpend;

                Log::info('AdSpendBilling: part of this day was already deducted', [
                    'customer_id' => $customer->id,
                    'billing_date' => $this->billingDate($customer),
                    'already_deducted' => $alreadyDeducted,
                    'remaining' => $actualSpend,
                ]);
            }

            if ($actualSpend <= 0) {
                $result['success'] = true;
                $result['action_taken'] = 'No spend to bill';

                return $result;
            }

            $billingDate = $this->billingDate($customer);

            // deduct() re-reads the balance under lock and refuses rather than
            // overdraw, so its return value is the only reliable statement that
            // money moved. All three call sites here discarded it and reported
            // success regardless — so a refusal logged "Deducted from credit
            // balance" while taking nothing, and the day is never retried
            // because the run is already claimed.
            //
            // Calling deduct() directly also removes the unlocked
            // `current_balance >= $actualSpend` pre-check, which was reading a
            // stale in-memory balance to decide whether the locked write would
            // succeed.
            if ($credit->deduct($actualSpend, 'Daily ad spend - '.$billingDate, $billingDate)) {
                $result['success'] = true;
                $result['action_taken'] = 'Deducted from credit balance';

                // Check if we need to auto-replenish
                $this->checkAndReplenish($customer, $credit);
            } else {
                // Not enough credit, need to charge card. Take what is actually
                // there first, and measure the shortfall against what the
                // locked deduction accepted.
                $credit->refresh();
                $available = (float) $credit->current_balance;
                $deductedNow = 0.0;

                if ($available > 0 && $credit->deduct($available, 'Daily ad spend (partial) - '.$billingDate, $billingDate)) {
                    $deductedNow = $available;
                }

                $shortfall = round($actualSpend - $deductedNow, 2);

                // Try to charge the shortfall plus replenishment
                $replenishAmount = AdSpendCredit::calculateInitialCredit(
                    $this->getAverageDailyBudget($customer),
                    7
                );
                $totalToCharge = round($shortfall + $replenishAmount, 2);

                $chargeResult = $this->chargeCustomer(
                    $customer,
                    $totalToCharge,
                    'Ad spend replenishment',
                    $this->idempotencyKey('replenish', $customer, $billingDate, $totalToCharge)
                );

                if ($chargeResult['success']) {
                    $credit->addCredit($totalToCharge, 'Credit replenishment', $chargeResult['charge_id']);

                    if (! $credit->deduct($shortfall, 'Daily ad spend (remaining) - '.$billingDate, $billingDate)) {
                        // The credit just added should cover this. If it does
                        // not, say so rather than reporting a settled day.
                        report(new \RuntimeException(
                            "Ad spend deduction refused after replenishment for customer {$customer->id} on {$billingDate}"
                        ));

                        $result['success'] = false;
                        $result['error'] = 'Card charged but the outstanding spend could not be deducted';
                        $result['action_taken'] = 'Charged card; deduction refused';

                        return $result;
                    }

                    $credit->restoreAccount();
                    $result['success'] = true;
                    $result['action_taken'] = 'Charged card and replenished credit';
                } else {
                    // Payment failed - enter grace period or escalate
                    $this->handlePaymentFailure($customer, $credit, $chargeResult['error']);
                    $result['success'] = false;
                    $result['error'] = 'Payment failed: '.$chargeResult['error'];
                    $result['action_taken'] = 'Entered payment failure flow';
                }
            }

            return $result;

        } catch (\Throwable $e) {
            // \Throwable, not \Exception: a TypeError here used to sail past and
            // abort the whole nightly run rather than this one customer.
            report($e);
            Log::error('AdSpendBilling: Daily billing failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();

            // Tell the caller this was an exception, not a declined card. The
            // two are both success=false but want opposite handling: a decline
            // keeps the day's billing claim (the grace/pause flow owns it from
            // here), while an exception — a Facebook Insights timeout inside
            // getActualAdSpend, say — deducted nothing and must be retried, or
            // the day is silently never billed.
            $result['action_taken'] = self::ACTION_ERROR;

            return $result;
        }
    }

    /**
     * Put a billing event in front of the customer, not just in their inbox.
     *
     * Every one of these was a bare Mail::to() send, which bypasses the
     * notification system entirely and therefore can never reach the bell. A
     * customer whose campaigns were paused for a failed payment saw nothing at
     * all inside the product — the one place they would look to find out why
     * their ads had stopped.
     *
     * Deliberately best-effort: a notification that cannot be written must not
     * abort a billing run that has already moved money.
     */
    protected function notifyInApp(
        Customer $customer,
        string $type,
        string $title,
        string $message,
        string $actionUrl = '/billing/ad-spend',
        string $actionText = 'View ad spend',
    ): void {
        try {
            foreach ($customer->users as $user) {
                Notification::notify(
                    $user,
                    $type,
                    $title,
                    $message,
                    $actionUrl,
                    $actionText,
                    $customer,
                );
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error('AdSpendBilling: could not write in-app notification: '.$e->getMessage(), [
                'customer_id' => $customer->id,
                'type' => $type,
            ]);
        }
    }

    /**
     * Handle payment failure with grace period logic.
     */
    protected function handlePaymentFailure(Customer $customer, AdSpendCredit $credit, string $error): void
    {
        Log::warning('AdSpendBilling: Payment failed', [
            'customer_id' => $customer->id,
            'failed_count' => $credit->failed_charge_count + 1,
            'error' => $error,
        ]);

        $user = $customer->users()->wherePivot('role', 'owner')->first()
            ?? $customer->users()->first();

        // Determine action based on failure count
        switch ($credit->failed_charge_count) {
            case 0:
                // First failure - enter 24-hour grace period
                $credit->enterGracePeriod(24);

                if ($user) {
                    Mail::to($user->email)->send(new AdSpendPaymentWarning($customer, $credit, $error));
                }

                $this->notifyInApp(
                    $customer,
                    Notification::TYPE_BILLING_WARNING,
                    'We could not take your ad spend payment',
                    'Your card was declined. Your ads keep running for 24 hours — update your payment method to avoid interruption.',
                );
                break;

            case 1:
                // Second failure - extend grace period, reduce budget
                $credit->markPaymentFailed();
                $this->reduceCampaignBudgets($customer, 0.5); // 50% budget

                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendPaymentFailed($customer, $credit, $error));
                }

                $this->notifyInApp(
                    $customer,
                    Notification::TYPE_BILLING_WARNING,
                    'Your daily budgets have been halved',
                    'We still cannot take payment, so spending is reduced while we retry. Update your payment method to restore full budgets.',
                );
                break;

            default:
                // Third+ failure - pause all campaigns
                $credit->pauseCampaigns();
                $this->pauseAllCampaigns($customer);

                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendCampaignsPaused($customer, $credit));
                }

                $this->notifyInApp(
                    $customer,
                    Notification::TYPE_BILLING_WARNING,
                    'Your ads have been paused',
                    'Payment has failed three times, so your campaigns have stopped serving. They resume as soon as a payment goes through.',
                );
                break;
        }
    }

    /**
     * Attempt to recover payment and resume campaigns.
     */
    protected function attemptPaymentRecovery(Customer $customer, AdSpendCredit $credit): array
    {
        $result = [
            'customer_id' => $customer->id,
            'success' => false,
            'actual_spend' => 0,
            'action_taken' => null,
            'error' => null,
        ];

        // Calculate what we need to charge to get back to healthy state
        $replenishAmount = AdSpendCredit::calculateInitialCredit(
            $this->getAverageDailyBudget($customer),
            7
        );

        $chargeResult = $this->chargeCustomer(
            $customer,
            $replenishAmount,
            'Ad spend recovery',
            $this->idempotencyKey('recovery', $customer, $this->billingDate($customer), $replenishAmount)
        );

        if ($chargeResult['success']) {
            $credit->addCredit($replenishAmount, 'Credit recovery', $chargeResult['charge_id']);
            $credit->restoreAccount();

            // Resume campaigns and restore budgets to 100%. This branch is only
            // reached from the PAYMENT_PAUSED state, so the pause sweep did run.
            $this->recoverCampaigns($customer, true);

            $user = $customer->users()->wherePivot('role', 'owner')->first()
                ?? $customer->users()->first();
            if ($user) {
                Mail::to($user->email)->queue(new AdSpendCampaignsResumed($customer, $credit));
            }

            $this->notifyInApp(
                $customer,
                Notification::TYPE_BILLING_SUCCESS,
                'Your ads are running again',
                'Your payment went through and your campaigns are back to their full budgets.',
            );

            $result['success'] = true;
            $result['action_taken'] = 'Payment recovered, campaigns resumed';

            Log::info('AdSpendBilling: Payment recovered', [
                'customer_id' => $customer->id,
            ]);
        } else {
            $result['error'] = 'Recovery payment failed: '.$chargeResult['error'];
            $result['action_taken'] = 'Recovery attempt failed';
        }

        return $result;
    }

    /**
     * Check if credit needs replenishment and auto-charge.
     */
    protected function checkAndReplenish(Customer $customer, AdSpendCredit $credit): void
    {
        // Use the sum of daily budgets across active campaigns — this is the authoritative
        // number of what Google is authorised to spend per day, not a rolling average which
        // gets skewed by partial days and campaign ramp-up periods.
        $dailyBudget = $customer->campaigns()
            ->where('status', 'active')
            ->where(function ($q) {
                // whereNotIn alone is NULL-not-true, and primary_status is
                // nullable: MonitorCampaignStatus is its only writer and it
                // returns early when the platform lookup yields nothing. A
                // campaign the monitor has not reached yet dropped out of the
                // sum entirely, so an account whose campaigns are all NULL
                // summed to 0 and fell through to the rolling average the
                // comment above says must not be used.
                $q->whereNull('primary_status')
                    ->orWhereNotIn('primary_status', ['PAUSED', 'REMOVED', 'NOT_ELIGIBLE', 'ENDED']);
            })
            ->sum('daily_budget');

        // Fall back to rolling average if no budgets are set
        if ($dailyBudget <= 0) {
            $dailyBudget = $credit->getAverageDailySpend();
        }

        $daysRemaining = $dailyBudget > 0 ? $credit->current_balance / $dailyBudget : 999;

        // If less than 3 days remaining, auto-replenish
        if ($daysRemaining < 3 && $daysRemaining > 0) {
            $replenishAmount = AdSpendCredit::calculateInitialCredit($dailyBudget, 7);

            $chargeResult = $this->chargeCustomer(
                $customer,
                $replenishAmount,
                'Auto-replenishment',
                $this->idempotencyKey('autoreplenish', $customer, $this->billingDate($customer), $replenishAmount)
            );

            if ($chargeResult['success']) {
                $credit->addCredit($replenishAmount, 'Auto-replenishment', $chargeResult['charge_id']);

                Log::info('AdSpendBilling: Auto-replenished credit', [
                    'customer_id' => $customer->id,
                    'amount' => $replenishAmount,
                ]);
            } else {
                // Send low balance warning
                $user = $customer->users()->wherePivot('role', 'owner')->first()
                    ?? $customer->users()->first();
                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendLowBalance($customer, $credit, $daysRemaining));
                }

                $this->notifyInApp(
                    $customer,
                    Notification::TYPE_BILLING_WARNING,
                    'Your ad spend credit is running low',
                    'About '.$daysRemaining.' days of spend left. We top up automatically, but a declined card would pause your ads.',
                );
            }
        }
    }

    /**
     * A stable key for one intended charge.
     *
     * Stripe replays the original response for a repeated key rather than
     * charging again, so this must be derived only from what identifies the
     * charge — never from the clock or a random value.
     */
    protected function idempotencyKey(string $purpose, Customer $customer, string $scope, float $amount): string
    {
        return sprintf('adspend:%s:%d:%s:%d', $purpose, $customer->id, $scope, (int) round($amount * 100));
    }

    /**
     * Charge the customer's card via Stripe.
     */
    protected function chargeCustomer(
        Customer $customer,
        float $amount,
        string $description,
        string $idempotencyKey,
    ): array {
        try {
            // Prefer an owner who can actually pay, then anyone who can.
            //
            // This used to take the first owner and only fall back if there was
            // no owner at all — so an owner without a card blocked the charge
            // while a teammate's card sat unused. sitetospend has two owners;
            // the one listed first has no payment method and the other has an
            // Amex, so every charge failed with "No payment method on file"
            // against an account that plainly had one.
            $users = $customer->users()->get();

            $user = $users->first(fn ($u) => ($u->pivot->role ?? null) === 'owner' && $u->hasDefaultPaymentMethod())
                ?? $users->first(fn ($u) => $u->hasDefaultPaymentMethod());

            if (! $user || ! $user->hasDefaultPaymentMethod()) {
                return [
                    'success' => false,
                    'error' => 'No payment method on file for this account',
                ];
            }

            // Amount in cents for Stripe
            $amountCents = (int) round($amount * 100);

            // Charge in the customer's own currency (their ad-account currency) so we
            // never bill USD for AUD spend. Ad spend is deducted 1:1 in this currency.
            //
            // Built directly rather than through Cashier's charge(): an
            // idempotency key is a request option, and charge() -> createPayment()
            // calls paymentIntents->create($params) with no second argument, so
            // there is no way to pass one through it. Without the key, a lost
            // response or a retry bills the card again for the full amount.
            $params = [
                'amount' => $amountCents,
                'currency' => strtolower($customer->billingCurrency()),
                'payment_method' => $user->defaultPaymentMethod()->id,
                'confirmation_method' => 'automatic',
                'confirm' => true,
                'description' => $description,
                'metadata' => [
                    'customer_id' => (string) $customer->id,
                    'type' => 'ad_spend_credit',
                ],
                'payment_method_types' => ['card'],
            ];

            if ($user->hasStripeId()) {
                $params['customer'] = $user->stripe_id;
            }

            $intent = app(\App\Services\Billing\AdSpendPaymentGateway::class)->collect($customer, $params, $idempotencyKey);
            $payment = new \Laravel\Cashier\Payment($intent);

            // charge() did this for us; keep it so IncompletePayment still
            // surfaces requires_action the way the callers below expect.
            $payment->validate();
            if ($intent->status !== 'succeeded' || $intent->amount !== $amountCents || strtolower($intent->currency) !== strtolower($customer->billingCurrency())) {
                throw new \RuntimeException('Payment is not settled for the expected amount and currency.');
            }

            return [
                'success' => true,
                'charge_id' => $payment->id,
                'payment_method_id' => $user->defaultPaymentMethod()->id,
            ];

        } catch (CardException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (IncompletePayment $e) {
            return [
                'success' => false,
                'error' => 'Payment requires additional action',
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get actual ad spend from yesterday's performance data.
     * Uses campaign platform IDs directly — not strategy deployment status, which can be
     * missing for campaigns deployed outside the strategy wizard or still in 'deploying' state.
     */
    /**
     * The day being billed, in the advertising account's own timezone.
     *
     * Ad platforms report by their account's timezone; this application runs in
     * UTC. For an Australian account that is ten hours apart, so now()->subDay()
     * asks for 17 August while the account has already closed off the 18th —
     * billing sat permanently a day behind, and every reconciliation compared
     * mismatched windows and reported a divergence that was really an offset.
     *
     * Falls back to the app timezone when a customer has none recorded, which
     * is the previous behaviour rather than a guess.
     */
    /**
     * What has already been taken for a given billing day.
     *
     * Matched on the day the entry bills for, which its description records —
     * not on when the row was written, since a catch-up posted today may settle
     * a day from last week.
     */
    protected function deductionsFor(AdSpendCredit $credit, string $billingDate): float
    {
        return abs((float) $credit->transactions()
            ->where('type', AdSpendTransaction::TYPE_DEDUCTION)
            ->where(function ($query) use ($billingDate) {
                // billed_for is the real answer. The description match is kept
                // only for rows written before that column existed and whose
                // backfill did not resolve — a money guard should not depend on
                // a human-readable string, but nor should it lose old rows.
                $query->whereDate('billed_for', $billingDate)
                    ->orWhere(function ($legacy) use ($billingDate) {
                        $legacy->whereNull('billed_for')
                            ->where('description', 'like', '%'.$billingDate);
                    });
            })
            ->sum('amount'));
    }

    protected function billingDate(Customer $customer): string
    {
        if ($this->spendDateOverride !== null) {
            return $this->spendDateOverride;
        }
        $timezone = $customer->timezone ?: config('app.timezone');

        try {
            return now()->setTimezone($timezone)->subDay()->toDateString();
        } catch (\Throwable $e) {
            // An invalid timezone string must not stop billing entirely.
            report($e);

            return now()->subDay()->toDateString();
        }
    }

    protected function getActualAdSpend(Customer $customer): float
    {
        $totalSpend = 0;

        try {
            // Bill on ACTUAL recorded spend, independent of the local `status` field.
            // `status` is a lifecycle flag set only by deploy/pause code paths and is
            // never reconciled against the platform, so a live campaign can sit as
            // DRAFT/paused locally (e.g. created by a remediation agent, or a deploy that
            // reached the platform but never flipped status) while it spends real money.
            // Keying billing off `status` silently under-bills — the leak behind the
            // ReconcileAdSpend divergence. Any campaign with a platform ID is a billing
            // candidate; getXAdsSpend returns 0 for days with no recorded spend. (BILL-8)
            $billableCampaigns = $customer->campaigns()
                ->where(function ($q) {
                    $q->whereNotNull('google_ads_campaign_id')
                        ->orWhereNotNull('facebook_ads_campaign_id')
                        ->orWhereNotNull('microsoft_ads_campaign_id')
                        ->orWhereNotNull('linkedin_campaign_id');
                })
                ->get();

            foreach ($billableCampaigns as $campaign) {
                if (! empty($campaign->google_ads_campaign_id)) {
                    $totalSpend += $this->getGoogleAdsSpend($customer, $campaign);
                }
                if (! empty($campaign->facebook_ads_campaign_id)) {
                    $totalSpend += $this->getFacebookAdsSpend($customer, $campaign);
                }
                if (! empty($campaign->microsoft_ads_campaign_id)) {
                    $totalSpend += $this->getMicrosoftAdsSpend($customer, $campaign);
                }
                if (! empty($campaign->linkedin_campaign_id)) {
                    $totalSpend += $this->getLinkedInAdsSpend($customer, $campaign);
                }
            }

        } catch (\Throwable $e) {
            // Returning the partial total billed the customer for however many
            // campaigns happened to be read before the failure, and presented it
            // as the whole day's spend.
            report($e);
            Log::error('AdSpendBilling: Failed to get actual spend', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $totalSpend;
    }

    /**
     * Get spend from Google Ads for yesterday.
     * Reads from the local GoogleAdsPerformanceData table which is synced by the agents —
     * avoids a redundant live API call and uses the same data source as the rest of the app.
     */
    protected function getGoogleAdsSpend(Customer $customer, Campaign $campaign): float
    {
        try {
            return (float) \App\Models\GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $this->billingDate($customer))
                ->sum('cost');
        } catch (\Throwable $e) {
            // Never resolve a failed lookup to zero. A zero here is
            // indistinguishable from a genuine no-spend day, so the customer
            // was under-billed, the run was marked settled, and Log::warning
            // does not reach runtime_exceptions — the failure was invisible.
            report($e);
            Log::error('AdSpendBilling: Failed to get Google Ads spend', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get spend from Facebook Ads for yesterday.
     */
    protected function getFacebookAdsSpend(Customer $customer, Campaign $campaign): float
    {
        // Check if customer has Facebook access token
        if (empty($customer->facebook_ads_account_id)) {
            return 0;
        }

        // Check if campaign has Facebook campaign ID
        if (empty($campaign->facebook_ads_campaign_id)) {
            return 0;
        }

        try {
            $insightService = new \App\Services\FacebookAds\InsightService($customer);

            // Get yesterday's spend
            $yesterday = $this->billingDate($customer);

            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                $yesterday,
                $yesterday,
                ['spend']
            );

            if (! empty($insights) && isset($insights[0]['spend'])) {
                $spend = (float) $insights[0]['spend'];

                Log::info('AdSpendBilling: Retrieved Facebook Ads spend', [
                    'campaign_id' => $campaign->id,
                    'facebook_campaign_id' => $campaign->facebook_ads_campaign_id,
                    'date' => $yesterday,
                    'spend' => $spend,
                ]);

                return $spend;
            }

            return 0;
        } catch (\Throwable $e) {
            // Never resolve a failed lookup to zero. A zero here is
            // indistinguishable from a genuine no-spend day, so the customer
            // was under-billed, the run was marked settled, and Log::warning
            // does not reach runtime_exceptions — the failure was invisible.
            report($e);
            Log::error('AdSpendBilling: Failed to get Facebook Ads spend', [
                'campaign_id' => $campaign->id,
                'facebook_campaign_id' => $campaign->facebook_ads_campaign_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get spend from Microsoft Ads for yesterday.
     */
    protected function getMicrosoftAdsSpend(Customer $customer, Campaign $campaign): float
    {
        if (empty($campaign->microsoft_ads_campaign_id)) {
            return 0;
        }

        try {
            $yesterday = $this->billingDate($customer);
            $spend = \App\Models\MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $yesterday)
                ->sum('cost');

            return (float) $spend;
        } catch (\Throwable $e) {
            // Never resolve a failed lookup to zero. A zero here is
            // indistinguishable from a genuine no-spend day, so the customer
            // was under-billed, the run was marked settled, and Log::warning
            // does not reach runtime_exceptions — the failure was invisible.
            report($e);
            Log::error('AdSpendBilling: Failed to get Microsoft Ads spend', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get spend from LinkedIn Ads for yesterday.
     */
    protected function getLinkedInAdsSpend(Customer $customer, Campaign $campaign): float
    {
        if (empty($campaign->linkedin_campaign_id)) {
            return 0;
        }

        try {
            $yesterday = $this->billingDate($customer);
            $spend = \App\Models\LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $yesterday)
                ->sum('cost');

            return (float) $spend;
        } catch (\Throwable $e) {
            // Never resolve a failed lookup to zero. A zero here is
            // indistinguishable from a genuine no-spend day, so the customer
            // was under-billed, the run was marked settled, and Log::warning
            // does not reach runtime_exceptions — the failure was invisible.
            report($e);
            Log::error('AdSpendBilling: Failed to get LinkedIn Ads spend', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get total daily budget across all customer campaigns.
     */
    protected function getAverageDailyBudget(Customer $customer): float
    {
        return $customer->campaigns()
            ->where('status', 'active')
            ->sum('daily_budget') ?: 50;
    }

    /**
     * Reduce budgets for all active campaigns.
     */
    protected function reduceCampaignBudgets(Customer $customer, float $multiplier): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                if (! $campaign->google_ads_campaign_id && ! $campaign->facebook_ads_campaign_id
                    && ! $campaign->microsoft_ads_campaign_id && ! $campaign->linkedin_campaign_id) {
                    continue;
                }
                $campaign->update(['billing_budget_multiplier' => max(0, min(1, $multiplier))]);
                $newBudget = round(($campaign->daily_budget ?? 0) * $multiplier, 2);
                if (! $this->budgetAgent->updateCampaignBudgetPublic($customer, $campaign, $newBudget)) {
                    throw new \RuntimeException('Platform rejected the billing budget limit.');
                }
            } catch (\Throwable $e) {
                report($e);
                Log::error('AdSpendBilling: Failed to reduce budget', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
                if ($multiplier < 1) {
                    try {
                        $paused = app(DeactivateCustomerService::class)->pauseCampaign($customer, $campaign);
                        if (is_string($paused)) {
                            report(new \RuntimeException($paused));
                        }
                    } catch (\Throwable $pauseError) {
                        report($pauseError);
                    }
                }
            }
        }
    }

    /**
     * Pause all active campaigns for a customer.
     *
     * The platform calls live in DeactivateCustomerService — the same sweep the
     * "stop this customer spending" path uses. This method had its own copy of
     * them, and it called UpdateCampaignStatus::pause() with one of the two
     * arguments that method requires. The resulting ArgumentCountError is an
     * \Error thrown by the first statement inside the try, so the catch below
     * swallowed it before Facebook, Microsoft or LinkedIn were touched and
     * before the campaign was marked paused locally: on the third consecutive
     * declined charge, every campaign with a Google id kept serving.
     */
    protected function pauseAllCampaigns(Customer $customer): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->get();

        $deactivator = app(DeactivateCustomerService::class);

        foreach ($campaigns as $campaign) {
            try {
                $result = $deactivator->pauseCampaign($customer, $campaign);

                if (is_string($result)) {
                    // A platform refused. Report it — the campaign is still
                    // spending against a card that has declined three times,
                    // and Log::error alone never reaches the exception dashboard.
                    report(new \RuntimeException(
                        "Ad spend pause refused for campaign {$campaign->id}: {$result}"
                    ));
                    Log::error('AdSpendBilling: Failed to pause campaign', [
                        'campaign_id' => $campaign->id,
                        'error' => $result,
                    ]);

                    continue;
                }

                if ($result === true) {
                    Log::info('AdSpendBilling: Paused campaign', [
                        'campaign_id' => $campaign->id,
                        'reason' => 'payment_failure',
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
                Log::error('AdSpendBilling: Failed to pause campaign', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resume the campaigns the payment-failure pause turned off.
     *
     * The filter here used to be `paused_reason = 'Payment failure'`, a column
     * no migration has ever created: the read threw SQLSTATE 42703 *after*
     * restoreAccount() had run, so a recovered account read as healthy while
     * every campaign stayed paused and every budget stayed halved.
     *
     * There is no per-campaign reason column, so the set is inferred, and it is
     * deliberately narrow: paused locally AND paused on the platform, which is
     * exactly the pair applyPlatformStatus('PAUSED') writes. CheckCampaign-
     * PolicyViolations only writes the local `status`, so a campaign parked for
     * a disapproved ad is not swept back on by a credit top-up.
     */
    protected function resumeAllCampaigns(Customer $customer): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'paused')
            ->where('platform_status', 'PAUSED')
            ->where(function ($q) {
                // A platform id, not withDeployedPlatforms(): that scope also
                // matches on a deployed strategy, and a campaign with no id
                // anywhere is one the pause sweep never touched.
                $q->whereNotNull('google_ads_campaign_id')
                    ->orWhereNotNull('facebook_ads_campaign_id')
                    ->orWhereNotNull('microsoft_ads_campaign_id')
                    ->orWhereNotNull('linkedin_campaign_id');
            })
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                // Resume Google Ads campaign
                if (! empty($campaign->google_ads_campaign_id) && ! empty($customer->google_ads_customer_id)) {
                    $result = (new \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus($customer))
                        ->enable($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());

                    // enable() reports failure by return value, so a refusal is
                    // silent. Don't mark the campaign active off the back of one.
                    if (! ($result['success'] ?? false)) {
                        report(new \RuntimeException(
                            "Ad spend resume refused for campaign {$campaign->id}: ".($result['error'] ?? 'unknown')
                        ));
                        Log::error('AdSpendBilling: Failed to resume campaign', [
                            'campaign_id' => $campaign->id,
                            'error' => $result['error'] ?? 'unknown',
                        ]);

                        continue;
                    }
                }

                // Resume Facebook Ads campaign
                if (! empty($campaign->facebook_ads_campaign_id)) {
                    $this->resumeFacebookCampaign($customer, $campaign->facebook_ads_campaign_id);
                }

                // Resume Microsoft Ads campaign
                if (! empty($campaign->microsoft_ads_campaign_id) && ! empty($customer->microsoft_ads_account_id)) {
                    try {
                        $msService = new \App\Services\MicrosoftAds\CampaignService($customer);
                        $msService->updateStatus($campaign->microsoft_ads_campaign_id, 'Active');
                    } catch (\Throwable $e) {
                        Log::warning('AdSpendBilling: Failed to resume Microsoft campaign', ['error' => $e->getMessage()]);
                    }
                }

                // Resume LinkedIn Ads campaign
                if (! empty($campaign->linkedin_campaign_id) && ! empty($customer->linkedin_ads_account_id)) {
                    try {
                        $liService = new \App\Services\LinkedInAds\CampaignService($customer);
                        $liService->updateStatus($campaign->linkedin_campaign_id, 'ACTIVE');
                    } catch (\Throwable $e) {
                        report($e);
                        Log::warning('AdSpendBilling: Failed to resume LinkedIn campaign', ['error' => $e->getMessage()]);
                    }
                }

                // Both columns together — billing and every serving check read
                // one or the other, so writing only `status` leaves the campaign
                // half-resumed.
                $campaign->applyPlatformStatus('ENABLED');

                Log::info('AdSpendBilling: Resumed campaign', [
                    'campaign_id' => $campaign->id,
                    'facebook_ads_id' => $campaign->facebook_ads_campaign_id,
                ]);

            } catch (\Throwable $e) {
                report($e);
                Log::error('AdSpendBilling: Failed to resume campaign', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resume payment-paused campaigns and restore their budgets to 100%.
     *
     * The single entry point used by the daily recovery job AND the user-facing
     * retry / update-payment-method paths, so a successful recovery charge
     * always actually brings the campaigns back — not just the ledger flags.
     * The 1.0 budget multiplier re-pushes the stored daily_budget and is a
     * no-op when nothing was reduced, so it always runs.
     *
     * $campaignsWerePaused says whether the pause sweep had actually run for
     * this account. It has to come from the caller: restoreAccount() clears
     * campaigns_paused_at and every path here charges (and so restores) first,
     * after which the credit row can no longer answer the question. A top-up on
     * an account that only ever reached grace period must restore budgets
     * without un-pausing campaigns nobody's billing paused.
     */
    public function recoverCampaigns(Customer $customer, bool $campaignsWerePaused = true): void
    {
        if ($campaignsWerePaused) {
            $this->resumeAllCampaigns($customer);
        }

        $this->reduceCampaignBudgets($customer, 1.0);
    }

    /**
     * Resume a Facebook Ads campaign.
     *
     * (Pausing one now goes through DeactivateCustomerService::pauseCampaign,
     * which is the only remaining copy of the per-platform pause calls.)
     */
    protected function resumeFacebookCampaign(Customer $customer, string $campaignId): void
    {
        if (empty($customer->facebook_ads_account_id)) {
            return;
        }

        $campaignService = new \App\Services\FacebookAds\CampaignService($customer);
        $campaignService->updateCampaign($campaignId, ['status' => 'ACTIVE']);
    }

    /**
     * Add credit to a customer's account (manual top-up).
     */
    public function addCredit(Customer $customer, float $amount, ?string $description = null): array
    {
        $credit = $customer->adSpendCredit;

        if (! $credit) {
            return [
                'success' => false,
                'error' => 'No credit account found',
            ];
        }

        // Scoped to the minute, not the day: an admin may legitimately top the
        // same account up twice for the same amount, and a day-wide key would
        // make Stripe silently replay the first charge instead of taking the
        // second. A minute is wide enough to absorb a double-submit or an
        // in-request retry, narrow enough not to swallow a deliberate repeat.
        $chargeResult = $this->chargeCustomer(
            $customer,
            $amount,
            $description ?? 'Manual credit top-up',
            $this->idempotencyKey('manual', $customer, now()->format('Y-m-d\TH:i'), $amount)
        );

        if ($chargeResult['success']) {
            $credit->addCredit($amount, $description ?? 'Manual credit top-up', $chargeResult['charge_id']);

            // A successful charge is the answer to whatever the failure state was
            // recording. Without this a manual top-up left the account in grace
            // period, still counting up the failure ladder towards halved budgets
            // and paused campaigns — with the money already taken.
            $credit->restoreAccount();

            return [
                'success' => true,
                'new_balance' => $credit->fresh()->current_balance,
                'charge_id' => $chargeResult['charge_id'],
            ];
        }

        return $chargeResult;
    }
}
