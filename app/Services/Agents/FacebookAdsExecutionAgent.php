<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Prompts\FacebookAdsExecutionPrompt;
use App\Services\GeminiService;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CreativeService;
use App\Services\FacebookAds\AdService;
use App\Services\Agents\Traits\RetryableApiOperation;
use App\Services\StorageHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Facebook Ads Execution Agent
 * 
 * AI-powered execution agent for Facebook/Meta Ads platform that dynamically generates
 * and executes deployment plans based on available assets, budget, and platform capabilities.
 * 
 * Features:
 * - Dynamic campaign objective selection (LINK_CLICKS, CONVERSIONS, REACH, etc.)
 * - AI-driven execution planning with Google Search grounding
 * - Budget validation and allocation
 * - Dynamic Creative optimization
 * - Advantage+ Campaign recommendations
 * - Creative format optimization (Single Image, Carousel, Video, Stories)
 * - Intelligent error recovery
 */
class FacebookAdsExecutionAgent extends PlatformExecutionAgent
{
    use RetryableApiOperation;
    
    protected string $platform = 'facebook';
    
    protected CampaignService $campaignService;
    protected AdSetService $adSetService;
    protected CreativeService $creativeService;
    protected AdService $adService;
    
    /**
     * Initialize Facebook Ads API services
     */
    protected function initializeServices(): void
    {
        $this->campaignService = new CampaignService($this->customer);
        $this->adSetService = new AdSetService($this->customer);
        $this->creativeService = new CreativeService($this->customer);
        $this->adService = new AdService($this->customer);
    }
    
    /**
     * Execute the deployment with ExecutionContext
     */
    public function execute(ExecutionContext $context): ExecutionResult
    {
        Log::info("FacebookAdsExecutionAgent: Starting execution", [
            'campaign_id' => $context->campaign->id,
            'strategy_id' => $context->strategy->id,
        ]);

        $this->initializeServices();
        
        // Validate prerequisites
        $validation = $this->validatePrerequisites($context);
        if (!$validation->isValid()) {
            return ExecutionResult::failure(
                $validation->getErrors(),
                $validation->getWarnings()
            );
        }

        // Analyze optimization opportunities
        $optimization = $this->analyzeOptimizationOpportunities($context);

        // Generate execution plan
        $plan = $this->generateExecutionPlan($context);
        
        // Execute the plan
        return $this->executePlan($plan, $context);
    }
    
    /**
     * Validate prerequisites before deployment
     * 
     * Checks:
     * - Facebook Ads account connection
     * - Facebook Page connection
     * - Pixel installation status
     * - Available creative assets (images/videos)
     * - Ad copy availability
     * - Budget meets minimum requirements ($5/day minimum)
     * - Payment method validity
     */
    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $result = new ValidationResult(true);

        // Facebook deployment requires an account assigned by a platform admin
        // via the Business Manager (Path A). Accounts must be BM-managed —
        // deployment is blocked until an admin links one from the customer edit page.
        if (!$this->customer->facebook_bm_owned || !$this->customer->facebook_ads_account_id) {
            $result->addError(
                'facebook_bm_not_configured',
                'Facebook ad account not set up. A platform admin must link a Business Manager-managed ad account before campaigns can be deployed.'
            );
            return $result;
        }
        
        // Check Facebook Page connection
        if (!$this->customer->facebook_page_id) {
            $result->addError('facebook_page_not_connected', 'Facebook Page not connected - required for ads');
            return $result;
        }
        
        // Check Pixel installation (warning only - campaigns can run without it)
        if (!$this->hasPixelInstalled($context)) {
            $result->addWarning('no_pixel', 'No Facebook Pixel configured - conversion tracking and Advantage+ campaigns will be limited');
        }
        
        // Validate creative assets
        $strategy = $context->strategy;
        $hasImages = $strategy->imageCollaterals()->where('is_active', true)->exists();
        $hasVideos = $strategy->videoCollaterals()->where('is_active', true)->exists();
        $hasAdCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->exists();
        
        if (!$hasAdCopy) {
            $result->addError('no_ad_copy', 'No ad copy available for Facebook Ads');
        }
        
        if (!$hasImages && !$hasVideos) {
            $result->addError('no_creatives', 'No images or videos available - Facebook Ads require visual creatives');
        }
        
        // Validate budget meets Facebook Ads minimums
        $budgetValidation = $this->validateBudget($context);
        if (!$budgetValidation->passes()) {
            foreach ($budgetValidation->errors as $error) {
                $result->addError('budget_' . $error['code'], $error['message']);
            }
        }
        
        return $result;
    }
    
    /**
     * Analyze optimization opportunities for Facebook Ads
     * 
     * Evaluates:
     * - Dynamic Creative eligibility (3+ images, multiple headlines/descriptions)
     * - Advantage+ Campaign eligibility (pixel + conversions + $50/day minimum)
     * - Creative format opportunities (Single Image vs Carousel vs Video vs Stories)
     * - Audience targeting optimization
     * - Placement optimization opportunities
     * - Retargeting pixel data availability
     */
    protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
    {
        $analysis = new OptimizationAnalysis();
        $strategy = $context->strategy;
        $campaign = $context->campaign;
        
        // Check Dynamic Creative eligibility
        $imageCount = $strategy->imageCollaterals()->where('is_active', true)->count();
        $adCopy = $strategy->adCopies()->where('platform', 'facebook')->first();
        $headlineCount = $adCopy && isset($adCopy->headlines) ? count($adCopy->headlines) : 0;
        $descriptionCount = $adCopy && isset($adCopy->descriptions) ? count($adCopy->descriptions) : 0;
        
        if ($imageCount >= 3 && $headlineCount >= 3 && $descriptionCount >= 2) {
            $analysis->addOpportunity(
                'dynamic_creative_eligible',
                'Campaign is eligible for Dynamic Creative - automatically tests combinations of creative elements',
                'high',
                ['images' => $imageCount, 'headlines' => $headlineCount, 'descriptions' => $descriptionCount]
            );
        }
        
        // Check Advantage+ Campaign eligibility
        $hasPixelWithConversions = $this->hasPixelWithConversions($context);
        $meetsAdvantagePlusBudget = $context->calculateDailyBudget() >= 50.0; // $50/day minimum
        
        if ($hasPixelWithConversions && $meetsAdvantagePlusBudget) {
            $analysis->addOpportunity(
                'advantage_plus_eligible',
                'Campaign is eligible for Advantage+ Campaign - Meta\'s AI-powered campaign type for best performance',
                'high',
                ['pixel_with_conversions' => true, 'budget_meets_minimum' => true]
            );
        } elseif ($meetsAdvantagePlusBudget && !$hasPixelWithConversions) {
            $analysis->addOpportunity(
                'advantage_plus_potential',
                'Budget supports Advantage+ but requires Pixel with conversion data',
                'medium',
                ['budget_meets_minimum' => true, 'needs_pixel_conversions' => true]
            );
        }
        
        // Check creative format opportunities
        $videoCount = $strategy->videoCollaterals()->where('is_active', true)->count();
        
        if ($imageCount >= 3) {
            $analysis->addOpportunity(
                'carousel_ads',
                'Multiple images available - consider Carousel ads for better engagement',
                'medium',
                ['image_count' => $imageCount]
            );
        }
        
        if ($videoCount >= 1) {
            $analysis->addOpportunity(
                'video_ads',
                'Video content available - video ads typically have higher engagement rates',
                'high',
                ['video_count' => $videoCount]
            );
        }
        
        // Check placement optimization
        $analysis->addOpportunity(
            'automatic_placements',
            'Use automatic placements for maximum reach across Facebook, Instagram, Messenger, and Audience Network',
            'medium',
            ['platforms' => ['facebook', 'instagram', 'messenger', 'audience_network']]
        );
        
        // Check retargeting opportunities
        if ($this->hasPixelInstalled($context)) {
            $analysis->addOpportunity(
                'retargeting_available',
                'Facebook Pixel installed - can use website custom audiences for retargeting',
                'medium',
                ['pixel_installed' => true]
            );
        }
        
        return $analysis;
    }
    
    /**
     * Validate budget meets Facebook Ads requirements
     */
    protected function validateBudget(ExecutionContext $context): BudgetValidation
    {
        $dailyBudget = $context->calculateDailyBudget();
        
        // Facebook Ads minimum daily budget is $5.00 per ad set
        if ($dailyBudget < 5.0) {
            return BudgetValidation::invalid(
                $dailyBudget,
                ['minimum_daily_budget' => 5.0],
                [['code' => 'below_minimum', 'message' => 'Daily budget must be at least $5.00 per ad set for Facebook Ads']]
            );
        }
        
        // Warn if budget is low for Advantage+
        $warnings = [];
        if ($dailyBudget < 50.0) {
            $warnings[] = [
                'code' => 'advantage_plus_budget',
                'message' => 'Daily budget below $50.00 - Advantage+ campaigns not recommended'
            ];
        }
        
        return BudgetValidation::valid($dailyBudget, ['minimum_daily_budget' => 5.0], $warnings);
    }
    
    /**
     * Generate AI-powered execution plan for Facebook Ads
     */
    protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
    {
        $prompt = FacebookAdsExecutionPrompt::generate($context);
        $systemInstruction = FacebookAdsExecutionPrompt::getSystemInstruction();
        
        Log::info("FacebookAdsExecutionAgent: Generating execution plan for Campaign {$context->campaign->id}");
        
        try {
            // Use Google Search grounding for real-time API documentation access
            $response = $this->gemini->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                ],
                systemInstruction: $systemInstruction,
                enableThinking: true,
                enableGoogleSearch: true // Enable real-time grounding for current API best practices
            );
            
            if (!$response || !isset($response['text'])) {
                throw new \Exception('Empty response from AI model');
            }
            
            $plan = ExecutionPlan::fromJson($response['text']);
            
            Log::info("FacebookAdsExecutionAgent: Generated execution plan", [
                'campaign_id' => $context->campaign->id,
                'steps_count' => count($plan->steps),
                'objective' => $plan->getCampaignStructure()['objective'] ?? 'unknown'
            ]);
            
            return $plan;
            
        } catch (\Exception $e) {
            Log::error("FacebookAdsExecutionAgent: Failed to generate execution plan: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute the AI-generated deployment plan
     */
    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $this->initializeServices();
        
        $startTime = microtime(true);
        $result = ExecutionResult::success(platformIds: [], executionTime: 0.0, plan: $plan);
        $accountId = str_replace('act_', '', $this->customer->facebook_ads_account_id);
        $strategy = $context->strategy;
        $campaign = $context->campaign;
        
        Log::info("FacebookAdsExecutionAgent: Starting plan execution for Campaign {$campaign->id}");
        
        try {
            $campaignStructure = $plan->getCampaignStructure();
            $objective = $campaignStructure['objective'] ?? 'LINK_CLICKS';
            $creativeFormat = $plan->getCreativeStrategy()['ad_format'] ?? 'single_image';
            
            // 1. Create Facebook Campaign
            $fbCampaign = $this->createCampaign($accountId, $campaign, $strategy, $plan, $result);
            
            // 2. Create Ad Set with targeting
            $fbAdSet = $this->createAdSet($accountId, $fbCampaign['id'], $campaign, $strategy, $plan, $result);
            
            // 3. Upload creatives and create ads based on format
            switch ($creativeFormat) {
                case 'carousel':
                    $this->createCarouselAd($accountId, $fbAdSet['id'], $campaign, $strategy, $plan, $result);
                    break;
                    
                case 'video':
                    $this->createVideoAd($accountId, $fbAdSet['id'], $campaign, $strategy, $plan, $result);
                    break;
                    
                case 'single_image':
                default:
                    $this->createSingleImageAd($accountId, $fbAdSet['id'], $campaign, $strategy, $plan, $result);
                    break;
            }
            
            $result->executionTime = microtime(true) - $startTime;
            
            Log::info("FacebookAdsExecutionAgent: Successfully executed plan", [
                'campaign_id' => $campaign->id,
                'execution_time' => $result->executionTime,
                'platform_ids_count' => count($result->platformIds)
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("FacebookAdsExecutionAgent: Plan execution failed: " . $e->getMessage());
            
            $result = ExecutionResult::failure($plan, [$e->getMessage()]);
            $result->executionTime = microtime(true) - $startTime;
            
            return $result;
        }
    }
    
    /**
     * Create Facebook campaign
     */
    protected function createCampaign(
        string $accountId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): array {
        $campaignStructure = $plan->getCampaignStructure();
        $dailyBudgetCents = (int)(($campaignStructure['daily_budget'] ?? $campaign->total_budget / 30) * 100);
        
        $fbCampaign = $this->campaignService->createCampaign(
            $accountId,
            $campaign->name,
            $campaignStructure['objective'] ?? 'OUTCOME_TRAFFIC',
            $dailyBudgetCents,
            'PAUSED' // Start paused for review
        );
        
        if (!$fbCampaign || !isset($fbCampaign['id'])) {
            throw new \Exception('Failed to create Facebook campaign');
        }
        
        $result->addPlatformId('campaign', $fbCampaign['id']);
        $campaign->facebook_ads_campaign_id = $fbCampaign['id'];
        $campaign->save();
        
        $strategy->facebook_campaign_id = $fbCampaign['id'];
        $strategy->save();
        
        return $fbCampaign;
    }
    
    /**
     * Create Facebook ad set with targeting
     */
    protected function createAdSet(
        string $accountId,
        string $campaignId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): array {
        $campaignStructure = $plan->getCampaignStructure();
        $dailyBudgetCents = (int)(($campaignStructure['daily_budget'] ?? $campaign->total_budget / 30) * 100);
        
        // Build targeting from plan
        $targeting = $this->buildTargeting($plan);
        
        // Campaign uses CBO — budget is set at campaign level, not ad-set level.
        // optimization_goal drives how Facebook allocates impressions within the ad set.
        // billing_event is always IMPRESSIONS (handled inside AdSetService).
        $fbAdSet = $this->adSetService->createAdSet(
            $accountId,
            $campaignId,
            $campaign->name . ' - Ad Set',
            $targeting,
            $campaignStructure['optimization_goal'] ?? 'LANDING_PAGE_VIEWS',
            'PAUSED'
        );
        
        if (!$fbAdSet || !isset($fbAdSet['id'])) {
            throw new \Exception('Failed to create Facebook ad set');
        }
        
        $result->addPlatformId('ad_set', $fbAdSet['id']);
        $strategy->facebook_adset_id = $fbAdSet['id'];
        $strategy->save();
        
        return $fbAdSet;
    }
    
    /**
     * Create single image ad
     */
    protected function createSingleImageAd(
        string $accountId,
        string $adSetId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        $imageCollateral = $strategy->imageCollaterals()->where('is_active', true)->first();
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->first();
        
        if (!$imageCollateral || !$adCopy) {
            throw new \Exception('No image or ad copy available for Facebook ad');
        }
        
        $imageUrl = StorageHelper::url($imageCollateral->s3_path);
        
        $destinationUrl = $this->getDestinationUrl($campaign, $strategy);

        $fbCreative = $this->creativeService->createImageCreative(
            $accountId,
            $campaign->name . ' - Creative',
            $imageUrl,
            $adCopy->headlines[0] ?? 'Learn More',
            $adCopy->descriptions[0] ?? 'Discover our products and services',
            'LEARN_MORE',
            $destinationUrl
        );
        
        if (!$fbCreative || !isset($fbCreative['id'])) {
            throw new \Exception('Failed to create Facebook ad creative');
        }
        
        $result->addPlatformId('creative', $fbCreative['id']);
        $strategy->facebook_creative_id = $fbCreative['id'];
        $strategy->save();
        
        // Create ad
        $fbAd = $this->adService->createAd(
            $accountId,
            $adSetId,
            $campaign->name . ' - Ad',
            $fbCreative['id']
        );
        
        if (!$fbAd || !isset($fbAd['id'])) {
            throw new \Exception('Failed to create Facebook ad');
        }
        
        $result->addPlatformId('ad', $fbAd['id']);
        $strategy->facebook_ad_id = $fbAd['id'];
        $strategy->save();
    }
    
    /**
     * Create carousel ad with multiple images
     */
    protected function createCarouselAd(
        string $accountId,
        string $adSetId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->take(10)->get();
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->first();
        
        if ($imageCollaterals->count() < 2 || !$adCopy) {
            throw new \Exception('Need at least 2 images and ad copy for carousel ad');
        }
        
        // Build carousel cards
        $carouselCards = [];
        foreach ($imageCollaterals as $index => $image) {
            $carouselCards[] = [
                'picture' => StorageHelper::url($image->s3_path),
                'name' => $adCopy->headlines[$index] ?? $adCopy->headlines[0] ?? 'Learn More',
                'description' => $adCopy->descriptions[$index] ?? $adCopy->descriptions[0] ?? '',
                'link' => $this->getDestinationUrl($campaign, $strategy),
            ];
        }
        
        $fbCreative = $this->creativeService->createCarouselCreative(
            $accountId,
            $campaign->name . ' - Carousel Creative',
            $carouselCards,
            $this->getDestinationUrl($campaign, $strategy)
        );
        
        if (!$fbCreative || !isset($fbCreative['id'])) {
            throw new \Exception('Failed to create Facebook carousel creative');
        }
        
        $result->addPlatformId('creative', $fbCreative['id']);
        $strategy->facebook_creative_id = $fbCreative['id'];
        $strategy->save();
        
        // Create ad
        $fbAd = $this->adService->createAd(
            $accountId,
            $adSetId,
            $campaign->name . ' - Carousel Ad',
            $fbCreative['id']
        );
        
        if (!$fbAd || !isset($fbAd['id'])) {
            throw new \Exception('Failed to create Facebook ad');
        }
        
        $result->addPlatformId('ad', $fbAd['id']);
        $strategy->facebook_ad_id = $fbAd['id'];
        $strategy->save();
    }
    
    /**
     * Create video ad
     */
    protected function createVideoAd(
        string $accountId,
        string $adSetId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        $videoCollateral = $strategy->videoCollaterals()->where('is_active', true)->first();
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->first();
        
        if (!$videoCollateral || !$adCopy) {
            throw new \Exception('No video or ad copy available for Facebook video ad');
        }
        
        $videoUrl = StorageHelper::url($videoCollateral->s3_path);
        
        $fbCreative = $this->creativeService->createVideoCreative(
            $accountId,
            $campaign->name . ' - Video Creative',
            $videoUrl,
            $adCopy->headlines[0] ?? 'Watch Now',
            $adCopy->descriptions[0] ?? 'Discover our story',
            'LEARN_MORE',
            $this->getDestinationUrl($campaign, $strategy)
        );
        
        if (!$fbCreative || !isset($fbCreative['id'])) {
            throw new \Exception('Failed to create Facebook video creative');
        }
        
        $result->addPlatformId('creative', $fbCreative['id']);
        $strategy->facebook_creative_id = $fbCreative['id'];
        $strategy->save();
        
        // Create ad
        $fbAd = $this->adService->createAd(
            $accountId,
            $adSetId,
            $campaign->name . ' - Video Ad',
            $fbCreative['id']
        );
        
        if (!$fbAd || !isset($fbAd['id'])) {
            throw new \Exception('Failed to create Facebook ad');
        }
        
        $result->addPlatformId('ad', $fbAd['id']);
        $strategy->facebook_ad_id = $fbAd['id'];
        $strategy->save();
    }
    
    /**
     * Build targeting configuration from execution plan
     */
    protected function buildTargeting(ExecutionPlan $plan): array
    {
        $creativeStrategy = $plan->getCreativeStrategy();
        $targeting = $creativeStrategy['targeting'] ?? [];
        
        // Default targeting if not specified in plan
        $targetingConfig = [
            'geo_locations' => $targeting['geo_locations'] ?? [
                'countries' => ['US'],
            ],
            'age_min' => $targeting['age_min'] ?? 18,
            'age_max' => $targeting['age_max'] ?? 65,
            'genders' => $targeting['genders'] ?? [1, 2], // All genders
            'interests' => $targeting['interests'] ?? [],
            'behaviors' => $targeting['behaviors'] ?? [],
            'custom_audiences' => $targeting['custom_audiences'] ?? [],
        ];
        
        // Add placement targeting based on AI strategy
        $placementStrategy = $this->determinePlacementStrategy($plan, $creativeStrategy);
        if (!empty($placementStrategy)) {
            $targetingConfig['publisher_platforms'] = $placementStrategy['platforms'];
            if (!empty($placementStrategy['positions'])) {
                $targetingConfig['facebook_positions'] = $placementStrategy['positions']['facebook'] ?? [];
                $targetingConfig['instagram_positions'] = $placementStrategy['positions']['instagram'] ?? [];
            }
        }
        
        return $targetingConfig;
    }
    
    /**
     * Determine optimal placement strategy based on creative format and campaign objective.
     * AI-driven placement optimization for Facebook, Instagram, Messenger, and Audience Network.
     *
     * @param ExecutionPlan $plan
     * @param array $creativeStrategy
     * @return array
     */
    protected function determinePlacementStrategy(ExecutionPlan $plan, array $creativeStrategy): array
    {
        $adFormat = $creativeStrategy['ad_format'] ?? 'single_image';
        $objective = $plan->getCampaignStructure()['objective'] ?? 'LINK_CLICKS';
        
        // Default: Advantage+ Placements (let Meta optimize)
        // This is recommended for most campaigns as Meta's algorithm can optimize delivery
        $useAutomaticPlacements = $creativeStrategy['use_automatic_placements'] ?? true;
        
        if ($useAutomaticPlacements) {
            return [
                'platforms' => ['facebook', 'instagram', 'audience_network', 'messenger'],
                'automatic' => true,
                'positions' => [], // Let Meta optimize
            ];
        }
        
        // Manual placement selection based on creative format
        $placements = [
            'platforms' => [],
            'automatic' => false,
            'positions' => [
                'facebook' => [],
                'instagram' => [],
            ],
        ];
        
        switch ($adFormat) {
            case 'video':
                // Video ads perform best on:
                // - Facebook Feed, In-Stream, Stories, Reels
                // - Instagram Feed, Stories, Reels, Explore
                $placements['platforms'] = ['facebook', 'instagram'];
                $placements['positions'] = [
                    'facebook' => ['feed', 'video_feeds', 'story', 'reels'],
                    'instagram' => ['stream', 'story', 'reels', 'explore'],
                ];
                break;
                
            case 'carousel':
                // Carousel ads work well on:
                // - Facebook Feed
                // - Instagram Feed
                // - Marketplace (for e-commerce)
                $placements['platforms'] = ['facebook', 'instagram'];
                $placements['positions'] = [
                    'facebook' => ['feed', 'marketplace'],
                    'instagram' => ['stream', 'explore'],
                ];
                break;
                
            case 'stories':
                // Stories-specific creative
                $placements['platforms'] = ['facebook', 'instagram'];
                $placements['positions'] = [
                    'facebook' => ['story'],
                    'instagram' => ['story', 'reels'],
                ];
                break;
                
            case 'reels':
                // Reels-specific creative
                $placements['platforms'] = ['facebook', 'instagram'];
                $placements['positions'] = [
                    'facebook' => ['reels'],
                    'instagram' => ['reels'],
                ];
                break;
                
            case 'single_image':
            default:
                // Single image works across all placements
                // Optimize based on objective
                $placements['platforms'] = ['facebook', 'instagram'];
                
                if (in_array($objective, ['CONVERSIONS', 'SALES'])) {
                    // Conversions: Focus on Feed for higher intent
                    $placements['positions'] = [
                        'facebook' => ['feed', 'marketplace', 'right_hand_column'],
                        'instagram' => ['stream', 'explore'],
                    ];
                } elseif (in_array($objective, ['REACH', 'BRAND_AWARENESS'])) {
                    // Reach/Awareness: Broader placements
                    $placements['platforms'][] = 'audience_network';
                    $placements['positions'] = [
                        'facebook' => ['feed', 'story', 'video_feeds'],
                        'instagram' => ['stream', 'story', 'explore'],
                    ];
                } else {
                    // Default: Feed-focused for traffic/engagement
                    $placements['positions'] = [
                        'facebook' => ['feed'],
                        'instagram' => ['stream'],
                    ];
                }
                break;
        }
        
        Log::info('FacebookAdsExecutionAgent: Determined placement strategy', [
            'ad_format' => $adFormat,
            'objective' => $objective,
            'platforms' => $placements['platforms'],
            'automatic' => $placements['automatic'],
        ]);
        
        return $placements;
    }
    
    /**
     * Handle execution errors with AI-powered recovery
     */
    protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
    {
        Log::error("FacebookAdsExecutionAgent: Execution error - " . $error->getMessage(), [
            'campaign_id' => $context->campaign->id,
            'customer_id' => $this->customer->id
        ]);
        
        // Generate AI-powered recovery plan
        $recoveryPrompt = $this->buildRecoveryPrompt($error, $context);
        
        try {
            $response = $this->geminiService->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $recoveryPrompt,
                config: ['temperature' => 0.3, 'maxOutputTokens' => 2048],
                systemInstruction: 'You are an expert at diagnosing and recovering from Facebook/Meta Ads API errors. Provide specific, actionable recovery steps.'
            );
            
            if ($response && isset($response['text'])) {
                return RecoveryPlan::fromJson($response['text']);
            }
        } catch (\Exception $e) {
            Log::error("FacebookAdsExecutionAgent: Failed to generate recovery plan: " . $e->getMessage());
        }
        
        // Fallback to simple recovery plan
        return RecoveryPlan::simple($error->getMessage(), [
            'Check Facebook Ads account connection',
            'Verify Facebook Page is connected',
            'Ensure access token has required permissions',
            'Check payment method is valid',
            'Review creative assets and ad copy',
            'Verify targeting settings are valid'
        ]);
    }
    
    /**
     * Build recovery prompt for AI
     */
    protected function buildRecoveryPrompt(\Exception $error, ExecutionContext $context): string
    {
        return <<<PROMPT
You are troubleshooting a Facebook/Meta Ads deployment error. Analyze the error and provide recovery actions.

Error Message: {$error->getMessage()}

Campaign Context:
- Campaign ID: {$context->campaign->id}
- Campaign Name: {$context->campaign->name}
- Platform: Facebook/Meta Ads
- Ad Account ID: {$this->customer->facebook_ads_account_id}
- Facebook Page ID: {$this->customer->facebook_page_id}
- Daily Budget: \${$context->calculateDailyBudget()}

Available Assets:
- Ad Copy: {$context->hasAssetType('ad_copy')}
- Images: {$context->hasAssetType('image')}
- Videos: {$context->hasAssetType('video')}

Common Facebook Ads errors:
- Audience too narrow (< 50,000 reach)
- Creative rejection/review pending
- Targeting overlap warnings
- Budget too low ($5 minimum per ad set)
- Page access issues
- Pixel configuration errors

Provide a JSON response with:
{
    "error_type": "string (authentication|budget|creative|targeting|pixel|permissions|configuration)",
    "recovery_actions": ["action1", "action2"],
    "reasoning": "explanation of the error and recovery approach"
}
PROMPT;
    }
    
    /**
     * Check if Facebook Pixel is installed
     */
    protected function hasPixelInstalled(ExecutionContext $context): bool
    {
        // TODO: Implement actual Pixel check via Facebook API
        // For now, return false to be conservative
        return false;
    }
    
    /**
     * Check if Pixel has conversion data
     */
    protected function hasPixelWithConversions(ExecutionContext $context): bool
    {
        // TODO: Implement actual Pixel conversion data check via Facebook API
        // For now, return false to be conservative
        return false;
    }
    
    /**
     * Get the platform name for this agent
     */
    protected function getPlatformName(): string
    {
        return 'Facebook Ads';
    }

    /**
     * Append UTM parameters to a URL for attribution tracking.
     */
    protected function appendUtmParameters(string $url, Campaign $campaign, Strategy $strategy): string
    {
        $params = [
            'utm_source' => 'facebook',
            'utm_medium' => $strategy->campaign_type ?? 'social',
            'utm_campaign' => 'spectra_' . $campaign->id,
            'utm_content' => 'strategy_' . $strategy->id,
        ];

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params);
    }

    /**
     * Get destination URL with UTM parameters appended.
     */
    protected function getDestinationUrl(Campaign $campaign, Strategy $strategy): string
    {
        $url = $campaign->landing_page_url ?? $campaign->customer?->website ?? '';
        if (empty($url)) {
            return '';
        }
        return $this->appendUtmParameters($url, $campaign, $strategy);
    }
}
