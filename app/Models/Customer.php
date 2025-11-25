<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'description',
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
}
