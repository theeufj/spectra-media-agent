<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    /**
     * `primary_status` values in which the ad platform reports the campaign as
     * actually delivering.
     *
     * LIMITED and LEARNING count: both serve impressions, just constrained or
     * still calibrating. PENDING does not — it is awaiting review. This is
     * deliberately NOT the same question as `status === Active`; the two drift
     * by design and conflating them caused BILL-8. See CampaignStatus.
     */
    public const SERVING_PRIMARY_STATUSES = ['ELIGIBLE', 'LIMITED', 'LEARNING'];

    /** Campaigns the platform reports as delivering right now. */
    public function scopeServing($query)
    {
        return $query->whereIn('primary_status', self::SERVING_PRIMARY_STATUSES);
    }

    /**
     * May collateral generation render video for this campaign unprompted?
     *
     * No, if we built the campaign for them. GenerateCampaignCollateral queues
     * two Veo renders per strategy off the generate_video flag, and Veo is
     * billed per second of output — real money spent on a signup that has not
     * yet decided it wants us. Video stays a deliberate choice the customer
     * makes on the collateral screen once they have seen what we built.
     */
    public function allowsAutomaticVideo(): bool
    {
        return $this->auto_generated_at === null;
    }

    /** Is the platform reporting this campaign as delivering? */
    public function isServing(): bool
    {
        return in_array($this->primary_status, self::SERVING_PRIMARY_STATUSES, true);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'name',
        'status',
        'reason',
        'goals',
        'target_market',
        'voice',
        'total_budget',
        'daily_budget',
        'auto_generated_at',
        'budget_confirmed_at',
        'budget_rationale',
        'start_date',
        'end_date',
        'primary_kpi',
        'product_focus',
        'landing_page_url',
        'exclusions',
        'google_ads_campaign_id',
        'google_ads_experiment_id',
        'platform_status',
        'primary_status',
        'primary_status_reasons',
        'last_checked_at',
        'facebook_ads_campaign_id',
        'microsoft_ads_campaign_id',
        'linkedin_campaign_id',
        'strategy_generation_started_at',
        'strategy_generation_completed_at',
        'strategy_generation_error',
        'pending_admin_deployment_at',
        'geographic_targeting',
        'keywords',
        'platforms',
        'latest_optimization_analysis',
        'last_optimized_at',
        'healing_actions',
        'keyword_actions',
        'budget_adjustments',
        'last_maintenance_at',
        'last_maintenance_results',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status' => CampaignStatus::class,
        'goals' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'primary_status_reasons' => 'array',
        'auto_generated_at' => 'datetime',
        'budget_confirmed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'geographic_targeting' => 'array',
        'keywords' => 'array',
        'platforms' => 'array',
        'strategy_generation_started_at' => 'datetime',
        'strategy_generation_completed_at' => 'datetime',
        'latest_optimization_analysis' => 'array',
        'last_optimized_at' => 'datetime',
        'healing_actions' => 'array',
        'keyword_actions' => 'array',
        'budget_adjustments' => 'array',
        'last_maintenance_at' => 'datetime',
        'last_maintenance_results' => 'array',
    ];

    /**
     * Return the fully-qualified Google Ads resource name for this campaign.
     * google_ads_campaign_id may store either the full resource name
     * (customers/{id}/campaigns/{id}) or just the numeric campaign ID.
     * This method normalises both forms.
     */
    public function googleAdsResourceName(): ?string
    {
        if (! $this->google_ads_campaign_id) {
            return null;
        }
        if (str_starts_with($this->google_ads_campaign_id, 'customers/')) {
            return $this->google_ads_campaign_id;
        }
        $customerId = $this->customer?->cleanGoogleCustomerId();

        return $customerId
            ? "customers/{$customerId}/campaigns/{$this->google_ads_campaign_id}"
            : null;
    }

    /**
     * The numeric Google Ads campaign ID, whether google_ads_campaign_id is stored as a
     * bare number or a full "customers/{cid}/campaigns/{id}" resource name. Safe to
     * interpolate into a GAQL `campaign.id = ` filter — stripping non-digits from a
     * resource name would fuse the customer and campaign IDs into an invalid number.
     */
    public function googleCampaignNumericId(): ?string
    {
        $val = (string) ($this->google_ads_campaign_id ?? '');
        if ($val === '') {
            return null;
        }
        if (preg_match('/campaigns\/(\d+)/', $val, $m)) {
            return $m[1];
        }

        return ctype_digit($val) ? $val : null;
    }

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
    /**
     * @return BelongsTo<Customer, Campaign>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A Campaign has many Strategies.
     *
     * @return HasMany<Strategy, $this>
     */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    /**
     * Record a platform status, keeping the lifecycle status in step.
     *
     * There are two status columns and they mean different things:
     * `platform_status` is what Google, Facebook, Microsoft or LinkedIn say, and
     * `status` is our lifecycle state. AdSpendBillingService filters on `status`
     * — so a write that sets only `platform_status` stops the ads while billing
     * carries on charging for a campaign it still believes is running.
     *
     * That has now been the cause of two separate billing leaks (BILL-8, and the
     * admin pause path), which is reason enough for every write to go through
     * one place rather than each caller remembering to set the pair.
     *
     * Returns true if the lifecycle status moved.
     */
    public function applyPlatformStatus(string $platformStatus): bool
    {
        $target = match (strtoupper($platformStatus)) {
            'ENABLED' => CampaignStatus::Active,
            'PAUSED' => CampaignStatus::Paused,
            'REMOVED' => CampaignStatus::Ended,
            // UNKNOWN tells us nothing; record what the platform said and leave
            // the lifecycle state alone rather than guessing.
            default => null,
        };

        $attributes = ['platform_status' => strtoupper($platformStatus)];

        // Never resurrect a campaign the customer has not finished setting up. A
        // draft carrying a platform id is a data problem to investigate, not one
        // to paper over by flipping it live.
        $unfinished = in_array($this->status, [CampaignStatus::Draft, CampaignStatus::PendingAdminDeployment], true);

        if (! $target || $unfinished || $this->status === $target) {
            $this->update($attributes);

            return false;
        }

        $this->update($attributes + ['status' => $target]);

        return true;
    }

    /**
     * Strategy snapshots, oldest first when ordered by version_number.
     *
     * SummarizeCampaignHistoryService has read this since 2025; the relation
     * was never defined, so the call fatalled.
     *
     * @return HasMany<CampaignVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CampaignVersion::class);
    }

    /**
     * Per-platform daily performance rows.
     *
     * These are the tables that are actually populated — FetchGoogleAdsPerformanceData,
     * FetchFacebookAdsPerformanceData, MicrosoftAds\PerformanceService and
     * LinkedInAds\PerformanceService each write one row per campaign per date.
     * (Contrast Strategy::performanceData(), whose table has no writer at all.)
     *
     * Declared here because YesterdayPerformanceSummaryTest already queries
     * `campaigns.googleAdsPerformanceData` through whereHas, which would have
     * thrown the moment that test ran outside its RUN_INTEGRATION_TESTS guard.
     *
     * Money lives in `cost`, and there is one row per DATE — sum over a range
     * rather than taking first().
     *
     * @return HasMany<GoogleAdsPerformanceData, $this>
     */
    public function googleAdsPerformanceData(): HasMany
    {
        return $this->hasMany(GoogleAdsPerformanceData::class);
    }

    /** @return HasMany<FacebookAdsPerformanceData, $this> */
    public function facebookAdsPerformanceData(): HasMany
    {
        return $this->hasMany(FacebookAdsPerformanceData::class);
    }

    /** @return HasMany<MicrosoftAdsPerformanceData, $this> */
    public function microsoftAdsPerformanceData(): HasMany
    {
        return $this->hasMany(MicrosoftAdsPerformanceData::class);
    }

    /** @return HasMany<LinkedInAdsPerformanceData, $this> */
    public function linkedInAdsPerformanceData(): HasMany
    {
        return $this->hasMany(LinkedInAdsPerformanceData::class);
    }

    /**
     * Scope campaigns that have been deployed to at least one ad platform.
     */
    public function scopeWithDeployedPlatforms($query)
    {
        return $query->where(function ($campaignQuery) {
            $campaignQuery
                ->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id')
                ->orWhereNotNull('microsoft_ads_campaign_id')
                ->orWhereNotNull('linkedin_campaign_id')
                ->orWhereHas('strategies', function ($strategyQuery) {
                    $strategyQuery->whereIn('deployment_status', Strategy::DEPLOYED_STATUSES);
                });
        });
    }

    /**
     * The pages selected for this campaign.
     *
     * @return BelongsToMany<CustomerPage, $this>
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(CustomerPage::class, 'campaign_pages');
    }

    public function imageCollaterals(): HasMany
    {
        return $this->hasMany(ImageCollateral::class);
    }

    public function videoCollaterals(): HasMany
    {
        return $this->hasMany(VideoCollateral::class);
    }
}
