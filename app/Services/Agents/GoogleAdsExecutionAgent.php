<?php

namespace App\Services\Agents;

use App\Mail\GoogleAdsVerificationRequired;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Notifications\ConversionTrackingReady;
use App\Prompts\GoogleAdsExecutionPrompt;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\Executors\CampaignTypeExecutor;
use App\Services\Agents\Google\Executors\DemandGenCampaignExecutor;
use App\Services\Agents\Google\Executors\DisplayCampaignExecutor;
use App\Services\Agents\Google\Executors\LocalServicesCampaignExecutor;
use App\Services\Agents\Google\Executors\PerformanceMaxCampaignExecutor;
use App\Services\Agents\Google\Executors\SearchCampaignExecutor;
use App\Services\Agents\Google\Executors\ShoppingCampaignExecutor;
use App\Services\Agents\Google\Executors\VideoCampaignExecutor;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Agents\Traits\RetryableApiOperation;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GTM\GTMContainerService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        Log::info('GoogleAdsExecutionAgent: Starting execution', [
            'campaign_id' => $context->campaign->id,
            'strategy_id' => $context->strategy->id,
        ]);

        // Validate prerequisites
        $validation = $this->validatePrerequisites($context);
        if (! $validation->passes()) {
            Log::error('GoogleAdsExecutionAgent: Prerequisites validation failed', [
                'errors' => $validation->errors,
            ]);

            return ExecutionResult::failure($validation->errors);
        }

        // NOTE: analyzeOptimizationOpportunities() used to be called here and its
        // result discarded — it costs a Google Ads conversion query plus several
        // collateral counts on every deploy for nothing. It is left in place
        // because executePlan() independently re-derives PMax eligibility with a
        // *different* rule set (>=3 valid-ratio images vs. images + video +
        // conversion tracking + budget). Those two decisions should be unified and
        // fed into generateExecutionPlan(); until then, don't pay for the analysis.

        // Generate execution plan
        $plan = $this->generateExecutionPlan($context);

        // Execute the plan
        $result = $this->executePlan($plan, $context);

        Log::info('GoogleAdsExecutionAgent: Execution completed', [
            'campaign_id' => $context->campaign->id,
            'success' => $result->success,
            'execution_time' => $result->executionTime,
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

        // Ensure the customer has a Google Ads sub-account.
        // If not, auto-provision one under the platform MCC.
        if (! $this->customer->google_ads_customer_id) {
            $provisioned = $this->provisionGoogleAdsAccount();
            if (! $provisioned) {
                $result->addError('google_ads_no_account', 'Failed to provision Google Ads account - check MCC configuration');

                return $result;
            }
        }

        // Verify platform MCC credentials are configured
        $mccAccount = \App\Models\MccAccount::getActive();
        if (! $mccAccount) {
            $result->addError('google_ads_not_authorized', 'No active MCC account configured');

            return $result;
        }

        // Check conversion tracking (warning only - campaigns can run without it)
        if (! $this->hasConversionTracking($context)) {
            $result->addWarning('no_conversion_tracking', 'No conversion tracking configured - Smart Bidding will be limited');
        }

        // Validate creative assets
        $strategy = $context->strategy;
        $hasImages = $strategy->imageCollaterals()->where('is_active', true)->where('should_deploy', true)->exists();
        $hasAdCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->exists();

        if (! $hasAdCopy) {
            $result->addError('no_ad_copy', 'No ad copy available for Google Ads');
        }

        if (! $hasImages) {
            $result->addWarning('no_images', 'No images available - Display and Performance Max campaigns will be limited');
        }

        // Validate budget meets Google Ads minimums
        $budgetValidation = $this->validateBudget($context);
        if (! $budgetValidation->passes()) {
            foreach ($budgetValidation->errors as $error) {
                $result->addError('budget_'.$error['code'], $error['message']);
            }
        }

        return $result;
    }

    /**
     * Auto-provision a Google Ads sub-account under the platform MCC.
     */
    protected function provisionGoogleAdsAccount(): bool
    {
        $mccAccount = \App\Models\MccAccount::getActive();

        if (! $mccAccount) {
            Log::error('Cannot provision Google Ads account: No active MCC configured');

            return false;
        }

        $mccCustomerId = $mccAccount->google_customer_id;

        try {
            Log::info('Provisioning Google Ads sub-account under platform MCC', [
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->name,
                'mcc_id' => $mccCustomerId,
            ]);

            $mccManager = new \App\Services\GoogleAds\MCCAccountManager($this->customer);
            $result = $mccManager->createStandardAccountUnderMCC(
                $mccCustomerId,
                $this->customer->name
            );

            if (! $result) {
                Log::error('Failed to create sub-account under MCC', [
                    'customer_id' => $this->customer->id,
                ]);

                return false;
            }

            // Refresh the customer model to pick up the new IDs
            $this->customer->refresh();

            Log::info('Successfully provisioned Google Ads sub-account', [
                'customer_id' => $this->customer->id,
                'google_ads_customer_id' => $this->customer->google_ads_customer_id,
                'mcc_id' => $this->customer->google_ads_manager_customer_id,
            ]);

            foreach ($this->customer->users as $user) {
                Mail::to($user->email)->queue(
                    new GoogleAdsVerificationRequired($user, $this->customer)
                );
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error provisioning Google Ads sub-account: '.$e->getMessage(), [
                'customer_id' => $this->customer->id,
                'exception' => $e,
            ]);

            return false;
        }
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
        $analysis = new OptimizationAnalysis;
        $strategy = $context->strategy;
        $campaign = $context->campaign;

        // Check Performance Max eligibility
        $hasMultipleAssets = $strategy->imageCollaterals()->where('is_active', true)->where('should_deploy', true)->count() >= 3
            && VideoCollateral::where('campaign_id', $campaign->id)->where('is_active', true)->count() >= 1;
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
        // Google's minimums: Target ROAS needs 50+/month, Target CPA needs 30+/month.
        $conversionCount = $this->getConversionCount($context);
        if ($conversionCount >= 50) {
            $analysis->addOpportunity(
                'smart_bidding_target_roas',
                'Sufficient conversion data for Target ROAS bidding strategy',
                'high',
                ['conversion_count' => $conversionCount]
            );
        } elseif ($conversionCount >= 30) {
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
                'message' => 'Daily budget below $8.33 (~$250/month) - Performance Max campaigns not recommended',
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

        $lastException = null;
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->gemini->generateContent(
                    model: config('ai.models.default'),
                    prompt: $prompt,
                    config: [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 65536,
                    ],
                    systemInstruction: $systemInstruction,
                    enableGoogleSearch: false
                );

                if (! $response || ! isset($response['text'])) {
                    throw new \Exception('Empty response from AI model');
                }

                Log::debug('GoogleAdsExecutionAgent: Raw AI response', [
                    'attempt' => $attempt,
                    'response_length' => strlen($response['text']),
                    'response_preview' => substr($response['text'], 0, 500),
                ]);

                $plan = ExecutionPlan::fromJson($response['text']);
                $plan = (new CampaignReviewAgent($this->customer))->review($plan, 'google');

                Log::info('GoogleAdsExecutionAgent: Generated execution plan', [
                    'campaign_id' => $context->campaign->id,
                    'attempt' => $attempt,
                    'steps_count' => count($plan->steps),
                    'campaign_type' => $plan->getCampaignStructure()['type'] ?? 'unknown',
                ]);

                return $plan;

            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("GoogleAdsExecutionAgent: Execution plan attempt {$attempt}/{$maxAttempts} failed: ".$e->getMessage());
                if ($attempt < $maxAttempts) {
                    usleep(500000 * $attempt); // 0.5s, 1s back-off
                }
            }
        }

        Log::error("GoogleAdsExecutionAgent: Failed to generate execution plan after {$maxAttempts} attempts: ".$lastException->getMessage());
        throw $lastException;
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
            $this->setupConversionTracking($customerId, $result, $campaign);

            $campaignStructure = $plan->getCampaignStructure();
            $campaignType = $campaignStructure['type'] ?? 'search';

            // Prefer Performance Max over plain Search when assets are ready.
            // PMax runs across all Google surfaces (Search, Display, YouTube, Gmail, Maps)
            // from day one — more efficient than a Search-only campaign for new accounts.
            if (in_array($campaignType, ['search', 'display'], true)) {
                $hasAdCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->exists();
                $minBudget = ($strategy->daily_budget ?: ($campaign->daily_budget ?: 0)) >= 10;

                $validPmaxImages = 0;
                if ($hasAdCopy && $minBudget) {
                    $images = $strategy->imageCollaterals()
                        ->where('is_active', true)
                        ->where('should_deploy', true)
                        ->get();

                    foreach ($images as $img) {
                        $url = $img->cloudfront_url ?? $img->s3_path ?? null;
                        if (! $url) {
                            continue;
                        }
                        try {
                            $size = @getimagesize($url);
                            if (! $size || $size[0] === 0 || $size[1] === 0) {
                                continue;
                            }
                            $ratio = $size[0] / $size[1];
                            // PMax requires 1.91:1 (±5%) or 1:1 (±5%)
                            $is191 = $ratio >= 1.8145 && $ratio <= 2.0055;
                            $is1x1 = $ratio >= 0.95 && $ratio <= 1.05;
                            if ($is191 || $is1x1) {
                                $validPmaxImages++;
                            }
                        } catch (\Throwable $e) {
                            // skip unreadable images
                        }
                    }
                }

                if ($validPmaxImages >= 3 && $hasAdCopy && $minBudget) {
                    Log::info("GoogleAdsExecutionAgent: Upgrading {$campaignType} → performance_max (assets ready)", [
                        'campaign_id' => $campaign->id,
                        'valid_pmax_images' => $validPmaxImages,
                    ]);
                    $campaignType = 'performance_max';
                    $result->addWarning('campaign_type_upgraded', 'Campaign type upgraded to Performance Max — all Google surfaces will be targeted using your uploaded assets.');
                } else {
                    Log::info("GoogleAdsExecutionAgent: Keeping {$campaignType} campaign type (PMax requires ≥3 valid-ratio images, found {$validPmaxImages})", [
                        'campaign_id' => $campaign->id,
                    ]);
                }
            }

            $this->executorFor($campaignType)
                ->execute($customerId, $campaign, $strategy, $plan, $result);

            $result->executionTime = microtime(true) - $startTime;

            Log::info('GoogleAdsExecutionAgent: Successfully executed plan', [
                'campaign_id' => $campaign->id,
                'execution_time' => $result->executionTime,
                'platform_ids_count' => count($result->platformIds),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('GoogleAdsExecutionAgent: Plan execution failed: '.$e->getMessage());

            $result = ExecutionResult::failure([$e->getMessage()]);
            $result->plan = $plan;
            $result->executionTime = microtime(true) - $startTime;

            return $result;
        }
    }

    /**
     * Build the executor for a campaign type, wiring the shared building blocks.
     *
     * Each campaign type used to be a ~100-250 line method on this class; they now
     * live in App\Services\Agents\Google\Executors and share the collaborators below.
     *
     * @throws \InvalidArgumentException when the type has no executor
     */
    protected function executorFor(string $campaignType): CampaignTypeExecutor
    {
        $geo = new GeoTargetResolver($this->customer);
        $extensions = new AdExtensionBuilder($this->customer, $this->gemini);
        $urls = new LandingUrlBuilder($this->customer);
        $bidding = new BiddingStrategyApplier($this->customer);

        return match ($campaignType) {
            'search' => new SearchCampaignExecutor(
                $this->customer, $geo, $extensions, $urls, $bidding,
                new SearchKeywordBuilder($this->customer),
                new AudienceTargeter($this->customer),
            ),
            'display' => new DisplayCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            'performance_max' => new PerformanceMaxCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            'video' => new VideoCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            'demand_gen' => new DemandGenCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            'shopping' => new ShoppingCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            'local_services' => new LocalServicesCampaignExecutor($this->customer, $geo, $extensions, $urls, $bidding),
            default => throw new \InvalidArgumentException("Unsupported campaign type: {$campaignType}"),
        };
    }

    protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
    {
        Log::error('GoogleAdsExecutionAgent: Execution error - '.$error->getMessage(), [
            'campaign_id' => $context->campaign->id,
            'customer_id' => $this->customer->id,
        ]);

        // Generate AI-powered recovery plan
        $recoveryPrompt = $this->buildRecoveryPrompt($error, $context);

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $recoveryPrompt,
                config: ['temperature' => 0.3, 'maxOutputTokens' => 2048],
                systemInstruction: 'You are an expert at diagnosing and recovering from Google Ads API errors. Provide specific, actionable recovery steps.'
            );

            if ($response && isset($response['text'])) {
                return RecoveryPlan::fromJson($response['text']);
            }
        } catch (\Exception $e) {
            Log::error('GoogleAdsExecutionAgent: Failed to generate recovery plan: '.$e->getMessage());
        }

        // Fallback to simple recovery plan
        return RecoveryPlan::simple($error->getMessage(), [
            'Check Google Ads account connection',
            'Verify customer ID is correct',
            'Ensure sufficient permissions',
            'Review campaign budget and settings',
            'Check for API quota limits',
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
        try {
            $customerId = $context->customer->google_ads_customer_id;
            if (! $customerId) {
                return false;
            }

            $conversionService = new \App\Services\GoogleAds\ConversionTrackingService($context->customer);

            return $conversionService->isConversionTrackingSetUp($customerId);
        } catch (\Exception $e) {
            Log::warning('Failed to check conversion tracking: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get conversion count for Smart Bidding eligibility
     */
    protected function getConversionCount(ExecutionContext $context): int
    {
        try {
            $customerId = $context->customer->google_ads_customer_id;
            if (! $customerId) {
                return 0;
            }

            $conversionService = new \App\Services\GoogleAds\ConversionTrackingService($context->customer);

            return $conversionService->getConversionCountLast30Days($customerId);
        } catch (\Exception $e) {
            Log::warning('Failed to get conversion count: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Get the platform name for this agent
     */
    protected function getPlatformName(): string
    {
        return 'Google Ads';
    }

    /**
     * Create the customer's conversion action (and wire the GTM tag) if absent.
     * Best-effort: never fails the deployment.
     */
    protected function setupConversionTracking(string $customerId, ExecutionResult $result, ?Campaign $campaign = null): void
    {
        try {
            // Fast path: customer already has a saved conversion action — nothing to do
            if ($this->customer->conversion_action_id) {
                Log::info('GoogleAdsExecutionAgent: Conversion tracking already set up, skipping creation');

                return;
            }

            // Slower API check: action may exist in Google Ads but not yet saved locally
            $conversionService = new \App\Services\GoogleAds\ConversionTrackingService($this->customer);
            if ($conversionService->isConversionTrackingSetUp($customerId)) {
                Log::info('GoogleAdsExecutionAgent: Conversion tracking already exists in Google Ads, skipping creation');

                return;
            }

            // Pick category based on industry — SaaS/services use LEAD, e-commerce uses PURCHASE
            $ecommerceIndustries = ['ecommerce', 'retail', 'shopping'];
            $industry = strtolower($campaign->industry ?? $this->customer->industry ?? '');
            $isEcommerce = in_array($industry, $ecommerceIndustries, true);

            $conversionCategory = $isEcommerce ? ConversionActionCategory::PURCHASE : ConversionActionCategory::LEAD;
            $conversionName = $isEcommerce ? 'Default Purchase Conversion' : 'Default Lead Conversion';
            $gtmTagLabel = $isEcommerce ? 'Google Ads Conversion - Purchase' : 'Google Ads Conversion - Lead';

            $createConversionService = new CreateConversionAction($this->customer);
            $resourceName = ($createConversionService)($customerId, $conversionName, $conversionCategory);

            if ($resourceName) {
                $result->addPlatformId('conversion_action', $resourceName);

                // Persist so HealthCheckAgent / PreLaunchComplianceAgent / setup progress see it
                $this->customer->conversion_action_id = $resourceName;
                $this->customer->save();

                // Notify all users for this customer so they know to install the snippet
                $this->customer->users()->each(
                    fn ($user) => $user->notify(new ConversionTrackingReady($this->customer))
                );

                Log::info("GoogleAdsExecutionAgent: Created default conversion action: $resourceName", [
                    'category' => $isEcommerce ? 'PURCHASE' : 'LEAD',
                    'industry' => $industry,
                ]);

                // GTM Integration
                try {
                    $getDetails = new GetConversionActionDetails($this->customer);
                    $details = ($getDetails)($customerId, $resourceName);

                    if ($details && isset($details['conversion_id'], $details['conversion_label'])) {
                        $gtmService = new GTMContainerService;

                        $tagResult = $gtmService->addConversionTag(
                            $this->customer,
                            $gtmTagLabel,
                            $details['conversion_id'],
                            ['conversion_label' => $details['conversion_label']]
                        );

                        if ($tagResult['success']) {
                            Log::info('Created GTM Tag for conversion: '.($tagResult['tag_id'] ?? 'unknown'));
                            $gtmService->publishContainer($this->customer, 'Spectra: conversion tag wired on campaign deploy');
                        } else {
                            Log::warning('Failed to create GTM Tag: '.($tagResult['error'] ?? 'Unknown'));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('GTM Integration failed: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Don't fail the whole execution for this, just warn
            Log::warning('GoogleAdsExecutionAgent: Failed to setup conversion tracking: '.$e->getMessage());
            $result->addWarning('Conversion tracking setup failed: '.$e->getMessage());
        }
    }
}
