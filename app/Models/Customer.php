<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'description',
        'industry',
        'competitive_strategy',
        'competitive_strategy_updated_at',
        'competitor_analysis_at',
        'country',
        'timezone',
        'currency_code',
        'website',
        'phone',
        'google_ads_refresh_token',
        'google_ads_customer_id',
        'facebook_ads_account_id',
        'facebook_ads_access_token',
        'gtm_container_id',
        'gtm_account_id',
        'gtm_workspace_id',
        'gtm_config',
        'gtm_installed',
        'gtm_last_verified',
        'cro_audits_used',
        'gtm_detected',
        'gtm_detected_at',
    ];

    protected $casts = [
        'gtm_config' => 'array',
        'gtm_installed' => 'boolean',
        'gtm_detected' => 'boolean',
        'gtm_last_verified' => 'datetime',
        'gtm_detected_at' => 'datetime',
        'competitive_strategy' => 'array',
        'competitive_strategy_updated_at' => 'datetime',
        'competitor_analysis_at' => 'datetime',
    ];

    /**
     * The users that belong to the customer.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    /**
     * Get the pages for the customer.
     */
    public function pages()
    {
        return $this->hasMany(CustomerPage::class);
    }

    /**
     * Get the campaigns for the customer.
     */
    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Get the brand guideline for the customer.
     */
    public function brandGuideline()
    {
        return $this->hasOne(BrandGuideline::class);
    }

    /**
     * Get the competitors for the customer.
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /**
     * Get the ad spend credit account for the customer.
     */
    public function adSpendCredit()
    {
        return $this->hasOne(AdSpendCredit::class);
    }
}
