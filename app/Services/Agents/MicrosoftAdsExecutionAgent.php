<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\MicrosoftAds\CampaignService;
use App\Services\MicrosoftAds\AdGroupService;
use App\Services\MicrosoftAds\PerformanceService;
use App\Services\MicrosoftAds\ImportService;
use App\Services\MicrosoftAds\ConversionTrackingService;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Ads Execution Agent.
 *
 * Mirrors Google Ads campaigns to Bing / Microsoft Advertising
 * with platform-specific optimizations. Supports:
 * - Direct campaign creation via API
 * - Import from Google Ads (recommended for mirroring)
 * - Budget management
 * - Performance tracking
 */
class MicrosoftAdsExecutionAgent extends PlatformExecutionAgent
{
    protected string $platform = 'microsoft';

    public function execute(ExecutionContext $context): ExecutionResult
    {
        $this->logExecution('Starting Microsoft Ads execution', [
            'campaign_id' => $context->campaign?->id,
        ]);

        try {
            // Validate prerequisites
            $validation = $this->validatePrerequisites($context);
            if (!$validation->passed) {
                return new ExecutionResult(
                    success: false,
                    message: 'Prerequisites not met: ' . implode(', ', $validation->errors),
                    data: ['validation' => $validation],
                );
            }

            // Generate execution plan
            $plan = $this->generateExecutionPlan($context);

            // Execute the plan
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

    protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
    {
        $prompt = \App\Prompts\MicrosoftAdsExecutionPrompt::generate($context);
        $systemInstruction = \App\Prompts\MicrosoftAdsExecutionPrompt::getSystemInstruction();

        $this->logExecution('Generating execution plan via Gemini AI');

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $prompt,
                config: [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json',
                ],
                systemInstruction: $systemInstruction
            );

            if (!$response || !isset($response['text'])) {
                throw new \Exception('Empty response from AI model');
            }

            return ExecutionPlan::fromJson($response['text']);
        } catch (\Exception $e) {
            $this->logError('Failed to generate execution plan', ['error' => $e->getMessage()]);

            // Fallback plan if AI fails
            return $this->generateFallbackPlan($context);
        }
    }

    protected function generateFallbackPlan(ExecutionContext $context): ExecutionPlan
    {
        $campaign = $context->campaign;
        $useImport = $campaign && $campaign->google_ads_campaign_id
            && $this->customer->microsoft_ads_account_id;

        $steps = [];

        if ($useImport) {
            $steps[] = new ExecutionStep(
                action: 'import_from_google',
                description: 'Import campaign structure from Google Ads',
                params: ['google_campaign_id' => $campaign->google_ads_campaign_id],
            );
        } else {
            $steps[] = new ExecutionStep(
                action: 'create_campaign',
                description: 'Create new search campaign on Microsoft Ads',
                params: [
                    'name' => $campaign?->name ?? 'New Campaign',
                    'daily_budget' => ($campaign?->daily_budget ?? 50) * 0.3, // Start at 30% of Google budget
                ],
            );
            $steps[] = new ExecutionStep(
                action: 'create_ad_groups',
                description: 'Create ad groups with keywords and ads',
                params: [],
            );
        }

        $steps[] = new ExecutionStep(
            action: 'configure_tracking',
            description: 'Set up UET tag and conversion tracking',
            params: [],
        );

        return new ExecutionPlan(
            steps: $steps,
            reasoning: $useImport
                ? 'Fallback: Importing from existing Google Ads campaign for fastest deployment'
                : 'Fallback: Creating fresh campaign — no Google Ads source to import from',
            estimatedDuration: $useImport ? '5 minutes' : '15 minutes',
        );
    }

    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $errors = [];

        if (!$this->customer->microsoft_ads_customer_id) {
            $errors[] = 'Microsoft Ads customer ID not configured';
        }
        if (!$this->customer->microsoft_ads_account_id) {
            $errors[] = 'Microsoft Ads account ID not configured';
        }
        if (!config('microsoftads.developer_token')) {
            $errors[] = 'Microsoft Ads developer token not configured';
        }
        if (!config('microsoftads.client_id')) {
            $errors[] = 'Microsoft Ads OAuth client ID not configured';
        }

        return new ValidationResult(
            passed: empty($errors),
            errors: $errors,
        );
    }

    protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
    {
        $opportunities = [];

        // Microsoft Ads typically has lower CPCs
        $opportunities[] = [
            'type' => 'lower_cpc',
            'description' => 'Microsoft Ads typically offers 20-35% lower CPCs than Google Ads',
            'impact' => 'high',
        ];

        // LinkedIn profile targeting (unique to Microsoft)
        $opportunities[] = [
            'type' => 'linkedin_targeting',
            'description' => 'Target by company, industry, or job function via LinkedIn integration',
            'impact' => 'medium',
        ];

        // Import Google campaigns (fast setup)
        if ($context->campaign?->google_ads_campaign_id) {
            $opportunities[] = [
                'type' => 'google_import',
                'description' => 'Import existing Google Ads campaigns for quick setup',
                'impact' => 'high',
            ];
        }

        return new OptimizationAnalysis(
            opportunities: $opportunities,
            recommendedStrategy: 'Import from Google Ads and optimize for Microsoft audience',
        );
    }

    protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
    {
        $message = $error->getMessage();
        $this->logError('Analyzing execution error with AI', ['error' => $message]);

        $prompt = <<<PROMPT
Analyze this Microsoft Advertising API error and provide a recovery plan in JSON format.
Error Message: {$message}

Return a valid JSON object matching this structure EXACTLY:
{
    "actions": ["array", "of", "strings", "representing", "recovery", "steps"],
    "canAutoRecover": boolean,
    "reasoning": "string explaining why this error occurred and how to fix it"
}
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $prompt,
                config: [
                    'temperature' => 0.3,
                    'responseMimeType' => 'application/json',
                ],
                systemInstruction: 'You are an expert at diagnosing Microsoft Ads API errors. Provide actionable recovery steps.'
            );

            if ($response && isset($response['text'])) {
                return RecoveryPlan::fromJson($response['text']);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to generate recovery plan via AI', ['error' => $e->getMessage()]);
        }

        // Fallback to static rules
        $actions = [];
        if (str_contains($message, 'auth') || str_contains($message, 'token')) {
            $actions[] = 'Refresh OAuth token and retry';
        } elseif (str_contains($message, 'budget') || str_contains($message, 'Budget')) {
            $actions[] = 'Adjust budget to meet Microsoft Ads minimums';
        } elseif (str_contains($message, 'policy')) {
            $actions[] = 'Review ad copy for Microsoft Ads policy compliance';
        } else {
            $actions[] = 'Retry with exponential backoff';
        }

        return new RecoveryPlan(
            actions: $actions,
            canAutoRecover: str_contains($message, 'rate') || str_contains($message, 'timeout'),
            reasoning: "Static fallback analysis for: {$message}",
        );
    }

    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $results = [];

        foreach ($plan->steps as $step) {
            // Steps from AI come back as plain arrays; fallback plan uses ExecutionStep objects.
            $action = is_array($step) ? ($step['action'] ?? '') : ($step->action ?? '');
            $params = is_array($step) ? ($step['params'] ?? $step['parameters'] ?? []) : ($step->params ?? []);

            if ($action === '') {
                continue;
            }

            try {
                $stepResult = match ($action) {
                    'import_from_google'  => $this->executeGoogleImport($params),
                    'create_campaign'     => $this->executeCreateCampaign($params, $context),
                    'create_ad_groups',
                    'create_ads'          => $this->executeCreateAdGroups($context),
                    'configure_tracking',
                    'setup_tracking'      => $this->executeConfigureTracking(),
                    'create_extensions'   => ['status' => 'skipped', 'reason' => 'Extensions added during ad group creation'],
                    default               => ['skipped' => true, 'reason' => "Unhandled action: {$action}"],
                };
                $results[$action] = ['success' => true, 'data' => $stepResult];
            } catch (\Exception $e) {
                $results[$action] = ['success' => false, 'error' => $e->getMessage()];
                $this->logError("Step failed: {$action}", ['error' => $e->getMessage()]);
            }
        }

        $allSucceeded = !empty($results) && collect($results)->every(fn ($r) => $r['success']);

        return new ExecutionResult(
            success: $allSucceeded,
            message: $allSucceeded ? 'Microsoft Ads campaign deployed' : 'Partial deployment',
            data: $results,
        );
    }

    protected function getPlatformName(): string
    {
        return 'Microsoft Ads';
    }

    // ---- Step Implementations ----

    protected function executeGoogleImport(array $params): array
    {
        $importService = new ImportService($this->customer);
        $result = $importService->importFromGoogleAds($params['google_campaign_id']);
        return $result ?? ['status' => 'import_submitted'];
    }

    protected function executeCreateCampaign(array $params, ExecutionContext $context): array
    {
        $campaignService = new CampaignService($this->customer);
        $result = $campaignService->createSearchCampaign([
            'name' => $params['name'],
            'daily_budget' => $params['daily_budget'],
            'status' => 'Paused',
        ]);

        if ($result && isset($result['CampaignIds'])) {
            $msId = $result['CampaignIds']['long'][0] ?? $result['CampaignIds'][0] ?? null;
            if ($msId && $context->campaign) {
                $context->campaign->update(['microsoft_ads_campaign_id' => $msId]);
            }
        }

        return $result ?? ['error' => 'Campaign creation returned null'];
    }

    protected function executeCreateAdGroups(ExecutionContext $context): array
    {
        $campaign = $context->campaign;
        if (!$campaign || !$campaign->microsoft_ads_campaign_id) {
            return ['skipped' => 'No Microsoft campaign ID available'];
        }

        $adGroupService = new AdGroupService($this->customer);

        // Keywords from strategy bidding_strategy (buyer-intent, with match types)
        $biddingStrategy = $context->strategy?->bidding_strategy ?? [];
        $strategyKeywords = $biddingStrategy['keywords'] ?? [];

        $result = $adGroupService->createAdGroup($campaign->microsoft_ads_campaign_id, [
            'name'    => $campaign->name . ' - Search',
            'cpc_bid' => 1.50,
            'status'  => 'Active',
        ]);

        $adGroupId = null;
        if ($result && isset($result['AdGroupIds'])) {
            $adGroupId = $result['AdGroupIds']['long'][0] ?? $result['AdGroupIds'][0] ?? null;
        }

        if (!$adGroupId) {
            return ['error' => 'Ad group creation returned null', 'raw' => $result];
        }

        // Add keywords with proper match types
        $kwPayload = [];
        if (!empty($strategyKeywords)) {
            foreach (array_slice($strategyKeywords, 0, 30) as $kw) {
                $text = is_string($kw) ? $kw : ($kw['text'] ?? '');
                $matchType = $kw['match_type'] ?? 'Broad';
                // Microsoft uses Exact/Phrase/Broad (capitalised)
                $msMatch = match (strtoupper($matchType)) {
                    'EXACT'  => 'Exact',
                    'PHRASE' => 'Phrase',
                    default  => 'Broad',
                };
                if ($text) {
                    $kwPayload[] = ['text' => $text, 'match_type' => $msMatch, 'bid' => 1.50];
                }
            }
        }

        $kwResult = !empty($kwPayload)
            ? $adGroupService->addKeywords($adGroupId, $kwPayload)
            : ['skipped' => 'No keywords in strategy'];

        // Add a basic RSA using strategy ad copy guidance
        $adCopy = $context->strategy?->ad_copy_strategy ?? '';
        $adResult = $adGroupService->addExpandedTextAds($adGroupId, [[
            'headlines'    => [
                'Stop Paying Agency Retainers',
                'AI Ad Management $99/mo',
                '6 AI Agents Managing Your Ads',
                'No Retainers. No Setup Fees.',
                '24/7 Autonomous Ad Optimization',
            ],
            'descriptions' => [
                'Our AI agents manage your Google & Bing ads 24/7. Start for $99/mo — no retainers.',
                'Replace your agency with 6 AI agents. Self-healing campaigns. Deploy in 1 click.',
            ],
            'path1'      => 'AI-Ads',
            'path2'      => '99-mo',
            'final_url'  => $biddingStrategy['landing_page_url'] ?? $this->customer->website ?? 'https://sitetospend.com',
        ]]);

        return [
            'ad_group_id'    => $adGroupId,
            'keywords_added' => $kwResult,
            'ad_created'     => $adResult ? 'yes' : 'failed',
        ];
    }

    protected function executeConfigureTracking(): array
    {
        try {
            $trackingService = new ConversionTrackingService($this->customer);

            $tags = $trackingService->getUetTags();
            $tagId = null;

            if (!empty($tags)) {
                $tagId = $tags[0]['Id'] ?? null;
            } else {
                $newTag = $trackingService->createUetTag([
                    'name'        => $this->customer->name . ' UET',
                    'description' => 'Auto-provisioned by Sitetospend',
                ]);
                $tagId = $newTag['UetTagId'] ?? null;
            }

            if ($tagId) {
                $goals = $trackingService->getConversionGoals();
                if (empty($goals)) {
                    $trackingService->createUrlConversionGoal([
                        'name'              => 'Website Conversion',
                        'uet_tag_id'        => $tagId,
                        'url_expression'    => '/thank-you',
                        'conversion_window' => 30,
                    ]);
                }
            }

            return ['status' => 'tracking_configured', 'uet_tag_id' => $tagId];
        } catch (\Exception $e) {
            return ['status' => 'tracking_skipped', 'error' => $e->getMessage()];
        }
    }
}
