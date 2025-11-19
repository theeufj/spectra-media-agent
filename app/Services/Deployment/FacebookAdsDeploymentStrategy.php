<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CreativeService;
use App\Services\FacebookAds\AdService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FacebookAdsDeploymentStrategy implements DeploymentStrategy
{
    protected Customer $customer;
    protected CampaignService $campaignService;
    protected AdSetService $adSetService;
    protected CreativeService $creativeService;
    protected AdService $adService;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->campaignService = new CampaignService($customer);
        $this->adSetService = new AdSetService($customer);
        $this->creativeService = new CreativeService($customer);
        $this->adService = new AdService($customer);
    }

    public function deploy(Campaign $campaign, Strategy $strategy): bool
    {
        Log::info("Starting Facebook Ads deployment for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        try {
            $accountId = $this->customer->facebook_ads_account_id;
            
            if (!$accountId) {
                throw new \Exception("No Facebook Ads account linked for customer {$this->customer->id}");
            }

            if (!$this->customer->facebook_page_id) {
                throw new \Exception("No Facebook Page linked for customer {$this->customer->id}. Please connect a Facebook Page first.");
            }

            // Remove 'act_' prefix if present
            $accountId = str_replace('act_', '', $accountId);

            // Deploy based on campaign type
            $result = match($strategy->campaign_type) {
                'display' => $this->deployDisplayCampaign($accountId, $campaign, $strategy),
                'video' => $this->deployVideoCampaign($accountId, $campaign, $strategy),
                default => throw new \Exception("Campaign type '{$strategy->campaign_type}' not supported for Facebook Ads"),
            };

            if (!$result) {
                throw new \Exception('Failed to deploy Facebook Ads campaign');
            }

            Log::info("Successfully deployed to Facebook Ads for Strategy ID: {$strategy->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("Facebook Ads deployment failed for Strategy ID {$strategy->id}: " . $e->getMessage());
            return false;
        }
    }

    private function deployDisplayCampaign(string $accountId, Campaign $campaign, Strategy $strategy): bool
    {
        // 1. Create Facebook Campaign
        $fbCampaign = $this->campaignService->createCampaign(
            $accountId,
            $campaign->name . ' - Display',
            'LINK_CLICKS', // Default objective for display
            (int)($strategy->budget * 100) // Convert to cents
        );

        if (!$fbCampaign || !isset($fbCampaign['id'])) {
            throw new \Exception('Failed to create Facebook campaign');
        }

        $campaign->facebook_ads_campaign_id = $fbCampaign['id'];
        $campaign->save();
        
        $strategy->facebook_campaign_id = $fbCampaign['id'];
        $strategy->save();

        Log::info("Created Facebook campaign: {$fbCampaign['id']}");

        // 2. Create Ad Set with targeting
        $targeting = $this->buildTargeting($strategy);
        
        $fbAdSet = $this->adSetService->createAdSet(
            $fbCampaign['id'],
            $campaign->name . ' - Ad Set',
            (int)($strategy->budget * 100), // Daily budget in cents
            $targeting,
            'LINK_CLICKS',
            'LINK_CLICKS'
        );

        if (!$fbAdSet || !isset($fbAdSet['id'])) {
            throw new \Exception('Failed to create Facebook ad set');
        }

        $strategy->facebook_adset_id = $fbAdSet['id'];
        $strategy->save();

        Log::info("Created Facebook ad set: {$fbAdSet['id']}");

        // 3. Upload Images and Create Creative
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->get();
        $adCopy = $strategy->adCopies()->where('platform', $strategy->platform)->first();

        if ($imageCollaterals->isEmpty() || !$adCopy) {
            throw new \Exception('No images or ad copy available for Facebook ad');
        }

        // Upload first image (Facebook ad creative typically uses one main image)
        $firstImage = $imageCollaterals->first();
        $imageUrl = Storage::disk('s3')->url($firstImage->s3_path);

        $fbCreative = $this->creativeService->createImageCreative(
            $accountId,
            $campaign->name . ' - Creative',
            $imageUrl,
            $adCopy->headlines[0] ?? 'Default Headline',
            $adCopy->descriptions[0] ?? 'Default Description',
            'LEARN_MORE'
        );

        if (!$fbCreative || !isset($fbCreative['id'])) {
            throw new \Exception('Failed to create Facebook ad creative');
        }

        $strategy->facebook_creative_id = $fbCreative['id'];
        $strategy->save();

        Log::info("Created Facebook creative: {$fbCreative['id']}");

        // 4. Create Ad
        $fbAd = $this->adService->createAd(
            $fbAdSet['id'],
            $campaign->name . ' - Ad',
            $fbCreative['id']
        );

        if (!$fbAd || !isset($fbAd['id'])) {
            throw new \Exception('Failed to create Facebook ad');
        }

        $strategy->facebook_ad_id = $fbAd['id'];
        $strategy->save();

        Log::info("Created Facebook ad: {$fbAd['id']}");

        return true;
    }

    private function deployVideoCampaign(string $accountId, Campaign $campaign, Strategy $strategy): bool
    {
        // Similar to display but with video creative
        // 1. Create Facebook Campaign
        $fbCampaign = $this->campaignService->createCampaign(
            $accountId,
            $campaign->name . ' - Video',
            'VIDEO_VIEWS',
            (int)($strategy->budget * 100)
        );

        if (!$fbCampaign || !isset($fbCampaign['id'])) {
            throw new \Exception('Failed to create Facebook video campaign');
        }

        $campaign->facebook_ads_campaign_id = $fbCampaign['id'];
        $campaign->save();
        
        $strategy->facebook_campaign_id = $fbCampaign['id'];
        $strategy->save();

        // 2. Create Ad Set
        $targeting = $this->buildTargeting($strategy);
        
        $fbAdSet = $this->adSetService->createAdSet(
            $fbCampaign['id'],
            $campaign->name . ' - Video Ad Set',
            (int)($strategy->budget * 100),
            $targeting,
            'VIDEO_VIEWS',
            'VIDEO_VIEWS'
        );

        if (!$fbAdSet || !isset($fbAdSet['id'])) {
            throw new \Exception('Failed to create Facebook video ad set');
        }

        $strategy->facebook_adset_id = $fbAdSet['id'];
        $strategy->save();

        // 3. Upload Video and Create Creative
        $videoCollaterals = $strategy->videoCollaterals()->where('is_active', true)->get();
        $adCopy = $strategy->adCopies()->where('platform', $strategy->platform)->first();

        if ($videoCollaterals->isEmpty() || !$adCopy) {
            throw new \Exception('No videos or ad copy available for Facebook video ad');
        }

        $firstVideo = $videoCollaterals->first();
        $videoUrl = Storage::disk('s3')->url($firstVideo->s3_path);

        $fbCreative = $this->creativeService->createVideoCreative(
            $accountId,
            $campaign->name . ' - Video Creative',
            $videoUrl,
            $adCopy->headlines[0] ?? 'Default Headline',
            $adCopy->descriptions[0] ?? 'Default Description'
        );

        if (!$fbCreative || !isset($fbCreative['id'])) {
            throw new \Exception('Failed to create Facebook video creative');
        }

        $strategy->facebook_creative_id = $fbCreative['id'];
        $strategy->save();

        // 4. Create Ad
        $fbAd = $this->adService->createAd(
            $fbAdSet['id'],
            $campaign->name . ' - Video Ad',
            $fbCreative['id']
        );

        if (!$fbAd || !isset($fbAd['id'])) {
            throw new \Exception('Failed to create Facebook video ad');
        }

        $strategy->facebook_ad_id = $fbAd['id'];
        $strategy->save();

        return true;
    }

    private function buildTargeting(Strategy $strategy): array
    {
        // Default targeting - can be enhanced with targeting_configs table
        return [
            'geo_locations' => [
                'countries' => ['US'], // Default to US, should come from targeting config
            ],
            'age_min' => 18,
            'age_max' => 65,
            'genders' => [0], // 0 = all genders
        ];
    }
}
