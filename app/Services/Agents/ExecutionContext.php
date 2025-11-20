<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
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
    public array $availableAssets;
    public array $platformStatus;
    public array $metadata;

    public function __construct(
        Strategy $strategy,
        Campaign $campaign,
        Customer $customer,
        array $availableAssets = [],
        array $platformStatus = [],
        array $metadata = []
    ) {
        $this->strategy = $strategy;
        $this->campaign = $campaign;
        $this->customer = $customer;
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
        // Analyze available assets
        $availableAssets = [
            'images' => $strategy->imageCollaterals()->where('is_active', true)->count(),
            'videos' => $strategy->videoCollaterals()->where('is_active', true)->count(),
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

        return new self(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: $availableAssets,
            platformStatus: $platformStatus
        );
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
     * Calculate daily budget from total campaign budget.
     * 
     * @return float Daily budget
     */
    public function calculateDailyBudget(): float
    {
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
