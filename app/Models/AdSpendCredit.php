<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

        // If paused/suspended, no budget
        if ($this->payment_status === self::PAYMENT_PAUSED || 
            $this->status === self::STATUS_SUSPENDED) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * Deduct an amount from the credit balance.
     */
    public function deduct(float $amount, string $description = null): bool
    {
        if ($amount > $this->current_balance) {
            return false;
        }

        $this->current_balance -= $amount;
        
        // Update status based on balance
        $this->updateBalanceStatus();
        
        $this->save();

        // Record transaction
        $this->transactions()->create([
            'type' => 'deduction',
            'amount' => -$amount,
            'balance_after' => $this->current_balance,
            'description' => $description ?? 'Daily ad spend charge',
        ]);

        return true;
    }

    /**
     * Add credit to the account.
     */
    public function addCredit(float $amount, string $description = null, string $stripeChargeId = null): void
    {
        $this->current_balance += $amount;
        $this->updateBalanceStatus();
        $this->save();

        // Record transaction
        $this->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $this->current_balance,
            'description' => $description ?? 'Credit added',
            'stripe_charge_id' => $stripeChargeId,
        ]);
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

        return abs($recentDeductions) / 7;
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
     * @param float $dailyBudget The daily ad spend budget
     * @param int $days Number of days to prepay (default 7)
     * @return float
     */
    public static function calculateInitialCredit(float $dailyBudget, int $days = 7): float
    {
        return $dailyBudget * $days;
    }
}
