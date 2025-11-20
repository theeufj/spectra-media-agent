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
    protected string $platform = 'google';
    
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
        $result = new ValidationResult();
        
        // Check Google Ads account connection
        if (!$this->customer->google_ads_customer_id) {
            $result->addError('google_ads_not_connected', 'Google Ads account not connected');
            return $result;
        }
        
        if (!$this->customer->google_ads_refresh_token) {
            $result->addError('google_ads_not_authorized', 'Google Ads account not authorized');
            return $result;
        }
        
        // Check conversion tracking (warning only - campaigns can run without it)
        if (!$this->hasConversionTracking($context)) {
            $result->addWarning('no_conversion_tracking', 'No conversion tracking configured - Smart Bidding will be limited');
        }
        
        // Validate creative assets
        $strategy = $context->strategy;
        $hasImages = $strategy->imageCollaterals()->where('is_active', true)->exists();
        $hasAdCopy = $strategy->adCopies()->where('platform', 'google')->exists();
        
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
            $response = $this->geminiService->generateContent(
                model: 'gemini-2.5-pro',
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
        $result = ExecutionResult::success($plan);
        $customerId = $this->customer->google_ads_customer_id;
        $strategy = $context->strategy;
        $campaign = $context->campaign;
        
        Log::info("GoogleAdsExecutionAgent: Starting plan execution for Campaign {$campaign->id}");
        
        try {
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
                    $result->addWarning('Performance Max campaigns not yet implemented - falling back to Search');
                    $this->executeSearchCampaign($customerId, $campaign, $strategy, $plan, $result);
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
            
            $result = ExecutionResult::failure($plan, [$e->getMessage()]);
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
        // 1. Create Campaign
        $createCampaignService = new CreateSearchCampaign($this->customer);
        $campaignStructure = $plan->getCampaignStructure();
        
        $campaignData = [
            'businessName' => $campaign->name,
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
        
        // 2. Create Ad Group
        $createAdGroupService = new CreateSearchAdGroup($this->customer);
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, 'Default Ad Group');
        if (!$adGroupResourceName) {
            throw new \Exception('Failed to create search ad group');
        }
        
        $result->addPlatformId('ad_group', $adGroupResourceName);
        $strategy->google_ads_ad_group_id = $adGroupResourceName;
        $strategy->save();
        
        // 3. Add Keywords if specified in plan
        $creativeStrategy = $plan->getCreativeStrategy();
        if (isset($creativeStrategy['keywords']) && !empty($creativeStrategy['keywords'])) {
            $this->addKeywords($customerId, $adGroupResourceName, $creativeStrategy['keywords'], $result);
        }
        
        // 4. Create Responsive Search Ad
        $adCopy = $strategy->adCopies()->where('platform', 'google')->first();
        if ($adCopy) {
            $createAdService = new CreateResponsiveSearchAd($this->customer);
            $adData = [
                'finalUrls' => [$campaign->landing_page_url],
                'headlines' => $adCopy->headlines ?? [],
                'descriptions' => $adCopy->descriptions ?? [],
            ];
            
            $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
            if ($adResourceName) {
                $result->addPlatformId('ad', $adResourceName);
            }
        }
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
        // 1. Create Campaign
        $createCampaignService = new CreateDisplayCampaign($this->customer);
        $campaignStructure = $plan->getCampaignStructure();
        
        $campaignData = [
            'businessName' => $campaign->name,
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
        
        // 2. Create Ad Group
        $createAdGroupService = new CreateDisplayAdGroup($this->customer);
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, 'Default Ad Group');
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
            $uploadImageAssetService = new UploadImageAsset($this->customer);
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
        $adCopy = $strategy->adCopies()->where('platform', 'google')->first();
        if ($adCopy && !empty($imageAssetResourceNames)) {
            $createAdService = new CreateResponsiveDisplayAd($this->customer);
            $adData = [
                'finalUrls' => [$campaign->landing_page_url],
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
    
    /**
     * Add keywords to ad group
     */
    protected function addKeywords(string $customerId, string $adGroupResourceName, array $keywords, ExecutionResult $result): void
    {
        $addCriterionService = new AddAdGroupCriterion($this->customer);
        
        foreach ($keywords as $keyword) {
            try {
                $keywordText = is_array($keyword) ? $keyword['text'] : $keyword;
                $matchType = is_array($keyword) && isset($keyword['match_type']) 
                    ? $keyword['match_type'] 
                    : 'BROAD';
                
                $criterionResourceName = ($addCriterionService)($customerId, $adGroupResourceName, $keywordText, $matchType);
                if ($criterionResourceName) {
                    $result->addPlatformId('keyword', $criterionResourceName);
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to add keyword: " . $e->getMessage());
            }
        }
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
            $response = $this->geminiService->generateContent(
                model: 'gemini-2.5-flash',
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
}
