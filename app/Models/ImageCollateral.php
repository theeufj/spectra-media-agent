<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageCollateral extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'strategy_id',
        'platform',
        's3_path',
        'cloudfront_url',
        'parent_id',
        'refinement_depth',
        'is_active',
        'is_seed',
        'should_deploy',
        'source',
        'format',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_seed' => 'boolean',
        'should_deploy' => 'boolean',
    ];

    /**
     * All media a strategy may use: its own rows plus campaign-level rows
     * (strategy_id null). Campaign-level rows are what the wizard's upload
     * step creates — the customer uploaded them for the campaign as a whole,
     * before strategies existed, so every strategy shares them. Deploy-time
     * queries that filtered on strategy_id alone silently orphaned them.
     */
    public function scopeForStrategy($query, Strategy $strategy)
    {
        return $query->where(function ($q) use ($strategy) {
            $q->where('strategy_id', $strategy->id)
                ->orWhere(function ($campaignLevel) use ($strategy) {
                    $campaignLevel->where('campaign_id', $strategy->campaign_id)
                        ->whereNull('strategy_id');
                });
        });
    }

    /**
     * Get the parent image that this image was refined from.
     */
    public function parent()
    {
        return $this->belongsTo(ImageCollateral::class, 'parent_id');
    }

    /**
     * Get the child images that were refined from this image.
     */
    public function children()
    {
        return $this->hasMany(ImageCollateral::class, 'parent_id');
    }

    /**
     * Get the campaign that this image collateral belongs to.
     */
    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the strategy that this image collateral belongs to.
     */
    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * Check if a new image can be generated for the given campaign.
     * Free / unsubscribed users: max 4 images per campaign.
     */
    /**
     * Images per campaign on the free plan.
     *
     * Named because the limit is now enforced in two places — the controller
     * refuses the request up front, the job keeps its check as a backstop —
     * and a bare 4 in both is how two copies of a rule drift apart. It is also
     * quoted to the user, who is owed the actual number.
     */
    public const FREE_TIER_LIMIT_PER_CAMPAIGN = 4;

    public static function canGenerateForCampaign(Campaign $campaign): bool
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return false;
        }

        // Paid accounts have no per-campaign limit. Was read off whichever user
        // the customer returned first, so on a shared account the limit applied
        // or not depending on row order.
        if ($customer->isOnPaidPlan()) {
            return true;
        }

        return static::where('campaign_id', $campaign->id)->count() < self::FREE_TIER_LIMIT_PER_CAMPAIGN;
    }
}
