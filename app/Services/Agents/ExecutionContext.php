<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Strategy;

/**
 * Represents the execution context for deployment.
 * 
 * Contains all necessary information about the campaign, strategy, customer,
 * available assets, and platform status needed for AI-powered execution planning.
 */
class ExecutionContext
{
    public Strategy $strategy;
    public Campaign $campaign;
    public Customer $customer;
    public ?BrandGuideline $brandGuideline;
    public array $availableAssets;
    public array $platformStatus;
    public array $metadata;

    public function __construct(
        Strategy $strategy,
        Campaign $campaign,
        Customer $customer,
        ?BrandGuideline $brandGuideline = null,
        array $availableAssets = [],
        array $platformStatus = [],
        array $metadata = []
    ) {
        $this->strategy = $strategy;
        $this->campaign = $campaign;
        $this->customer = $customer;
        $this->brandGuideline = $brandGuideline;
        $this->availableAssets = $availableAssets;
        $this->platformStatus = $platformStatus;
        $this->metadata = $metadata;
    }

    /**
     * Create an ExecutionContext from models with asset analysis.
     * 
     * @param Strategy $strategy The strategy to execute
     * @param Campaign $campaign The parent campaign
     * @param Customer $customer The customer
     * @param array $platformStatus Platform-specific status information
     * @return self
     */
    public static function create(
        Strategy $strategy,
        Campaign $campaign,
        Customer $customer,
        array $platformStatus = []
    ): self {
        // Analyze available assets — include strategy-specific and pre-wizard (strategy_id = null) uploads
        $preWizardImages = $campaign->imageCollaterals()->whereNull('strategy_id')->where('is_active', true)->count();
        $preWizardVideos = $campaign->videoCollaterals()->whereNull('strategy_id')->where('is_active', true)->count();
        $availableAssets = [
            'images' => $strategy->imageCollaterals()->where('is_active', true)->count() + $preWizardImages,
            'videos' => $strategy->videoCollaterals()->where('is_active', true)->count() + $preWizardVideos,
            'ad_copies' => $strategy->adCopies()->count(),
            'has_ad_copy' => $strategy->adCopies()->exists(),
        ];

        // Add detailed asset information if requested
        if ($strategy->relationLoaded('imageCollaterals')) {
            $availableAssets['image_details'] = $strategy->imageCollaterals()
                ->where('is_active', true)
                ->get()
                ->map(fn($img) => [
                    'id' => $img->id,
                    's3_path' => $img->s3_path,
                    'dimensions' => $img->dimensions ?? null,
                ])->toArray();
        }

        if ($strategy->relationLoaded('videoCollaterals')) {
            $availableAssets['video_details'] = $strategy->videoCollaterals()
                ->where('is_active', true)
                ->get()
                ->map(fn($vid) => [
                    'id' => $vid->id,
                    's3_path' => $vid->s3_path,
                    'duration' => $vid->duration ?? null,
                ])->toArray();
        }

        $brandGuideline = $customer->brandGuideline ?? null;

        // Pull last 30 days of performance so execution agent avoids repeating past mistakes
        $priorPerformance = self::buildPriorPerformance($campaign);

        // Pull last Facebook learning phase outcome so re-deployments don't repeat the same mistakes
        $fbLearningOutcome = self::buildFacebookLearningOutcome($campaign);

        // Pull last Quality Score improvement data so Google execution agent avoids low-QS patterns
        $qualityScoreContext = self::buildQualityScoreContext($campaign);

        return new self(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            brandGuideline: $brandGuideline,
            availableAssets: $availableAssets,
            platformStatus: $platformStatus,
            metadata: [
                'prior_performance'   => $priorPerformance,
                'fb_learning_outcome' => $fbLearningOutcome,
                'quality_score'       => $qualityScoreContext,
            ]
        );
    }

    private static function buildPriorPerformance(Campaign $campaign): array
    {
        $prior = [];

        $google = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('AVG(ctr) as avg_ctr, AVG(cpc) as avg_cpc, AVG(cpa) as avg_cpa, SUM(conversions) as total_conversions, SUM(cost) as total_cost, COUNT(*) as days')
            ->first();

        if ($google && $google->days > 0) {
            $prior['google'] = [
                'avg_ctr'           => round(($google->avg_ctr ?? 0) * 100, 2),
                'avg_cpc'           => round($google->avg_cpc ?? 0, 2),
                'avg_cpa'           => round($google->avg_cpa ?? 0, 2),
                'total_conversions' => round($google->total_conversions ?? 0, 1),
                'total_spend'       => round($google->total_cost ?? 0, 2),
                'days_of_data'      => $google->days,
            ];
        }

        $facebook = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('AVG(ctr) as avg_ctr, AVG(cpc) as avg_cpc, AVG(cpa) as avg_cpa, SUM(conversions) as total_conversions, SUM(cost) as total_cost, COUNT(*) as days')
            ->first();

        if ($facebook && $facebook->days > 0) {
            $prior['facebook'] = [
                'avg_ctr'           => round(($facebook->avg_ctr ?? 0) * 100, 2),
                'avg_cpc'           => round($facebook->avg_cpc ?? 0, 2),
                'avg_cpa'           => round($facebook->avg_cpa ?? 0, 2),
                'total_conversions' => round($facebook->total_conversions ?? 0, 1),
                'total_spend'       => round($facebook->total_cost ?? 0, 2),
                'days_of_data'      => $facebook->days,
            ];
        }

        return $prior;
    }

    /**
     * Pull the last Quality Score improvement AgentActivity for this campaign.
     * Returns flagged keywords and applied actions so execution agent can avoid low-QS patterns.
     */
    private static function buildQualityScoreContext(Campaign $campaign): ?array
    {
        $record = AgentActivity::where('campaign_id', $campaign->id)
            ->where('agent_type', 'quality_score')
            ->where('action', 'qs_improvements_applied')
            ->latest()
            ->first();

        if (!$record || empty($record->details)) {
            return null;
        }

        return [
            'actions'     => $record->details['actions'] ?? [],
            'flagged'     => $record->details['flagged'] ?? [],
            'recorded_at' => $record->created_at?->toDateString(),
        ];
    }

    /**
     * Pull the last Facebook learning phase AgentActivity for this campaign.
     * Returns a summary string the execution prompt can use, or null if no record exists.
     */
    private static function buildFacebookLearningOutcome(Campaign $campaign): ?array
    {
        $record = AgentActivity::where('campaign_id', $campaign->id)
            ->where('agent_type', 'facebook_learning')
            ->latest()
            ->first();

        if (!$record) {
            return null;
        }

        return [
            'action'      => $record->action,
            'description' => $record->description,
            'details'     => $record->details ?? [],
            'recorded_at' => $record->created_at?->toDateString(),
        ];
    }

    /**
     * Convert context to array for passing to AI prompts.
     * 
     * @return array Context as array suitable for prompt generation
     */
    public function toArray(): array
    {
        return [
            'campaign' => [
                'id' => $this->campaign->id,
                'name' => $this->campaign->name,
                'total_budget' => $this->campaign->total_budget,
                'daily_budget' => $this->campaign->daily_budget,
                'start_date' => $this->campaign->start_date,
                'end_date' => $this->campaign->end_date,
                'primary_kpi' => $this->campaign->primary_kpi,
                'landing_page_url' => $this->campaign->landing_page_url,
                'goals' => $this->campaign->goals,
                'target_market' => $this->campaign->target_market,
            ],
            'strategy' => [
                'id' => $this->strategy->id,
                'platform' => $this->strategy->platform,
                'campaign_type' => $this->strategy->campaign_type ?? 'display',
                'ad_copy_strategy' => $this->strategy->ad_copy_strategy,
                'imagery_strategy' => $this->strategy->imagery_strategy,
                'video_strategy' => $this->strategy->video_strategy,
                'budget' => $this->strategy->budget ?? null,
                'bidding_strategy' => $this->strategy->bidding_strategy ?? null,
            ],
            'customer' => [
                'id' => $this->customer->id,
                'business_name' => $this->customer->business_name ?? null,
            ],
            'available_assets' => $this->availableAssets,
            'platform_status' => $this->platformStatus,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get campaign duration in days.
     * 
     * @return int Number of days
     */
    public function getCampaignDurationDays(): int
    {
        $start = \Carbon\Carbon::parse($this->campaign->start_date);
        $end = \Carbon\Carbon::parse($this->campaign->end_date);
        return $start->diffInDays($end);
    }

    /**
     * Calculate daily budget for this strategy.
     * Uses the strategy-level budget if set, otherwise falls back to campaign budget split.
     * 
     * @return float Daily budget
     */
    public function calculateDailyBudget(): float
    {
        // Use strategy-level budget if assigned (set by DeployCampaign budget splitting)
        if ($this->strategy->daily_budget && $this->strategy->daily_budget > 0) {
            return (float) $this->strategy->daily_budget;
        }

        // Fallback: split campaign budget by duration
        $duration = $this->getCampaignDurationDays();
        if ($duration <= 0) {
            return 0;
        }
        return $this->campaign->total_budget / $duration;
    }

    /**
     * Check if specific asset type is available.
     * 
     * @param string $assetType Asset type (images, videos, ad_copies)
     * @return bool True if assets are available
     */
    public function hasAssetType(string $assetType): bool
    {
        return ($this->availableAssets[$assetType] ?? 0) > 0;
    }
}
