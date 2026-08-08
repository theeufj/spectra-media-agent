<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Strategy extends Model
{
    use HasFactory;

    /**
     * deployment_status values that mean "this strategy is live on the platform".
     *
     * 'verified' is the terminal success state — it follows 'deployed' once
     * VerifyDeployment confirms the objects exist on the platform. Both count as
     * live, and six call sites already agreed on that pair. AutoStartABTests did
     * not: it looked for ['deployed', 'live', 'active'], two of which are not
     * values this column ever holds, while omitting 'verified'. So the strategies
     * that had deployed *most* successfully were the ones excluded from A/B
     * testing, and no test was ever started.
     *
     * The only recorded values are: deployed, deploying, verified, null.
     */
    public const DEPLOYED_STATUSES = ['deployed', 'verified'];

    /** Strategies live on their platform. */
    public function scopeDeployed($query)
    {
        return $query->whereIn('deployment_status', self::DEPLOYED_STATUSES);
    }

    /** Is this strategy live on its platform? */
    public function isDeployed(): bool
    {
        return in_array($this->deployment_status, self::DEPLOYED_STATUSES, true);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'platform',
        'daily_budget',
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
        'collateral_errors',
        'generate_video',
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
        'collateral_errors' => 'array',
        'generate_video' => 'boolean',
        'execution_time' => 'float',
    ];

    /**
     * A Strategy belongs to a Campaign.
     * This defines the inverse of the one-to-many relationship.
     * In Go, this might be a pointer back to the parent Campaign struct: `Campaign *Campaign`.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the ad copies for the strategy.
     *
     * @return HasMany<AdCopy, $this>
     */
    public function adCopies(): HasMany
    {
        return $this->hasMany(AdCopy::class);
    }

    /**
     * Get the image collaterals for the strategy.
     *
     * @return HasMany<ImageCollateral, $this>
     */
    public function imageCollaterals(): HasMany
    {
        return $this->hasMany(ImageCollateral::class);
    }

    /**
     * Get the video collaterals for the strategy.
     *
     * @return HasMany<VideoCollateral, $this>
     */
    public function videoCollaterals(): HasMany
    {
        return $this->hasMany(VideoCollateral::class);
    }

    /**
     * Get the targeting configuration for the strategy.
     *
     * @return HasOne<TargetingConfig, $this>
     */
    public function targetingConfig(): HasOne
    {
        return $this->hasOne(TargetingConfig::class);
    }
}
