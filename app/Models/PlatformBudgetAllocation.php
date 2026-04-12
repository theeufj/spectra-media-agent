<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformBudgetAllocation extends Model
{
    protected $fillable = [
        'customer_id',
        'total_monthly_budget',
        'google_ads_pct',
        'facebook_ads_pct',
        'microsoft_ads_pct',
        'linkedin_ads_pct',
        'per_campaign_splits',
        'strategy',
        'target_roas',
        'target_cpa',
        'auto_rebalance',
        'rebalance_frequency',
        'last_rebalanced_at',
        'constraints',
        'ai_reasoning',
        'last_ai_analysis_at',
    ];

    protected $casts = [
        'total_monthly_budget' => 'decimal:2',
        'google_ads_pct' => 'decimal:2',
        'facebook_ads_pct' => 'decimal:2',
        'microsoft_ads_pct' => 'decimal:2',
        'linkedin_ads_pct' => 'decimal:2',
        'per_campaign_splits' => 'array',
        'ai_reasoning' => 'array',
        'last_ai_analysis_at' => 'datetime',
        'target_roas' => 'decimal:2',
        'target_cpa' => 'decimal:2',
        'auto_rebalance' => 'boolean',
        'last_rebalanced_at' => 'datetime',
        'constraints' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function dailyBudgetFor(string $platform): float
    {
        $pctField = "{$platform}_pct";
        $pct = (float) ($this->$pctField ?? 0);
        return round(($this->total_monthly_budget / 30.4) * ($pct / 100), 2);
    }
}
