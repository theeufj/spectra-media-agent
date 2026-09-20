<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<int, array<string, mixed>>|null $creative_concepts
 * @property array<int, array<string, mixed>>|null $creative_candidates
 * @property array<string, mixed>|null $creative_review
 */
class Strategy extends Model
{
    use HasFactory, HasPublicUuid;

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

    public function supportsVideo(): bool
    {
        return ! in_array($this->campaign_type, ['search', 'shopping', 'local_services'], true)
            && ! preg_match('/search|sem/i', $this->platform);
    }

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
        'generation_id',
        'ad_extensions',
        'conversion_goals',
        'campaign_type',
        'ad_copy_strategy',
        'imagery_strategy',
        'creative_concepts',
        'creative_candidates',
        'creative_review',
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
        'creative_concepts' => 'array',
        'creative_candidates' => 'array',
        'creative_review' => 'array',
        'ad_extensions' => 'array',
        'conversion_goals' => 'array',
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
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The Google Ads campaign this strategy may reuse on redeploy, or null if
     * it must create its own.
     *
     * Prefers the strategy's own id. The legacy campaign-level id is honoured
     * only by the campaign's first Google strategy — it was that strategy's
     * deploy that created it. Handing it to every Google strategy attached
     * the second strategy's ad groups to the first one's campaign (wrong
     * type, wrong budget).
     */
    public function reusableGoogleCampaignId(): ?string
    {
        if ($this->google_ads_campaign_id) {
            return $this->google_ads_campaign_id;
        }

        $campaign = $this->campaign;
        if (! $campaign instanceof Campaign || ! $campaign->google_ads_campaign_id) {
            return null;
        }

        $firstGoogleStrategyId = $campaign->strategies()
            ->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
            ->orderBy('id')
            ->value('id');

        return $firstGoogleStrategyId === $this->id ? $campaign->google_ads_campaign_id : null;
    }

    /**
     * Record a freshly created Google Ads campaign against this strategy,
     * keeping the legacy campaign-level column filled for everything that
     * still reads it (verification fallback, budget jobs).
     */
    public function recordGoogleCampaignId(string $resourceName): void
    {
        $this->forceFill(['google_ads_campaign_id' => $resourceName])->save();

        $campaign = $this->campaign;
        if ($campaign instanceof Campaign && empty($campaign->google_ads_campaign_id)) {
            $campaign->forceFill(['google_ads_campaign_id' => $resourceName])->save();
        }
    }

    /**
     * Strategy-level performance rows.
     *
     * The inverse of PerformanceData::strategy(), which has existed since the
     * model was added but was never declared on this side — so every
     * `->with('strategies.performanceData')` in the codebase was a fatal
     * "call to undefined relationship" waiting to happen.
     *
     * ⚠ NOTHING WRITES `performance_data`. The four fetch jobs
     * (FetchGoogleAdsPerformanceData and friends) all write the per-platform,
     * campaign-keyed tables instead — see Campaign::googleAdsPerformanceData()
     * and its siblings. So this relation is correct but returns an empty
     * collection, and the services that read it
     * (BudgetAllocationService, PortfolioOptimizationService,
     * DashboardDataService, StatisticalSignificanceService) will compute zeros
     * rather than crash.
     *
     * That is a deliberate improvement on crashing, not a claim that those
     * services work. They are all currently unreferenced; before wiring any of
     * them up, either populate this table or repoint them at the per-platform
     * relations. Repointing is not a rename: those tables store one row per
     * campaign PER DATE and call the money column `cost`, whereas these
     * services expect a single row with `spend`, so the aggregation has to be
     * chosen rather than assumed.
     *
     * @return HasMany<PerformanceData, $this>
     */
    public function performanceData(): HasMany
    {
        return $this->hasMany(PerformanceData::class);
    }

    /**
     * Get the ad copies for the strategy.
     *
     * The generic matters: without it PHPStan resolves
     * `$strategy->adCopies->first()` to a bare Model, so every read of
     * ->headlines / ->descriptions in the deployment agents looked like an
     * undefined property and the real errors were invisible behind it.
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
