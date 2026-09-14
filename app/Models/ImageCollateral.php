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
        'concept_key',
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
     * Distinct pictures per campaign, whatever the plan allows.
     *
     * Nothing bound this before. The gate was consulted three times in a row
     * by GenerateStrategyCollateral before any row existed, so it always
     * passed, and the prompt splitter returns a variable number of scenes per
     * job — campaign 36 got 4 pictures and campaign 40 got 8, from identical
     * code. Twenty-four cards for eight photographs is not a richer set, it is
     * a worse one: nobody reviews twenty-four, and the repetition reads as the
     * model having had one idea.
     *
     * Six is a set a person will actually look through.
     */
    public const MAX_CONCEPTS_PER_CAMPAIGN = 6;

    /**
     * Images per campaign on the free plan.
     *
     * Named because the limit is enforced in two places — the controller
     * refuses the request up front, the job keeps its check as a backstop —
     * and a bare 4 in both is how two copies of a rule drift apart. It is also
     * quoted to the user, who is owed the actual number.
     */
    public const FREE_TIER_LIMIT_PER_CAMPAIGN = 4;

    /**
     * How many pictures this campaign may hold in total.
     *
     * The plan's allowance and the per-campaign ceiling, whichever is smaller.
     * The ceiling must not hand a two-image plan six, and a generous plan must
     * not put twenty-four cards in front of someone.
     */
    public static function capForCampaign(Campaign $campaign): int
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return 0;
        }

        /*
           The plan's own allowance, for every plan.

           "Paid accounts have no per-campaign limit" meant image_generations
           was decorative above the free tier: a one-time setup customer is sold
           ten and generated twenty-seven, because collateral generation can run
           more than once against a strategy and nothing counted. Video got a
           gate when concepts were added; images never had one beyond free.
        */
        $limit = (int) ($customer->resolvePlan()->creative_limits['image_generations']
            ?? self::FREE_TIER_LIMIT_PER_CAMPAIGN);

        return max(0, min($limit, self::MAX_CONCEPTS_PER_CAMPAIGN));
    }

    /**
     * Whether another picture may be generated for this campaign.
     */
    public static function canGenerateForCampaign(Campaign $campaign): bool
    {
        return static::conceptsForCampaign($campaign) < static::capForCampaign($campaign);
    }

    /**
     * Distinct pictures generated for a campaign, not files.
     *
     * One scene is stored once per ad format, so counting rows counted the
     * same picture three times and made the allowance a third of what it
     * claimed. Rows predating concept_key have none, and each of those is
     * counted on its own — the old behaviour, for the old rows.
     */
    public static function conceptsForCampaign(Campaign $campaign): int
    {
        $rows = static::where('campaign_id', $campaign->id)
            ->get(['id', 'concept_key']);

        $keyed = $rows->whereNotNull('concept_key')->unique('concept_key')->count();
        $unkeyed = $rows->whereNull('concept_key')->count();

        return $keyed + $unkeyed;
    }
}
