<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'name',
        'reason',
        'goals',
        'target_market',
        'voice',
        'total_budget',
        'daily_budget',
        'start_date',
        'end_date',
        'primary_kpi',
        'product_focus',
        'landing_page_url',
        'exclusions',
        'google_ads_campaign_id',
        'platform_status',
        'primary_status',
        'primary_status_reasons',
        'last_checked_at',
        'facebook_ads_campaign_id',
        'strategy_generation_started_at',
        'strategy_generation_completed_at',
        'strategy_generation_error',
        'geographic_targeting',
        'keywords',
        'latest_optimization_analysis',
        'last_optimized_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'goals' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'primary_status_reasons' => 'array',
        'last_checked_at' => 'datetime',
        'geographic_targeting' => 'array',
        'keywords' => 'array',
        'strategy_generation_started_at' => 'datetime',
        'strategy_generation_completed_at' => 'datetime',
        'latest_optimization_analysis' => 'array',
        'last_optimized_at' => 'datetime',
    ];

    /**
     * Check if strategy generation is currently in progress.
     */
    public function isGeneratingStrategies(): bool
    {
        return $this->strategy_generation_started_at !== null 
            && $this->strategy_generation_completed_at === null;
    }

    /**
     * Get the customer that owns the campaign.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A Campaign has many Strategies.
     * This defines the one-to-many relationship between the Campaign and Strategy models.
     * In Go, you might represent this with a slice of Strategy structs: `Strategies []Strategy`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    /**
     * The pages selected for this campaign.
     */
    public function pages()
    {
        return $this->belongsToMany(CustomerPage::class, 'campaign_pages');
    }
}
