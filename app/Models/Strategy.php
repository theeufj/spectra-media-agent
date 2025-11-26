<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Strategy extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'platform',
        'campaign_type',
        'ad_copy_strategy',
        'imagery_strategy',
        'video_strategy',
        'signed_off_at',
        'bidding_strategy',
        'cpa_target',
        'revenue_cpa_multiple',
        'execution_plan',
        'execution_result',
        'execution_time',
        'execution_errors',
        'google_ads_ad_group_id',
        'status',
        'deployed_at',
        'deployment_status',
        'deployment_error',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'signed_off_at' => 'datetime',
        'deployed_at' => 'datetime',
        'bidding_strategy' => 'array',
        'execution_plan' => 'array',
        'execution_result' => 'array',
        'execution_errors' => 'array',
        'execution_time' => 'float',
    ];

    /**
     * A Strategy belongs to a Campaign.
     * This defines the inverse of the one-to-many relationship.
     * In Go, this might be a pointer back to the parent Campaign struct: `Campaign *Campaign`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the ad copies for the strategy.
     */
    public function adCopies()
    {
        return $this->hasMany(AdCopy::class);
    }

    /**
     * Get the image collaterals for the strategy.
     */
    public function imageCollaterals()
    {
        return $this->hasMany(ImageCollateral::class);
    }

    /**
     * Get the video collaterals for the strategy.
     */
    public function videoCollaterals()
    {
        return $this->hasMany(VideoCollateral::class);
    }

    /**
     * Get the targeting configuration for the strategy.
     */
    public function targetingConfig()
    {
        return $this->hasOne(TargetingConfig::class);
    }
}
