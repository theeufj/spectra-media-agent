<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Prompts\GoogleAdsExecutionPrompt;
use App\Services\GeminiService;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\AddCampaignCriterion;
use App\Services\GoogleAds\CommonServices\LinkAdGroupAsset;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use App\Services\GoogleAds\CommonServices\CreateTextAsset;
use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroup;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use App\Services\GoogleAds\CreateManagedAccount;
use App\Services\GoogleAds\CreateCustomerClientLink;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroupWithAssets;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\VideoServices\CreateVideoCampaign;
use App\Services\GoogleAds\VideoServices\CreateVideoAdGroup;
use App\Services\GoogleAds\VideoServices\CreateResponsiveVideoAd;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GTM\GTMContainerService;
use App\Services\Agents\Traits\RetryableApiOperation;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Google Ads Execution Agent
 * 
 * AI-powered execution agent for Google Ads platform that dynamically generates
 * and executes deployment plans based on available assets, budget, and platform capabilities.
 * 
 * Features:
 * - Dynamic campaign type selection (Search, Display, Performance Max)
 * - AI-driven execution planning with Google Search grounding
 * - Budget validation and allocation
 * - Smart Bidding strategy recommendation
 * - Asset optimization analysis
 * - Intelligent error recovery
 */
class GoogleAdsExecutionAgent extends PlatformExecutionAgent
{
    use RetryableApiOperation;
    
    protected string $platform = 'google';
    
    /**
     * Execute the deployment with ExecutionContext
     */
    public function execute(ExecutionContext $context): ExecutionResult
    {
        Log::info("GoogleAdsExecutionAgent: Starting execution", [
            'campaign_id' => $context->campaign->id,
            'strategy_id' => $context->strategy->id,
        ]);
        
        // Validate prerequisites
        $validation = $this->validatePrerequisites($context);
        if (!$validation->passes()) {
            Log::error("GoogleAdsExecutionAgent: Prerequisites validation failed", [
                'errors' => $validation->errors
            ]);
            return ExecutionResult::failure($validation->errors);
        }
        
        // Analyze optimization opportunities
        $optimization = $this->analyzeOptimizationOpportunities($context);
        
        // Generate execution plan
        $plan = $this->generateExecutionPlan($context);
        
        // Execute the plan
        $result = $this->executePlan($plan, $context);
        
        Log::info("GoogleAdsExecutionAgent: Execution completed", [
            'campaign_id' => $context->campaign->id,
            'success' => $result->success,
            'execution_time' => $result->executionTime
        ]);
        
        return $result;
    }
    
    /**
     * Validate prerequisites before deployment
     * 
     * Checks:
     * - Google Ads account connection
     * - Customer ID validity
     * - Conversion tracking setup
     * - Available creative assets
     * - Ad copy availability
     * - Budget meets minimum requirements
     */
    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $result = new ValidationResult(true);
        
        // Check if customer has Google Ads refresh token (OAuth authorization)
        if (!$this->customer->google_ads_refresh_token) {
            $result->addError('google_ads_not_authorized', 'Google Ads account not authorized - please connect your Google Ads account');
            return $result;
        }
        
        // Auto-create sub-account if customer doesn't have one
        if (!$this->customer->google_ads_customer_id) {
            Log::info("GoogleAdsExecutionAgent: Customer has no Google Ads account, attempting to create sub-account", [
                'customer_id' => $this->customer->id,
            ]);
            
            $created = $this->createSubAccount();
            if (!$created) {
                $result->addError('google_ads_subaccount_creation_failed', 'Failed to create Google Ads sub-account - please check MCC configuration');
                return $result;
            }
            
            Log::info("GoogleAdsExecutionAgent: Successfully created sub-account", [
                'customer_id' => $this->customer->id,
                'google_ads_customer_id' => $this->customer->google_ads_customer_id,
            ]);
        }
        
        // Check conversion tracking (warning only - campaigns can run without it)
        if (!$this->hasConversionTracking($context)) {
            $result->addWarning('no_conversion_tracking', 'No conversion tracking configured - Smart Bidding will be limited');
        }
        
        // Validate creative assets
        $strategy = $context->strategy;
        $hasImages = $strategy->imageCollaterals()->where('is_active', true)->exists();
        $hasAdCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->exists();
        
        if (!$hasAdCopy) {
            $result->addError('no_ad_copy', 'No ad copy available for Google Ads');
        }
        
        if (!$hasImages) {
            $result->addWarning('no_images', 'No images available - Display and Performance Max campaigns will be limited');
        }
        
        // Validate budget meets Google Ads minimums
        $budgetValidation = $this->validateBudget($context);
        if (!$budgetValidation->passes()) {
            foreach ($budgetValidation->errors as $error) {
                $result->addError('budget_' . $error['code'], $error['message']);
            }
        }
        
        return $result;
    }
    
    /**
     * Analyze optimization opportunities for Google Ads
     * 
     * Evaluates:
     * - Performance Max eligibility (multiple assets + conversion tracking + $250 min budget)
     * - Smart Bidding eligibility (conversion count and quality)
     * - Customer Match opportunities
     * - Keyword expansion opportunities
     * - Responsive Search Ad optimization
     * - Ad extension opportunities
     */
    protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
    {
        $analysis = new OptimizationAnalysis();
        $strategy = $context->strategy;
        $campaign = $context->campaign;
        
        // Check Performance Max eligibility
        $hasMultipleAssets = $strategy->imageCollaterals()->where('is_active', true)->count() >= 3
            && $strategy->videoCollaterals()->where('is_active', true)->count() >= 1;
        $hasConversionTracking = $this->hasConversionTracking($context);
        $meetsPerformanceMaxBudget = $context->calculateDailyBudget() >= 8.33; // ~$250/month minimum
        
        if ($hasMultipleAssets && $hasConversionTracking && $meetsPerformanceMaxBudget) {
            $analysis->addOpportunity(
                'performance_max_eligible',
                'Campaign is eligible for Performance Max - recommended for best performance',
                'high',
                ['multiple_assets' => true, 'conversion_tracking' => true, 'budget_meets_minimum' => true]
            );
        }
        
        // Check Smart Bidding eligibility
        $conversionCount = $this->getConversionCount($context);
        if ($conversionCount >= 30) {
            $analysis->addOpportunity(
                'smart_bidding_target_roas',
                'Sufficient conversion data for Target ROAS bidding strategy',
                'high',
                ['conversion_count' => $conversionCount]
            );
        } elseif ($conversionCount >= 15) {
            $analysis->addOpportunity(
                'smart_bidding_target_cpa',
                'Sufficient conversion data for Target CPA bidding strategy',
                'medium',
                ['conversion_count' => $conversionCount]
            );
        }
        
        // Check for keyword opportunities
        if ($strategy->bidding_strategy && isset($strategy->bidding_strategy['keywords'])) {
            $keywordCount = count($strategy->bidding_strategy['keywords']);
            if ($keywordCount < 10) {
                $analysis->addOpportunity(
                    'keyword_expansion',
                    'Limited keywords detected - consider expanding keyword list for better reach',
                    'medium',
                    ['current_keyword_count' => $keywordCount]
                );
            }
        }
        
        // Check ad extension opportunities
        $analysis->addOpportunity(
            'ad_extensions',
            'Add sitelink, callout, and structured snippet extensions to improve ad visibility',
            'medium',
            ['business_name' => $campaign->name, 'website' => $campaign->landing_page_url]
        );
        
        return $analysis;
    }
    
    /**
     * Validate budget meets Google Ads requirements
     */
    protected function validateBudget(ExecutionContext $context): BudgetValidation
    {
        $dailyBudget = $context->calculateDailyBudget();
        
        // Google Ads minimum daily budget is typically $1
        if ($dailyBudget < 1.0) {
            return BudgetValidation::invalid(
                $dailyBudget,
                ['minimum_daily_budget' => 1.0],
                [['code' => 'below_minimum', 'message' => 'Daily budget must be at least $1.00 for Google Ads']]
            );
        }
        
        // Warn if budget is low for Performance Max
        $warnings = [];
        if ($dailyBudget < 8.33) {
            $warnings[] = [
                'code' => 'performance_max_budget',
                'message' => 'Daily budget below $8.33 (~$250/month) - Performance Max campaigns not recommended'
            ];
        }
        
        return BudgetValidation::valid($dailyBudget, ['minimum_daily_budget' => 1.0], $warnings);
    }
    
    /**
     * Generate AI-powered execution plan for Google Ads
     */
    protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
    {
        $prompt = GoogleAdsExecutionPrompt::generate($context);
        $systemInstruction = GoogleAdsExecutionPrompt::getSystemInstruction();
        
        Log::info("GoogleAdsExecutionAgent: Generating execution plan for Campaign {$context->campaign->id}");
        
        try {
            // Use Google Search grounding for real-time API documentation access
            $response = $this->gemini->generateContent(
                model: 'gemini-2.5-pro',
                prompt: $prompt,
                config: [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                ],
                systemInstruction: $systemInstruction,
                enableGoogleSearch: true // Enable real-time grounding for current API best practices
            );
            
            if (!$response || !isset($response['text'])) {
                throw new \Exception('Empty response from AI model');
            }
            
            // Log the raw response for debugging
            Log::debug("GoogleAdsExecutionAgent: Raw AI response", [
                'response_length' => strlen($response['text']),
                'response_preview' => substr($response['text'], 0, 500)
            ]);
            
            $plan = ExecutionPlan::fromJson($response['text']);
            
            Log::info("GoogleAdsExecutionAgent: Generated execution plan", [
                'campaign_id' => $context->campaign->id,
                'steps_count' => count($plan->steps),
                'campaign_type' => $plan->getCampaignStructure()['type'] ?? 'unknown'
            ]);
            
            return $plan;
            
        } catch (\Exception $e) {
            Log::error("GoogleAdsExecutionAgent: Failed to generate execution plan: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute the AI-generated deployment plan
     */
    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $startTime = microtime(true);
        $result = ExecutionResult::success(platformIds: [], executionTime: 0.0, plan: $plan);
        $customerId = $this->customer->google_ads_customer_id;
        $strategy = $context->strategy;
        $campaign = $context->campaign;
        
        Log::info("GoogleAdsExecutionAgent: Starting plan execution for Campaign {$campaign->id}");
        
        try {
            // Setup Conversion Tracking (Best Effort)
            $this->setupConversionTracking($customerId, $result);

            $campaignStructure = $plan->getCampaignStructure();
            $campaignType = $campaignStructure['type'] ?? 'search';
            
            // Execute based on campaign type
            switch ($campaignType) {
                case 'search':
                    $this->executeSearchCampaign($customerId, $campaign, $strategy, $plan, $result);
                    break;
                    
                case 'display':
                    $this->executeDisplayCampaign($customerId, $campaign, $strategy, $plan, $result);
                    break;
                    
                case 'performance_max':
                    $this->executePerformanceMaxCampaign($customerId, $campaign, $strategy, $plan, $result);
                    break;
                    
                case 'video':
                    $this->executeVideoCampaign($customerId, $campaign, $strategy, $plan, $result);
                    break;
                    
                default:
                    throw new \Exception("Unsupported campaign type: {$campaignType}");
            }
            
            $result->executionTime = microtime(true) - $startTime;
            
            Log::info("GoogleAdsExecutionAgent: Successfully executed plan", [
                'campaign_id' => $campaign->id,
                'execution_time' => $result->executionTime,
                'platform_ids_count' => count($result->platformIds)
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("GoogleAdsExecutionAgent: Plan execution failed: " . $e->getMessage());
            
            $result = ExecutionResult::failure([$e->getMessage()]);
            $result->plan = $plan;
            $result->executionTime = microtime(true) - $startTime;
            
            return $result;
        }
    }
    
    /**
     * Execute Search campaign deployment
     */
    protected function executeSearchCampaign(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Verify we are targeting the correct sub-account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning("GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored sub-account ID", [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info("GoogleAdsExecutionAgent: Creating Search Campaign in sub-account", [
            'customer_id' => $customerId
        ]);

        // 1. Create Campaign
        $createCampaignService = new CreateSearchCampaign($this->customer, true);
        $campaignStructure = $plan->getCampaignStructure();
        
        $timestamp = now()->format('Ymd_His');
        $campaignName = $campaign->name . ' - ' . $timestamp;

        $campaignData = [
            'businessName' => $campaignName,
            'budget' => $campaignStructure['daily_budget'] ?? $campaign->total_budget / 30,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ];
        
        $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
        if (!$campaignResourceName) {
            throw new \Exception('Failed to create search campaign');
        }
        
        $result->addPlatformId('campaign', $campaignResourceName);
        $campaign->google_ads_campaign_id = $campaignResourceName;
        $campaign->save();

        // 1.5 Add Location Targeting
        $this->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);
        
        // 2. Create Ad Group
        $createAdGroupService = new CreateSearchAdGroup($this->customer, true);
        $adGroupName = 'Default Ad Group - ' . $timestamp;
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
        if (!$adGroupResourceName) {
            throw new \Exception('Failed to create search ad group');
        }
        
        $result->addPlatformId('ad_group', $adGroupResourceName);
        $strategy->google_ads_ad_group_id = $adGroupResourceName;
        $strategy->save();
        
        // 3. Add Keywords from campaign, strategy targeting config, or execution plan
        $keywords = $this->getKeywords($campaign, $strategy, $plan);
        if (!empty($keywords)) {
            $this->addKeywords($customerId, $adGroupResourceName, $keywords, $result);
        }

        // 3.5 Add Audience Targeting
        $this->addAudienceTargeting($customerId, $adGroupResourceName, $strategy, $result);
        
        // 4. Upload Image Assets for Responsive Search Ad (if available)
        $imageAssetResourceNames = [];
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->limit(15)->get();
        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer, true);
            $linkAdGroupAssetService = new LinkAdGroupAsset($this->customer, true);

            foreach ($imageCollaterals as $image) {
                try {
                    $imageData = Storage::disk('s3')->get($image->s3_path);
                    $assetResourceName = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
                    if ($assetResourceName) {
                        $imageAssetResourceNames[] = $assetResourceName;
                        $result->addPlatformId('image_asset', $assetResourceName);

                        // Link the asset to the Ad Group (Image Extension)
                        $linkResourceName = ($linkAdGroupAssetService)($customerId, $adGroupResourceName, $assetResourceName, AssetFieldType::AD_IMAGE);
                        if ($linkResourceName) {
                            $result->addPlatformId('ad_group_asset', $linkResourceName);
                            Log::info("GoogleAdsExecutionAgent: Linked image asset to ad group", [
                                'asset' => $assetResourceName,
                                'ad_group' => $adGroupResourceName
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $result->addWarning("Failed to upload/link image asset {$image->s3_path}: " . $e->getMessage());
                }
            }
        }
        
        // 5. Create Responsive Search Ad
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if ($adCopy) {
            $finalUrl = $this->getFinalUrl($campaign, $strategy, $plan);
            
            if (!$finalUrl) {
                $result->addWarning('No landing page URL found for ad creation. Skipping ad creation.');
            } else {
                $createAdService = new CreateResponsiveSearchAd($this->customer, true);
                $adData = [
                    'finalUrls' => [$finalUrl],
                    'headlines' => $adCopy->headlines ?? [],
                    'descriptions' => $adCopy->descriptions ?? [],
                    'imageAssets' => $imageAssetResourceNames, // Add images to RSA
                ];
                
                $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                if ($adResourceName) {
                    $result->addPlatformId('ad', $adResourceName);
                }
            }
        }

        // 6. Add Ad Extensions (Sitelinks, Callouts)
        $this->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }
    
    /**
     * Execute Display campaign deployment
     */
    protected function executeDisplayCampaign(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Verify we are targeting the correct sub-account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning("GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored sub-account ID", [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info("GoogleAdsExecutionAgent: Creating Display Campaign in sub-account", [
            'customer_id' => $customerId
        ]);

        // 1. Create Campaign
        $createCampaignService = new CreateDisplayCampaign($this->customer, true);
        $campaignStructure = $plan->getCampaignStructure();
        
        $timestamp = now()->format('Ymd_His');
        $campaignName = $campaign->name . ' - ' . $timestamp;

        $campaignData = [
            'businessName' => $campaignName,
            'budget' => $campaignStructure['daily_budget'] ?? $campaign->total_budget / 30,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ];
        
        $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
        if (!$campaignResourceName) {
            throw new \Exception('Failed to create display campaign');
        }
        
        $result->addPlatformId('campaign', $campaignResourceName);
        $campaign->google_ads_campaign_id = $campaignResourceName;
        $campaign->save();

        // 1.5 Add Location Targeting
        $this->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);
        
        // 2. Create Ad Group
        $createAdGroupService = new CreateDisplayAdGroup($this->customer, true);
        $adGroupName = 'Default Ad Group - ' . $timestamp;
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
        if (!$adGroupResourceName) {
            throw new \Exception('Failed to create display ad group');
        }
        
        $result->addPlatformId('ad_group', $adGroupResourceName);
        $strategy->google_ads_ad_group_id = $adGroupResourceName;
        $strategy->save();
        
        // 3. Upload Image Assets
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->get();
        $imageAssetResourceNames = [];
        
        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer, true);
            foreach ($imageCollaterals as $image) {
                try {
                    $imageData = Storage::disk('s3')->get($image->s3_path);
                    $assetResourceName = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
                    if ($assetResourceName) {
                        $imageAssetResourceNames[] = $assetResourceName;
                        $result->addPlatformId('image_asset', $assetResourceName);
                    }
                } catch (\Exception $e) {
                    $result->addWarning("Failed to upload image asset {$image->s3_path}: " . $e->getMessage());
                }
            }
        }
        
        // 4. Create Responsive Display Ad
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if ($adCopy && !empty($imageAssetResourceNames)) {
            $finalUrl = $this->getFinalUrl($campaign, $strategy, $plan);
            
            if (!$finalUrl) {
                $result->addWarning('No landing page URL found for display ad creation. Skipping ad creation.');
            } else {
                $createAdService = new CreateResponsiveDisplayAd($this->customer, true);
                $adData = [
                    'finalUrls' => [$finalUrl],
                    'headlines' => $adCopy->headlines ?? [],
                    'longHeadlines' => [$adCopy->headlines[0] ?? 'Get Started Today'],
                    'descriptions' => $adCopy->descriptions ?? [],
                    'imageAssets' => $imageAssetResourceNames,
                ];
                
                $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                if ($adResourceName) {
                    $result->addPlatformId('ad', $adResourceName);
                }
            }
        }

        // 5. Add Ad Extensions
        // Note: Display campaigns support fewer extensions, but Sitelinks/Callouts are often compatible.
        $this->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }
    
    /**
     * Execute Performance Max campaign deployment
     */
    protected function executePerformanceMaxCampaign(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Verify we are targeting the correct sub-account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning("GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored sub-account ID", [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info("GoogleAdsExecutionAgent: Creating Performance Max Campaign in sub-account", [
            'customer_id' => $customerId
        ]);

        // 1. Create Campaign
        $createCampaignService = new CreatePerformanceMaxCampaign($this->customer, true);
        $timestamp = now()->format('Ymd_His');
        $campaignName = $campaign->name . ' - PMax - ' . $timestamp;

        $campaignData = [
            'businessName' => $campaignName,
            'budget' => $campaign->total_budget / 30, // Daily budget
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
            'targetCpaMicros' => $strategy->cpa_target ?? null,
        ];

        $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
        if (!$campaignResourceName) {
            throw new \Exception('Failed to create Performance Max campaign');
        }

        $result->addPlatformId('campaign', $campaignResourceName);
        $campaign->google_ads_campaign_id = $campaignResourceName;
        $campaign->save();

        // 1.5 Add Location Targeting
        $this->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Prepare Assets
        $assets = [];
        $createTextAssetService = new CreateTextAsset($this->customer, true);
        $uploadImageService = new UploadImageAsset($this->customer, true);

        // 2.1 Text Assets
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        
        if ($adCopy) {
            // Headlines (Min 3, Max 5)
            $headlines = array_slice($adCopy->headlines ?? [], 0, 5);
            foreach ($headlines as $headline) {
                $assetResourceName = ($createTextAssetService)($customerId, $headline);
                if ($assetResourceName) {
                    $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::HEADLINE];
                }
            }

            // Long Headlines (Min 1, Max 5)
            $longHeadline = $headlines[0] ?? 'Discover Our Amazing Products'; 
            $assetResourceName = ($createTextAssetService)($customerId, $longHeadline);
            if ($assetResourceName) {
                $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::LONG_HEADLINE];
            }

            // Descriptions (Min 2, Max 5)
            $descriptions = array_slice($adCopy->descriptions ?? [], 0, 5);
            foreach ($descriptions as $description) {
                $assetResourceName = ($createTextAssetService)($customerId, $description);
                if ($assetResourceName) {
                    $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::DESCRIPTION];
                }
            }

            // Business Name (Min 1, Max 1)
            $businessName = $campaignData['businessName'] ?? 'ShopFree';
            $assetResourceName = ($createTextAssetService)($customerId, $businessName);
            if ($assetResourceName) {
                $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::BUSINESS_NAME];
            }
        }

        // 2.2 Image Assets
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->limit(15)->get();
        $hasLogo = false;
        
        foreach ($imageCollaterals as $image) {
            try {
                $imageData = Storage::disk('s3')->get($image->s3_path);
                $assetResourceName = ($uploadImageService)($customerId, $imageData, $image->s3_path);
                
                if ($assetResourceName) {
                    // Simple heuristic: if filename contains 'logo', treat as logo
                    if (str_contains(strtolower($image->s3_path), 'logo')) {
                        $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::LOGO];
                        $hasLogo = true;
                    } else {
                        // Link as MARKETING_IMAGE (Landscape)
                        $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::MARKETING_IMAGE];
                        
                        // Link as SQUARE_MARKETING_IMAGE (Square)
                        $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::SQUARE_MARKETING_IMAGE];
                    }
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to upload/link image asset {$image->s3_path}: " . $e->getMessage());
            }
        }

        // Ensure we have at least one logo (required for PMax)
        if (!$hasLogo && !empty($assets)) {
            // If no explicit logo found, use the first available image as a logo too (fallback)
            // This might fail if dimensions are wrong, but better than guaranteed failure.
            $firstAsset = $assets[0]['asset'];
            $assets[] = ['asset' => $firstAsset, 'field_type' => AssetFieldType::LOGO];
            Log::warning("GoogleAdsExecutionAgent: No explicit logo found, using first image as logo fallback.");
        }

        // 3. Create Asset Group with Assets
        $createAssetGroupService = new CreateAssetGroupWithAssets($this->customer, true);
        $assetGroupName = 'Asset Group - ' . $timestamp;
        $finalUrl = $this->getFinalUrl($campaign, $strategy, $plan);
        
        if (!$finalUrl) {
            throw new \Exception('No landing page URL found for Asset Group creation.');
        }

        $assetGroupResourceName = ($createAssetGroupService)($customerId, $campaignResourceName, $assetGroupName, [$finalUrl], $assets);
        if (!$assetGroupResourceName) {
            throw new \Exception('Failed to create Asset Group');
        }

        $result->addPlatformId('asset_group', $assetGroupResourceName);

        // 4. Add Ad Extensions (Sitelinks, Callouts) - PMax can use campaign-level assets
        $this->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }

    /**
     * Execute Video campaign deployment
     */
    protected function executeVideoCampaign(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Verify we are targeting the correct sub-account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info("GoogleAdsExecutionAgent: Creating Video Campaign in sub-account", [
            'customer_id' => $customerId
        ]);

        // 1. Create Campaign
        $createCampaignService = new CreateVideoCampaign($this->customer);
        $campaignStructure = $plan->getCampaignStructure();
        
        $timestamp = now()->format('Ymd_His');
        $campaignName = $campaign->name . ' - Video - ' . $timestamp;

        $campaignData = [
            'businessName' => $campaignName,
            'budget' => $campaignStructure['daily_budget'] ?? $campaign->total_budget / 30,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ];
        
        $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
        if (!$campaignResourceName) {
            throw new \Exception('Failed to create video campaign');
        }
        
        $result->addPlatformId('campaign', $campaignResourceName);
        $campaign->google_ads_campaign_id = $campaignResourceName;
        $campaign->save();

        // 1.5 Add Location Targeting
        $this->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);
        
        // 2. Create Ad Group
        $createAdGroupService = new CreateVideoAdGroup($this->customer);
        $adGroupName = 'Video Ad Group - ' . $timestamp;
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
        if (!$adGroupResourceName) {
            throw new \Exception('Failed to create video ad group');
        }
        
        $result->addPlatformId('ad_group', $adGroupResourceName);

        // 3. Create Video Ad
        // Note: Video ads require a YouTube Video ID. 
        // We assume the strategy or ad copy provides this, or we use a placeholder.
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%youtube%'])->first();
        
        if ($adCopy && !empty($adCopy->video_id)) {
            $createAdService = new CreateResponsiveVideoAd($this->customer);
            
            $adData = [
                'videoId' => $adCopy->video_id,
                'headline' => $adCopy->headlines[0] ?? 'Watch Now',
                'longHeadline' => $adCopy->headlines[1] ?? 'Discover More',
                'description' => $adCopy->descriptions[0] ?? 'Click to learn more',
                'callToAction' => 'WATCH_NOW',
            ];
            
            $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
            if ($adResourceName) {
                $result->addPlatformId('ad', $adResourceName);
            }
        } else {
            $result->addWarning("No YouTube Video ID found in Ad Copy. Skipping Video Ad creation.");
        }

        // 4. Add Ad Extensions
        $this->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }
    
    /**
     * Create and link assets for Performance Max
     * @deprecated Replaced by inline logic in executePerformanceMaxCampaign using CreateAssetGroupWithAssets
     */
    protected function createAndLinkPMaxAssets(
        string $customerId, 
        string $assetGroupResourceName, 
        Strategy $strategy, 
        ExecutionResult $result
    ): void {
        // Deprecated
    }
    
    /**
     * Get keywords from campaign, targeting config, or execution plan
     */
    protected function getKeywords(Campaign $campaign, Strategy $strategy, ExecutionPlan $plan): array
    {
        $keywords = [];
        
        // 1. Check campaign-level keywords (highest priority)
        if (!empty($campaign->keywords)) {
            $keywords = $campaign->keywords;
            Log::info("GoogleAdsExecutionAgent: Using campaign keywords", ['count' => count($keywords)]);
            return $keywords;
        }
        
        // 2. Check targeting config keywords
        $targetingConfig = $strategy->targetingConfig;
        if ($targetingConfig && isset($targetingConfig->google_options['keywords']) && !empty($targetingConfig->google_options['keywords'])) {
            $keywords = $targetingConfig->google_options['keywords'];
            Log::info("GoogleAdsExecutionAgent: Using targeting config keywords", ['count' => count($keywords)]);
            return $keywords;
        }
        
        // 3. Check execution plan keywords (AI-generated)
        $creativeStrategy = $plan->getCreativeStrategy();
        if (isset($creativeStrategy['keywords']) && !empty($creativeStrategy['keywords'])) {
            $keywords = $creativeStrategy['keywords'];
            Log::info("GoogleAdsExecutionAgent: Using execution plan keywords", ['count' => count($keywords)]);
            return $keywords;
        }
        
        Log::warning("GoogleAdsExecutionAgent: No keywords found for campaign");
        return [];
    }
    
    /**
     * Add keywords to ad group
     */
    protected function addKeywords(string $customerId, string $adGroupResourceName, array $keywords, ExecutionResult $result): void
    {
        $addCriterionService = new AddAdGroupCriterion($this->customer, true);
        
        foreach ($keywords as $keyword) {
            try {
                $keywordText = is_array($keyword) ? ($keyword['text'] ?? $keyword['keyword'] ?? '') : $keyword;
                $matchType = is_array($keyword) && isset($keyword['match_type']) 
                    ? $keyword['match_type'] 
                    : 'BROAD';
                
                if (empty($keywordText)) {
                    continue;
                }
                
                $criterionResourceName = ($addCriterionService)($customerId, $adGroupResourceName, [
                    'type' => 'KEYWORD',
                    'text' => $keywordText,
                    'matchType' => $matchType
                ]);
                if ($criterionResourceName) {
                    $result->addPlatformId('keyword', $criterionResourceName);
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to add keyword: " . $e->getMessage());
            }
        }
    }

    /**
     * Add audience targeting to ad group
     */
    protected function addAudienceTargeting(string $customerId, string $adGroupResourceName, Strategy $strategy, ExecutionResult $result): void
    {
        $targetingConfig = $strategy->targetingConfig;
        if (!$targetingConfig) {
            return;
        }

        $searchAudienceService = new SearchAudience($this->customer, true);
        $addCriterionService = new AddAdGroupCriterion($this->customer, true);

        $audiences = [];
        // Merge interests and behaviors
        if (!empty($targetingConfig->interests)) {
            $audiences = array_merge($audiences, $targetingConfig->interests);
        }
        if (!empty($targetingConfig->behaviors)) {
            $audiences = array_merge($audiences, $targetingConfig->behaviors);
        }

        foreach ($audiences as $audienceKeyword) {
            try {
                // Search for the audience ID
                $foundAudiences = ($searchAudienceService)($customerId, $audienceKeyword);
                
                if (empty($foundAudiences)) {
                    Log::warning("GoogleAdsExecutionAgent: No audience found for keyword '{$audienceKeyword}'");
                    continue;
                }

                // Pick the first match
                $bestMatch = $foundAudiences[0];
                $audienceResourceName = $bestMatch['id'];

                Log::info("GoogleAdsExecutionAgent: Found audience for '{$audienceKeyword}'", [
                    'name' => $bestMatch['name'],
                    'id' => $audienceResourceName
                ]);

                // Determine type based on resource name
                if (strpos($audienceResourceName, 'userInterests') !== false) {
                    $type = 'USER_INTEREST';
                    $key = 'userInterestId';
                } else {
                    $type = 'AUDIENCE';
                    $key = 'audienceId';
                }

                // Add to Ad Group
                $criterionResourceName = ($addCriterionService)($customerId, $adGroupResourceName, [
                    'type' => $type,
                    $key => $audienceResourceName
                ]);

                if ($criterionResourceName) {
                    $result->addPlatformId('audience', $criterionResourceName);
                }

            } catch (\Exception $e) {
                $result->addWarning("Failed to add audience targeting for '{$audienceKeyword}': " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get final URL from campaign, strategy, or plan
     */
    protected function getFinalUrl(Campaign $campaign, Strategy $strategy, ExecutionPlan $plan): ?string
    {
        // 1. Check Strategy recommendation (most specific)
        if (isset($strategy->bidding_strategy['landing_page_url']) && !empty($strategy->bidding_strategy['landing_page_url'])) {
            return $strategy->bidding_strategy['landing_page_url'];
        }

        // 2. Check Campaign default
        if (!empty($campaign->landing_page_url)) {
            return $campaign->landing_page_url;
        }
        
        // 3. Check Execution Plan (AI generated during execution)
        foreach ($plan->steps as $step) {
            if (isset($step['parameters']['final_urls'][0]) && !empty($step['parameters']['final_urls'][0])) {
                return $step['parameters']['final_urls'][0];
            }
        }
        
        return null;
    }

    /**
     * Handle execution errors with AI-powered recovery
     */
    protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
    {
        Log::error("GoogleAdsExecutionAgent: Execution error - " . $error->getMessage(), [
            'campaign_id' => $context->campaign->id,
            'customer_id' => $this->customer->id
        ]);
        
        // Generate AI-powered recovery plan
        $recoveryPrompt = $this->buildRecoveryPrompt($error, $context);
        
        try {
            $response = $this->gemini->generateContent(
                model: 'gemini-3-pro-preview',
                prompt: $recoveryPrompt,
                config: ['temperature' => 0.3, 'maxOutputTokens' => 2048],
                systemInstruction: 'You are an expert at diagnosing and recovering from Google Ads API errors. Provide specific, actionable recovery steps.'
            );
            
            if ($response && isset($response['text'])) {
                return RecoveryPlan::fromJson($response['text']);
            }
        } catch (\Exception $e) {
            Log::error("GoogleAdsExecutionAgent: Failed to generate recovery plan: " . $e->getMessage());
        }
        
        // Fallback to simple recovery plan
        return RecoveryPlan::simple($error->getMessage(), [
            'Check Google Ads account connection',
            'Verify customer ID is correct',
            'Ensure sufficient permissions',
            'Review campaign budget and settings',
            'Check for API quota limits'
        ]);
    }
    
    /**
     * Build recovery prompt for AI
     */
    protected function buildRecoveryPrompt(\Exception $error, ExecutionContext $context): string
    {
        return <<<PROMPT
You are troubleshooting a Google Ads deployment error. Analyze the error and provide recovery actions.

Error Message: {$error->getMessage()}

Campaign Context:
- Campaign ID: {$context->campaign->id}
- Campaign Name: {$context->campaign->name}
- Platform: Google Ads
- Customer ID: {$this->customer->google_ads_customer_id}
- Daily Budget: \${$context->calculateDailyBudget()}

Available Assets:
- Ad Copy: {$context->hasAssetType('ad_copy')}
- Images: {$context->hasAssetType('image')}
- Videos: {$context->hasAssetType('video')}

Provide a JSON response with:
{
    "error_type": "string (authentication|budget|assets|api_quota|permissions|configuration)",
    "recovery_actions": ["action1", "action2"],
    "reasoning": "explanation of the error and recovery approach"
}
PROMPT;
    }
    
    /**
     * Check if conversion tracking is configured
     */
    protected function hasConversionTracking(ExecutionContext $context): bool
    {
        // TODO: Implement actual conversion tracking check via Google Ads API
        // For now, return false to be conservative
        return false;
    }
    
    /**
     * Get conversion count for Smart Bidding eligibility
     */
    protected function getConversionCount(ExecutionContext $context): int
    {
        // TODO: Implement actual conversion count retrieval via Google Ads API
        // For now, return 0
        return 0;
    }
    
    /**
     * Get the platform name for this agent
     */
    protected function getPlatformName(): string
    {
        return 'Google Ads';
    }
    
    /**
     * Create a Google Ads sub-account under the MCC
     * 
     * @return bool True if account created successfully, false otherwise
     */
    protected function createSubAccount(): bool
    {
        try {
            $mccCustomerId = config('googleads.mcc_customer_id');
            
            if (!$mccCustomerId) {
                Log::error("GoogleAdsExecutionAgent: MCC Customer ID not configured");
                return false;
            }
            
            $accountName = $this->customer->name . ' - Google Ads';
            
            // Use dependency injection to create the service
            $createService = new CreateAndLinkManagedAccount(
                $this->customer,
                app(CreateManagedAccount::class, ['customer' => $this->customer]),
                app(CreateCustomerClientLink::class, ['customer' => $this->customer])
            );
            
            $result = $createService(
                $mccCustomerId,
                $accountName,
                'USD',  // TODO: Get from customer's preferred currency
                'America/New_York'  // TODO: Get from customer's timezone
            );
            
            if (!$result) {
                Log::error("GoogleAdsExecutionAgent: Failed to create sub-account", [
                    'customer_id' => $this->customer->id,
                    'mcc_customer_id' => $mccCustomerId,
                ]);
                return false;
            }
            
            // Update customer record with new Google Ads customer ID
            $this->customer->google_ads_customer_id = $result['customer_id'];
            $this->customer->save();
            
            Log::info("GoogleAdsExecutionAgent: Created Google Ads sub-account", [
                'customer_id' => $this->customer->id,
                'google_ads_customer_id' => $result['customer_id'],
                'resource_name' => $result['resource_name'],
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("GoogleAdsExecutionAgent: Exception creating sub-account: " . $e->getMessage(), [
                'customer_id' => $this->customer->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
    
    /**
     * Add location targeting to campaign
     */
    protected function addLocationTargeting(
        string $customerId,
        string $campaignResourceName,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        $locations = [];

        // 1. Check campaign-level geographic targeting (highest priority)
        if (!empty($campaign->geographic_targeting)) {
            $locations = $campaign->geographic_targeting;
            Log::info("GoogleAdsExecutionAgent: Using campaign geographic targeting", ['count' => count($locations)]);
        }
        // 2. Check targeting config
        elseif ($strategy->targetingConfig && !empty($strategy->targetingConfig->geo_locations)) {
            $locations = $strategy->targetingConfig->geo_locations;
            Log::info("GoogleAdsExecutionAgent: Using targeting config geographic targeting", ['count' => count($locations)]);
        }
        // 3. Check execution plan
        else {
            $campaignStructure = $plan->getCampaignStructure();
            if (isset($campaignStructure['locations']) && !empty($campaignStructure['locations'])) {
                // Map plan locations (strings) to IDs if possible, or log warning
                // For now, we'll skip string-based locations as we need IDs
                Log::warning("GoogleAdsExecutionAgent: Execution plan has string locations, but IDs are required. Skipping.");
            }
        }

        if (empty($locations)) {
            return;
        }

        $addCriterionService = new AddCampaignCriterion($this->customer, true);

        foreach ($locations as $location) {
            try {
                // Expecting location object with 'id' or 'location_id'
                $locationId = is_array($location) ? ($location['id'] ?? $location['location_id'] ?? null) : $location;

                if (!$locationId) {
                    continue;
                }

                $criterionResourceName = ($addCriterionService)($customerId, $campaignResourceName, [
                    'type' => 'LOCATION',
                    'locationId' => $locationId
                ]);

                if ($criterionResourceName) {
                    $result->addPlatformId('location_criterion', $criterionResourceName);
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to add location targeting: " . $e->getMessage());
            }
        }
    }

    /**
     * Setup conversion tracking if needed
     */
    protected function setupConversionTracking(string $customerId, ExecutionResult $result): void
    {
        // In a real scenario, we would check if conversions exist first.
        // For now, we'll attempt to create a default "Purchase" conversion action.
        
        try {
            $createConversionService = new CreateConversionAction($this->customer, true);
            $conversionName = "Default Purchase Conversion";
            
            $resourceName = ($createConversionService)($customerId, $conversionName, ConversionActionCategory::PURCHASE);
            
            if ($resourceName) {
                $result->addPlatformId('conversion_action', $resourceName);
                Log::info("GoogleAdsExecutionAgent: Created default conversion action: $resourceName");

                // GTM Integration
                try {
                    $getDetails = new GetConversionActionDetails($this->customer, true);
                    $details = ($getDetails)($customerId, $resourceName);
                    
                    if ($details && isset($details['conversion_id'], $details['conversion_label'])) {
                        $gtmService = new GTMContainerService();
                        
                        $tagResult = $gtmService->addConversionTag(
                            $this->customer,
                            "Google Ads Conversion - Purchase",
                            $details['conversion_id'],
                            ['conversion_label' => $details['conversion_label']]
                        );
                        
                        if ($tagResult['success']) {
                            Log::info("Created GTM Tag for conversion: " . ($tagResult['tag_id'] ?? 'unknown'));
                        } else {
                            Log::warning("Failed to create GTM Tag: " . ($tagResult['error'] ?? 'Unknown'));
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("GTM Integration failed: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // Don't fail the whole execution for this, just warn
            Log::warning("GoogleAdsExecutionAgent: Failed to setup conversion tracking: " . $e->getMessage());
            $result->addWarning("Conversion tracking setup failed: " . $e->getMessage());
        }
    }

    /**
     * Create and link ad extensions (Sitelinks, Callouts)
     */
    protected function createAndLinkAdExtensions(
        string $customerId, 
        string $campaignResourceName, 
        Strategy $strategy, 
        ExecutionResult $result
    ): void {
        $createSitelinkService = new CreateSitelinkAsset($this->customer, true);
        $createCalloutService = new CreateCalloutAsset($this->customer, true);
        $linkAssetService = new LinkCampaignAsset($this->customer, true);

        // 1. Sitelinks
        // In a real app, these would come from the Strategy or AdCopy model.
        // We'll generate some generic ones based on the business context if available, or placeholders.
        $sitelinks = [
            ['text' => 'Contact Us', 'desc1' => 'Get in touch today', 'desc2' => 'We are here to help'],
            ['text' => 'About Us', 'desc1' => 'Learn our story', 'desc2' => 'Serving you since 2020'],
            ['text' => 'Shop Now', 'desc1' => 'Browse our catalog', 'desc2' => 'Best prices guaranteed'],
            ['text' => 'Special Offers', 'desc1' => 'Limited time deals', 'desc2' => 'Save big today']
        ];

        foreach ($sitelinks as $sitelink) {
            try {
                // Use a placeholder URL if strategy doesn't have specific ones
                $url = $strategy->landing_page_url ?? 'https://example.com';
                
                $assetResourceName = ($createSitelinkService)(
                    $customerId, 
                    $sitelink['text'], 
                    $sitelink['desc1'], 
                    $sitelink['desc2'], 
                    $url
                );

                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::SITELINK);
                    $result->addPlatformId('sitelink_asset', $assetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning("GoogleAdsExecutionAgent: Failed to create/link sitelink: " . $e->getMessage());
            }
        }

        // 2. Callouts
        $callouts = ['Free Shipping', '24/7 Support', 'Best Quality', 'Secure Payment'];
        
        foreach ($callouts as $text) {
            try {
                $assetResourceName = ($createCalloutService)($customerId, $text);
                
                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::CALLOUT);
                    $result->addPlatformId('callout_asset', $assetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning("GoogleAdsExecutionAgent: Failed to create/link callout: " . $e->getMessage());
            }
        }
    }
}
