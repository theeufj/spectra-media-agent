<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * AdSpendTransaction
 *
 * Records all ad spend credit transactions (charges, credits, refunds).
 */
class AdSpendTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_spend_credit_id',
        'type',
        'amount',
        'billed_for',
        'balance_after',
        'description',
        'stripe_charge_id',
        'campaign_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'billed_for' => 'date',
        'metadata' => 'array',
    ];

    // Transaction types
    const TYPE_CREDIT = 'credit';           // Money added to account

    const TYPE_DEDUCTION = 'deduction';     // Daily ad spend charge

    const TYPE_REFUND = 'refund';           // Refund to customer

    const TYPE_ADJUSTMENT = 'adjustment';   // Manual adjustment

    const TYPE_DEBIT = 'debit';             // Legacy: written by since-removed code

    /**
     * Every type that takes money out of the account.
     *
     * 'debit' is here because two historical rows carry it. No current code
     * writes it, but leaving it out of this list is what made $598.14 of real
     * debits invisible to the ledger totals — and therefore countable a second
     * time as "unreconciled".
     */
    const DEBIT_TYPES = [self::TYPE_DEDUCTION, self::TYPE_ADJUSTMENT, self::TYPE_DEBIT];

    /**
     * Total taken out of an account, always positive.
     *
     * Read this rather than summing amounts yourself. The stored sign is not
     * consistent — deduct() writes negative, the legacy 'debit' rows are
     * positive — so a plain SUM() over mixed types returns a number that means
     * nothing. Subtracting one from actual spend inflated a customer's
     * "unreconciled" figure by twice everything already billed.
     */
    public static function totalDebited(int $creditId): float
    {
        return round((float) static::query()
            ->where('ad_spend_credit_id', $creditId)
            ->whereIn('type', self::DEBIT_TYPES)
            ->sum(DB::raw('ABS(amount)')), 2);
    }

    /**
     * The credit account this transaction belongs to.
     */
    public function adSpendCredit(): BelongsTo
    {
        return $this->belongsTo(AdSpendCredit::class);
    }

    /**
     * The campaign this transaction is for (if applicable).
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
