<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\LinkedInAds\CampaignService;
use App\Services\LinkedInAds\PerformanceService;
use Illuminate\Support\Facades\Log;

/**
 * LinkedIn Ads Execution Agent.
 *
 * Creates and manages LinkedIn advertising campaigns with
 * B2B-specific targeting (job title, company, industry, seniority).
 *
 * Supports:
 * - Sponsored Content campaigns
 * - Message Ads (InMail)
 * - Lead Gen Forms
 * - LinkedIn Insight Tag setup
 * - Performance tracking
 */
class LinkedInAdsExecutionAgent extends PlatformExecutionAgent
{
    protected string $platform = 'linkedin';

    protected function getPlatformName(): string
    {
        return 'LinkedIn Ads';
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $this->logExecution('Starting LinkedIn Ads execution', [
            'campaign_id' => $context->campaign?->id,
        ]);

        try {
            $validation = $this->validatePrerequisites($context);
            if (!$validation->passed) {
                return new ExecutionResult(
                    success: false,
                    message: 'Prerequisites not met: ' . implode(', ', $validation->errors),
                    data: ['validation' => $validation],
                );
            }

            $plan = $this->generateExecutionPlan($context);

            return $this->executePlan($plan, $context);
        } catch (\Exception $e) {
            $this->logError('Execution failed', ['error' => $e->getMessage()]);
            $recovery = $this->handleExecutionError($e, $context);

            return new ExecutionResult(
                success: false,
                message: 'Execution failed: ' . $e->getMessage(),
                data: ['recovery_plan' => $recovery],
            );
        }
    }

    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $errors = [];

        if (!$this->customer->linkedin_ads_account_id) {
            $errors[] = 'LinkedIn Ads account ID not configured';
        }

        $clientId = config('linkedinads.client_id');
        $clientSecret = config('linkedinads.client_secret');

        if (!$clientId || !$clientSecret) {
            $errors[] = 'LinkedIn API credentials not configured';
        }

        if (!config('linkedinads.refresh_token')) {
            $errors[] = 'No LinkedIn management credential configured (set LINKEDIN_ADS_REFRESH_TOKEN in .env)';
        }

        // Check for ad assets
        $assets = $context->availableAssets ?? [];
        if (($assets['ad_copies'] ?? 0) < 1) {
            $errors[] = 'At least 1 ad copy is required';
        }

        return new ValidationResult(
            passed: empty($errors),
            errors: $errors,
            warnings: [],
        );
    }

    protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
    {
        $strategy = $context->strategy;
        $campaign = $context->campaign;

        $landingPageUrl = $campaign->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $this->customer->website
            ?? 'Not provided';

        $prompt = <<<PROMPT
You are a LinkedIn Ads expert creating a campaign execution plan.

Strategy: {$strategy->campaign_type}
Platform: LinkedIn
Budget: \${$strategy->daily_budget}/day
Landing Page: {$landingPageUrl}
Target audience: {$strategy->target_audience}
Business type: {$this->customer->business_type}
Industry: {$this->customer->industry}
Business name: {$this->customer->name}

Available ad copies: {$context->availableAssets['ad_copies']}
Available images: {$context->availableAssets['images']}

BUYER PERSPECTIVE RULE: Target and write copy for the decision-maker who wants to BUY or HIRE this service — focus on their problems and desired outcomes, not the product's features or technology. Job titles, industries, and ad copy should reflect who has budget authority and is actively looking for a solution.

Create an execution plan as JSON with this structure:
{
    "steps": [
        {"action": "create_campaign", "description": "...", "parameters": {"campaign_type": "SPONSORED_UPDATES|SPONSORED_INMAILS", "objective": "WEBSITE_VISITS|LEAD_GENERATION|BRAND_AWARENESS", "daily_budget": 50}},
        {"action": "set_targeting", "description": "...", "parameters": {"job_titles": [], "industries": [], "company_sizes": [], "seniorities": []}},
        {"action": "create_creatives", "description": "...", "parameters": {}},
        {"action": "setup_conversion_tracking", "description": "...", "parameters": {}}
    ],
    "reasoning": "Why this plan makes sense for LinkedIn B2B advertising",
    "estimated_cpl": "estimated cost per lead"
}

Focus on B2B targeting capabilities that make LinkedIn unique.
Return ONLY valid JSON.
PROMPT;

        $result = $this->gemini->generateContent(config('ai.models.default'), $prompt, [
            'temperature' => 0.3,
            'maxOutputTokens' => 2048,
        ]);

        return ExecutionPlan::fromJson($result['text'] ?? '{}');
    }

    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $campaignService = new CampaignService($this->customer);
        $results = [];

        foreach ($plan->steps as $step) {
            try {
                $result = match ($step->action ?? $step['action'] ?? '') {
                    'create_campaign' => $this->executeCreateCampaign($campaignService, $step, $context),
                    'set_targeting' => $this->executeSetTargeting($campaignService, $step, $context),
                    'create_creatives' => $this->executeCreateCreatives($campaignService, $step, $context),
                    'setup_conversion_tracking' => $this->executeSetupTracking($campaignService),
                    default => ['status' => 'skipped', 'reason' => 'Unknown action'],
                };

                $results[] = array_merge(['step' => $step->action ?? $step['action'] ?? ''], $result);
            } catch (\Exception $e) {
                $results[] = [
                    'step' => $step->action ?? $step['action'] ?? '',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $allSucceeded = collect($results)->every(fn ($r) => ($r['status'] ?? '') !== 'failed');

        return new ExecutionResult(
            success: $allSucceeded,
            message: $allSucceeded ? 'LinkedIn campaign deployed successfully' : 'Some steps failed',
            data: ['steps' => $results, 'plan' => $plan],
        );
    }

    protected function executeCreateCampaign(CampaignService $service, $step, ExecutionContext $context): array
    {
        $params = (array) ($step->parameters ?? $step['parameters'] ?? []);
        $campaign = $context->campaign;

        $campaignType = $params['campaign_type'] ?? 'SPONSORED_UPDATES';

        $createParams = [
            'name' => $campaign?->name ?? 'New LinkedIn Campaign',
            'daily_budget' => $params['daily_budget'] ?? $context->strategy->daily_budget ?? 50,
            'objective' => $params['objective'] ?? 'WEBSITE_VISITS',
            'status' => 'PAUSED',
        ];

        $result = match ($campaignType) {
            'SPONSORED_INMAILS' => $service->createMessageAdsCampaign($createParams),
            default => $service->createSponsoredContentCampaign($createParams),
        };

        if ($result && $campaign) {
            $campaignId = $result['id'] ?? null;
            if ($campaignId) {
                $campaign->update(['linkedin_campaign_id' => $campaignId]);
            }
        }

        return ['status' => $result ? 'success' : 'failed', 'result' => $result];
    }

    protected function executeSetTargeting(CampaignService $service, $step, ExecutionContext $context): array
    {
        // Targeting is applied inside createSponsoredContentCampaign() via targetingCriteria.
        Log::info('[LinkedInAdsExecutionAgent] Targeting applied during campaign creation — no separate API call required', [
            'campaign_id' => $context->campaign->id,
        ]);
        return ['status' => 'success', 'reason' => 'Targeting applied during campaign creation'];
    }

    protected function executeCreateCreatives(CampaignService $service, $step, ExecutionContext $context): array
    {
        $campaign = $context->campaign;
        $strategy = $context->strategy;

        $campaignId = $campaign->linkedin_campaign_id ?? null;
        if (!$campaignId) {
            return ['status' => 'skipped', 'reason' => 'No LinkedIn campaign ID — campaign creation must succeed first'];
        }

        $adCopy = $strategy->adCopies()
            ->whereRaw('LOWER(platform) LIKE ?', ['%linkedin%'])
            ->first()
            ?? $strategy->adCopies()->first();

        if (!$adCopy) {
            Log::warning('[LinkedInAdsExecutionAgent] No ad copy for creative creation', ['strategy_id' => $strategy->id]);
            return ['status' => 'skipped', 'reason' => 'No ad copy available'];
        }

        $landingUrl = $campaign->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $this->customer->website
            ?? null;

        if (!$landingUrl) {
            Log::warning('[LinkedInAdsExecutionAgent] No landing URL for creative', ['strategy_id' => $strategy->id]);
            return ['status' => 'skipped', 'reason' => 'No landing page URL'];
        }

        $creative = $service->createCreative($campaignId, [
            'headline'    => $adCopy->headlines[0] ?? 'Learn More',
            'description' => $adCopy->descriptions[0] ?? '',
            'destination' => $landingUrl,
        ]);

        if ($creative) {
            Log::info('[LinkedInAdsExecutionAgent] Created LinkedIn creative', [
                'campaign_id' => $campaign->id,
                'creative'    => $creative,
            ]);
            $strategy->update(['linkedin_creative_id' => $creative['id'] ?? null]);
            return ['status' => 'success', 'creative' => $creative];
        }

        return ['status' => 'failed', 'reason' => 'LinkedIn API did not return a creative ID'];
    }

    protected function executeSetupTracking(CampaignService $service): array
    {
        $tag = $service->getInsightTag();
        return ['status' => $tag ? 'success' : 'skipped', 'insight_tag' => $tag];
    }

    protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
    {
        return new OptimizationAnalysis(
            opportunities: [
                'LinkedIn audiences convert at 2-5x higher rates for B2B',
                'Lead Gen Forms reduce friction by pre-filling user data',
                'Message Ads have 40%+ open rates for InMail',
                'Matched Audiences allow retargeting website visitors',
            ],
            estimatedImpact: 'medium',
            reasoning: 'LinkedIn excels at B2B targeting with professional demographic data',
        );
    }

    protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
    {
        return new RecoveryPlan(
            actions: ['Review LinkedIn API error', 'Check account permissions', 'Verify OAuth token'],
            reasoning: 'LinkedIn API errors often relate to authentication or permission issues',
        );
    }

    protected function logExecution(string $message, array $context = []): void
    {
        Log::info("LinkedInAdsExecutionAgent: {$message}", array_merge([
            'customer_id' => $this->customer->id,
        ], $context));
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::error("LinkedInAdsExecutionAgent: {$message}", array_merge([
            'customer_id' => $this->customer->id,
        ], $context));
    }
}
