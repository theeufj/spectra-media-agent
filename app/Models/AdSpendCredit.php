<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AdSpendCredit
 *
 * Tracks prepaid ad spend credits for customers.
 * When a campaign is created, we capture 7 days of estimated ad spend upfront.
 * Daily billing deducts from this credit first, then charges the card.
 *
 * Risk Management:
 * - Customers prepay estimated ad spend
 * - Daily billing ensures we're never more than 24 hours behind
 * - Failed payments trigger grace period → budget reduction → pause
 */
class AdSpendCredit extends Model
{
    use BelongsToCustomer;
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'initial_credit_amount',
        'current_balance',
        'currency',
        'status',
        'payment_status',
        'last_successful_charge_at',
        'failed_charge_count',
        'grace_period_ends_at',
        'campaigns_paused_at',
        'stripe_payment_method_id',
        'notes',
    ];

    protected $casts = [
        'initial_credit_amount' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'last_successful_charge_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'campaigns_paused_at' => 'datetime',
        'failed_charge_count' => 'integer',
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';

    const STATUS_LOW_BALANCE = 'low_balance';

    const STATUS_DEPLETED = 'depleted';

    const STATUS_SUSPENDED = 'suspended';

    // Payment status constants
    const PAYMENT_CURRENT = 'current';

    const PAYMENT_GRACE_PERIOD = 'grace_period';

    const PAYMENT_FAILED = 'failed';

    const PAYMENT_PAUSED = 'paused';

    /**
     * Every platform performance table ad spend is billed from, by display label.
     *
     * One list, because the copies disagreed. AdSpendBillingService::getActualAdSpend()
     * and ReconcileAdSpend::platformSpend() both cover all four platforms, while
     * the admin reconciliation tool summed only Google and Facebook against an
     * account-wide debit total — so a Microsoft or LinkedIn customer's
     * "unreconciled" figure went negative and the tool reported "already
     * reconciled" while genuinely unbilled Google spend sat there.
     *
     * @var array<string, class-string<Model>>
     */
    const PLATFORM_SPEND_MODELS = [
        'Google Ads' => GoogleAdsPerformanceData::class,
        'Facebook Ads' => FacebookAdsPerformanceData::class,
        'Microsoft Ads' => MicrosoftAdsPerformanceData::class,
        'LinkedIn Ads' => LinkedInAdsPerformanceData::class,
    ];

    /**
     * The customer this credit belongs to.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Billing transactions for this credit account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(AdSpendTransaction::class);
    }

    /**
     * Check if account is in good standing.
     */
    public function isInGoodStanding(): bool
    {
        return in_array($this->payment_status, [
            self::PAYMENT_CURRENT,
            self::PAYMENT_GRACE_PERIOD,
        ]) && $this->status !== self::STATUS_SUSPENDED;
    }

    /**
     * Check if campaigns should be running.
     */
    public function canRunCampaigns(): bool
    {
        return $this->isInGoodStanding() &&
               $this->current_balance > 0 &&
               $this->status !== self::STATUS_SUSPENDED;
    }

    /**
     * Check if account is in grace period.
     */
    public function isInGracePeriod(): bool
    {
        return $this->payment_status === self::PAYMENT_GRACE_PERIOD &&
               $this->grace_period_ends_at &&
               now()->lt($this->grace_period_ends_at);
    }

    /**
     * Get recommended daily budget based on credit balance.
     * Returns a multiplier (1.0 = full budget, 0.5 = half budget, etc.)
     */
    public function getBudgetMultiplier(): float
    {
        // Hard stop first — a paused/suspended account must get 0 budget regardless
        // of its balance status (otherwise a paused+low_balance account fell through
        // to the 0.75 branch below and kept spending).
        if ($this->payment_status === self::PAYMENT_PAUSED ||
            $this->status === self::STATUS_SUSPENDED) {
            return 0.0;
        }

        // If in grace period, reduce budget to 50%
        if ($this->isInGracePeriod()) {
            return 0.5;
        }

        // If payment failed but not yet paused, reduce to 25%
        if ($this->payment_status === self::PAYMENT_FAILED) {
            return 0.25;
        }

        // If balance is low (less than 3 days of typical spend), reduce to 75%
        if ($this->status === self::STATUS_LOW_BALANCE) {
            return 0.75;
        }

        return 1.0;
    }

    /**
     * Deduct an amount from the credit balance.
     */
    public function deduct(float $amount, ?string $description = null, ?string $billedFor = null): bool
    {
        // Lock the row for the read-modify-write so concurrent billing runs and
        // top-ups can't lose updates or overdraw the balance.
        return DB::transaction(function () use ($amount, $description, $billedFor) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || $amount > $locked->current_balance) {
                return false;
            }

            $locked->current_balance -= $amount;
            $locked->updateBalanceStatus();
            $locked->save();

            $locked->transactions()->create([
                'type' => AdSpendTransaction::TYPE_DEDUCTION,
                'amount' => -$amount,
                'billed_for' => $billedFor,
                'balance_after' => $locked->current_balance,
                'description' => $description ?? 'Daily ad spend charge',
            ]);

            // Reflect the committed state on the in-memory instance the caller holds.
            $this->current_balance = $locked->current_balance;
            $this->status = $locked->status;

            return true;
        });
    }

    /**
     * Add credit to the account.
     *
     * Keyed on the Stripe charge whenever there is one. Stripe replays the
     * original response for a repeated idempotency key rather than charging
     * again, so a retried top-up comes back as a *success* carrying the same
     * charge id — and this method used to write a second ledger row and a
     * second balance increase for money that was only collected once. A $100
     * top-up retried inside the minute became $200 of balance against $100
     * taken, at Spectra's expense, and chargeCustomer() cannot tell the two
     * apart because Stripe does not flag the replay.
     *
     * firstOrCreate under the row lock, plus the partial unique index on
     * (stripe_charge_id) for credit rows, is the guard: whoever writes the
     * ledger row moves the balance, and the replay finds it already there.
     */
    public function addCredit(float $amount, ?string $description = null, ?string $stripeChargeId = null): void
    {
        DB::transaction(function () use ($amount, $description, $stripeChargeId) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first() ?? $this;

            $newBalance = round((float) $locked->current_balance + $amount, 2);

            $entry = [
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description ?? 'Credit added',
            ];

            if ($stripeChargeId) {
                $transaction = $locked->transactions()->firstOrCreate(
                    ['stripe_charge_id' => $stripeChargeId, 'type' => AdSpendTransaction::TYPE_CREDIT],
                    $entry
                );

                if (! $transaction->wasRecentlyCreated) {
                    // Already credited. Leave the balance alone — the money was
                    // only collected once.
                    Log::warning('AdSpendCredit: replayed Stripe charge not credited again', [
                        'ad_spend_credit_id' => $locked->getKey(),
                        'stripe_charge_id' => $stripeChargeId,
                        'amount' => $amount,
                    ]);

                    return;
                }
            } else {
                $locked->transactions()->create($entry + [
                    'type' => AdSpendTransaction::TYPE_CREDIT,
                    'stripe_charge_id' => null,
                ]);
            }

            $locked->current_balance = $newBalance;
            $locked->updateBalanceStatus();
            $locked->save();

            $this->current_balance = $locked->current_balance;
            $this->status = $locked->status;
        });
    }

    /**
     * Apply a manual adjustment, positive or negative.
     *
     * Same discipline as deduct()/addCredit(): locked read-modify-write with
     * the ledger row inside the transaction. The admin reconciliation path used
     * to do this by hand with three unsynchronised statements, and lost any
     * concurrent nightly deduction.
     *
     * A negative adjustment is capped at the available balance and the ledger
     * records what was actually applied, not what was asked for — so the totals
     * stay internally consistent and any remainder is still visible as
     * unreconciled rather than being silently written off.
     *
     * Returns the amount actually applied (signed).
     *
     * $type lets a refund book itself as TYPE_REFUND while still getting the
     * lock, the cap and the balance-status recalculation. A refund that empties
     * an account has to leave it marked depleted, which a hand-rolled balance
     * write does not do.
     */
    public function recordAdjustment(
        float $amount,
        string $description,
        string $type = AdSpendTransaction::TYPE_ADJUSTMENT,
        ?string $stripeChargeId = null,
    ): float {
        return DB::transaction(function () use ($amount, $description, $type, $stripeChargeId) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first() ?? $this;

            $before = (float) $locked->current_balance;
            $applied = $amount < 0
                ? -min(abs($amount), $before)
                : $amount;
            $applied = round($applied, 2);

            $locked->current_balance = round($before + $applied, 2);
            $locked->updateBalanceStatus();
            $locked->save();

            $locked->transactions()->create([
                'type' => $type,
                'amount' => $applied,
                'balance_after' => $locked->current_balance,
                'description' => $description,
                'stripe_charge_id' => $stripeChargeId,
            ]);

            $this->current_balance = $locked->current_balance;
            $this->status = $locked->status;

            return $applied;
        });
    }

    /**
     * Update status based on current balance.
     */
    protected function updateBalanceStatus(): void
    {
        // Get average daily spend to determine "low balance" threshold
        $avgDailySpend = $this->getAverageDailySpend();
        $daysRemaining = $avgDailySpend > 0 ? $this->current_balance / $avgDailySpend : 999;

        if ($this->current_balance <= 0) {
            $this->status = self::STATUS_DEPLETED;
        } elseif ($daysRemaining < 3) {
            $this->status = self::STATUS_LOW_BALANCE;
        } else {
            $this->status = self::STATUS_ACTIVE;
        }
    }

    /**
     * Get average daily spend from recent transactions.
     */
    public function getAverageDailySpend(): float
    {
        $recentDeductions = $this->transactions()
            ->where('type', 'deduction')
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('amount');

        $avgFromTransactions = abs($recentDeductions) / 7;

        // If no deduction history yet (e.g. billing job was recently fixed), fall back to
        // actual Google Ads performance data so the topup logic has a realistic baseline.
        if ($avgFromTransactions < 1.0) {
            $actualSpend = GoogleAdsPerformanceData::whereHas('campaign', function ($q) {
                $q->where('customer_id', $this->customer_id);
            })
                ->where('date', '>=', now()->subDays(7)->toDateString())
                ->where('date', '<', now()->toDateString())
                ->sum('cost');

            return $actualSpend > 0 ? $actualSpend / 7 : 0.0;
        }

        return $avgFromTransactions;
    }

    /**
     * Enter grace period after payment failure.
     */
    public function enterGracePeriod(int $hoursGrace = 24): void
    {
        $this->payment_status = self::PAYMENT_GRACE_PERIOD;
        $this->grace_period_ends_at = now()->addHours($hoursGrace);
        $this->failed_charge_count++;
        $this->save();
    }

    /**
     * Mark payment as failed (after grace period).
     */
    public function markPaymentFailed(): void
    {
        $this->payment_status = self::PAYMENT_FAILED;
        $this->failed_charge_count++;
        $this->save();
    }

    /**
     * Pause campaigns due to payment failure.
     */
    public function pauseCampaigns(): void
    {
        $this->payment_status = self::PAYMENT_PAUSED;
        $this->campaigns_paused_at = now();
        $this->save();
    }

    /**
     * Restore account after successful payment.
     */
    public function restoreAccount(): void
    {
        $this->payment_status = self::PAYMENT_CURRENT;
        $this->failed_charge_count = 0;
        $this->grace_period_ends_at = null;
        $this->campaigns_paused_at = null;
        $this->last_successful_charge_at = now();
        $this->save();
    }

    /**
     * Calculate required initial credit for campaign.
     *
     * @param  float  $dailyBudget  The daily ad spend budget
     * @param  int  $days  Number of days to prepay (default 7)
     */
    public static function calculateInitialCredit(float $dailyBudget, int $days = 7): float
    {
        return $dailyBudget * $days;
    }
}
