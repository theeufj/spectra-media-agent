<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'balance_after',
        'description',
        'stripe_charge_id',
        'campaign_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
    ];

    // Transaction types
    const TYPE_CREDIT = 'credit';           // Money added to account
    const TYPE_DEDUCTION = 'deduction';     // Daily ad spend charge
    const TYPE_REFUND = 'refund';           // Refund to customer
    const TYPE_ADJUSTMENT = 'adjustment';   // Manual adjustment

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
