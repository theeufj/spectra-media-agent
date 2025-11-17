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
        'facebook_ads_campaign_id',
    ];

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
}
