<?php

namespace App\Services\Agents;

use App\Services\CampaignStatusHelper;
use App\Services\MicrosoftAds\AdGroupService;
use App\Services\MicrosoftAds\AssetService;
use App\Services\MicrosoftAds\CampaignService;
use App\Services\MicrosoftAds\ConversionTrackingService;
use App\Services\MicrosoftAds\ImportService;
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

            if (! $response || ! isset($response['text'])) {
                throw new \Exception('Empty response from AI model');
            }

            return ExecutionPlan::fromJson($response['text']);
        } catch (\Throwable $e) {
            report($e);
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

        if (! $this->customer->microsoft_ads_customer_id) {
            $errors[] = 'Microsoft Ads customer ID not configured';
        }
        if (! $this->customer->microsoft_ads_account_id) {
            $errors[] = 'Microsoft Ads account ID not configured';
        }
        if (! config('microsoftads.developer_token')) {
            $errors[] = 'Microsoft Ads developer token not configured';
        }
        if (! config('microsoftads.client_id')) {
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

    protected function handleExecutionError(\Throwable $error, ExecutionContext $context): RecoveryPlan
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
        } catch (\Throwable $e) {
            report($e);
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
        $startTime = microtime(true);
        $platformIds = [];

        foreach ($plan->steps as $step) {
            // Steps from AI come back as plain arrays; fallback plan uses ExecutionStep objects.
            $action = is_array($step) ? ($step['action'] ?? '') : ($step->action ?? '');
            $params = is_array($step) ? ($step['params'] ?? $step['parameters'] ?? []) : ($step->params ?? []);

            if ($action === '') {
                continue;
            }

            try {
                $stepResult = match ($action) {
                    'import_from_google' => $this->executeGoogleImport($params),
                    'create_campaign' => $this->executeCreateCampaign($params, $context),
                    'create_ad_groups',
                    'create_ads' => $this->executeCreateAdGroups($context),
                    'configure_tracking',
                    'setup_tracking' => $this->executeConfigureTracking(),
                    'create_extensions' => ['status' => 'not_required', 'reason' => 'Extensions added during ad group creation'],
                    default => throw new \RuntimeException("Unhandled plan action: {$action}"),
                };

                // Collect any platform IDs returned by the step
                if (isset($stepResult['microsoft_ads_campaign_id'])) {
                    $platformIds['campaign'] = $stepResult['microsoft_ads_campaign_id'];
                }
                if (isset($stepResult['ad_group_id'])) {
                    $platformIds['ad_group'] = $stepResult['ad_group_id'];
                }
                if (isset($stepResult['import_job_id'])) {
                    $platformIds['import_job'] = $stepResult['import_job_id'];
                }

                $failure = $this->stepFailureReason($stepResult);

                if ($failure !== null) {
                    $results[$action] = ['success' => false, 'error' => $failure, 'data' => $stepResult];
                    $this->logError("Step reported no work done: {$action}", ['reason' => $failure]);

                    continue;
                }

                $results[$action] = ['success' => true, 'data' => $stepResult];
            } catch (\Throwable $e) {
                report($e);
                $results[$action] = ['success' => false, 'error' => $e->getMessage()];
                $this->logError("Step failed: {$action}", ['error' => $e->getMessage()]);
            }
        }

        $executionTime = microtime(true) - $startTime;
        $anyRealWork = ! empty($results);

        $errors = [];

        if (! $anyRealWork) {
            $errors[] = new AgentIssue(
                'no_steps_executed',
                'No steps were executed — check plan generation',
            );
        } else {
            foreach ($results as $i => $result) {
                if ($result['success']) {
                    continue;
                }

                $errors[] = new AgentIssue(
                    'microsoft_step_failed',
                    sprintf('step %s: %s', $i, $result['error']),
                );
            }
        }

        // Every step can pass and still leave nothing behind — an import that
        // came back without a job id, a campaign that was created without an
        // id being read off the response. Downstream (MonitorCampaignStatus,
        // the performance fetch, AdSpendBillingService) keys off
        // microsoft_ads_campaign_id, so no identifier means no deployment,
        // whatever the steps said.
        if ($errors === [] && $platformIds === []) {
            $errors[] = new AgentIssue(
                'no_platform_ids',
                'No Microsoft Ads identifiers were recorded — nothing was created on the platform',
            );
        }

        return new ExecutionResult(
            success: $errors === [],
            errors: $errors,
            platformIds: $platformIds,
            executionTime: $executionTime,
            metadata: ['steps' => $results],
        );
    }

    /**
     * Why a step's payload means nothing landed on Microsoft, or null if it did.
     *
     * The step implementations report trouble by return value —
     * `['error' => 'Campaign creation returned null']`, `['skipped' => …]`,
     * `['status' => 'import_failed']`, `['status' => 'tracking_skipped']` — and
     * this loop used to record `['success' => true]` for anything that returned
     * without throwing. A run could therefore skip the Google import, create
     * nothing, and still be written back as `deployment_status='deployed'`
     * with a "deployment completed" email. Nothing retries a success, so those
     * campaigns stayed non-existent forever.
     *
     * Only top-level keys count. Nested best-effort results — image extensions,
     * keyword additions — carry their own 'skipped' and are enhancements to a
     * campaign that does exist, not the campaign itself.
     */
    protected function stepFailureReason(array $payload): ?string
    {
        if (isset($payload['error']) && $payload['error'] !== '') {
            return is_string($payload['error']) ? $payload['error'] : json_encode($payload['error']);
        }

        if (isset($payload['skipped'])) {
            return is_string($payload['skipped']) ? $payload['skipped'] : 'step skipped';
        }

        $status = (string) ($payload['status'] ?? '');

        if ($status === 'failed' || $status === 'skipped'
            || str_ends_with($status, '_failed') || str_ends_with($status, '_skipped')) {
            return $payload['reason'] ?? $status;
        }

        return null;
    }

    /**
     * Status a freshly created Microsoft campaign should launch with.
     *
     * This was hardcoded 'Paused'. Nothing ever turned those campaigns on:
     * ActivateCampaigns::handle() dispatches for google and facebook and
     * `continue`s otherwise, and SelfHealingAgent's zero-impressions branch
     * needs performance rows a paused campaign never produces. Meanwhile
     * DeployCampaign flipped the local row to Active and mailed "deployment
     * completed". The ENABLED/PAUSED decision (testing mode plus
     * `campaigns.default_status`) is the one CampaignStatusHelper already makes
     * for Facebook, whose ACTIVE/PAUSED vocabulary maps one-for-one onto
     * Microsoft's title-case Active/Paused — reuse it rather than growing a
     * fourth copy of the rule.
     */
    protected function deployStatus(): string
    {
        return CampaignStatusHelper::getFacebookAdsStatus() === 'PAUSED' ? 'Paused' : 'Active';
    }

    /**
     * The messages in a Microsoft `PartialErrors` block, if there are any.
     *
     * Add* operations answer 200 with PartialErrors rather than a SoapFault
     * when an entity is rejected, so a non-null response is not evidence that
     * anything was created — which is how ads with no headlines were recorded
     * as `'ad_created' => 'yes'`.
     */
    protected function partialErrorMessage(?array $response): ?string
    {
        $errors = $response['PartialErrors']['BatchError'] ?? $response['PartialErrors'] ?? null;

        if (empty($errors)) {
            return null;
        }

        if (! array_is_list($errors)) {
            $errors = [$errors];
        }

        $messages = array_filter(array_map(
            fn ($e) => is_array($e) ? trim(($e['Code'] ?? '').' '.($e['Message'] ?? '')) : (string) $e,
            $errors,
        ));

        return $messages === [] ? 'Microsoft rejected the request' : implode('; ', $messages);
    }

    protected function getPlatformName(): string
    {
        return 'Microsoft Ads';
    }

    // ---- Step Implementations ----

    protected function executeGoogleImport(array $params): array
    {
        // AddImportJobs takes the Google Ads ACCOUNT to import from. This used
        // to pass $params['google_campaign_id'], so every import job went up
        // with a campaign id sitting in the GoogleAccountId field — which is
        // the primary deploy route for Microsoft, and it never worked.
        $googleAccountId = $params['google_account_id']
            ?? $params['google_ads_customer_id']
            ?? $this->customer->google_ads_customer_id;

        // Google shows ids as 123-456-7890; the API wants digits only.
        $googleAccountId = $googleAccountId ? preg_replace('/\D/', '', (string) $googleAccountId) : null;

        if (! $googleAccountId) {
            $this->logError('Google import skipped: customer has no linked Google Ads account');

            return ['status' => 'import_skipped', 'reason' => 'no_google_ads_account'];
        }

        $result = (new ImportService($this->customer))->importFromGoogleAds($googleAccountId);

        if (! $result) {
            return ['status' => 'import_failed', 'google_account_id' => $googleAccountId];
        }

        // Keep the job id so the import can actually be followed up on;
        // getImportJobStatus() had nothing to poll with before.
        $ids = $result['ImportJobIds']['long'] ?? $result['ImportJobIds'] ?? null;
        $jobId = is_array($ids) ? ($ids[0] ?? null) : $ids;

        return [
            'status' => 'import_submitted',
            'google_account_id' => $googleAccountId,
            'import_job_id' => $jobId,
        ];
    }

    protected function executeCreateCampaign(array $params, ExecutionContext $context): array
    {
        // Idempotency: skip if this campaign was already deployed to Microsoft Ads
        if ($context->campaign && $context->campaign->microsoft_ads_campaign_id) {
            Log::info('[MicrosoftAdsExecutionAgent] Campaign already deployed to Microsoft Ads, skipping creation', [
                'campaign_id' => $context->campaign->id,
                'microsoft_ads_campaign_id' => $context->campaign->microsoft_ads_campaign_id,
            ]);

            return ['status' => 'already_deployed', 'microsoft_ads_campaign_id' => $context->campaign->microsoft_ads_campaign_id];
        }

        $campaignService = new CampaignService($this->customer);
        $name = $params['name'] ?? $params['campaign_name'] ?? $context->campaign?->name ?? 'Sitetospend Campaign';
        $dailyBudget = $params['daily_budget'] ?? $params['budget'] ?? $context->strategy?->daily_budget ?? 10.00;

        $result = $campaignService->createSearchCampaign([
            'name' => $name,
            'daily_budget' => (float) $dailyBudget,
            'status' => $this->deployStatus(),
        ]);

        // `isset($result['CampaignIds'])` was the whole check, and AddCampaigns
        // returns that element whether or not the campaign was accepted — a
        // rejected one comes back as a null id alongside a PartialErrors block.
        // Require the id itself.
        $msId = $result['CampaignIds']['long'][0] ?? $result['CampaignIds'][0] ?? null;

        if (! $msId) {
            return [
                'error' => 'Microsoft Ads returned no campaign ID: '
                    .($this->partialErrorMessage($result) ?? 'empty response from AddCampaigns'),
            ];
        }

        $context->campaign->update(['microsoft_ads_campaign_id' => $msId]);

        return ['status' => 'created', 'microsoft_ads_campaign_id' => $msId];
    }

    protected function executeCreateAdGroups(ExecutionContext $context): array
    {
        $campaign = $context->campaign;
        if (! $campaign || ! $campaign->microsoft_ads_campaign_id) {
            return ['skipped' => 'No Microsoft campaign ID available'];
        }

        $adGroupService = new AdGroupService($this->customer);

        // Keywords from strategy bidding_strategy (buyer-intent, with match types)
        $biddingStrategy = $context->strategy?->bidding_strategy ?? [];
        $strategyKeywords = $biddingStrategy['keywords'] ?? [];

        $result = $adGroupService->createAdGroup($campaign->microsoft_ads_campaign_id, [
            'name' => $campaign->name.' - Search',
            'cpc_bid' => 1.50,
            'status' => 'Active',
        ]);

        $adGroupId = null;
        if ($result && isset($result['AdGroupIds'])) {
            $adGroupId = $result['AdGroupIds']['long'][0] ?? $result['AdGroupIds'][0] ?? null;
        }

        if (! $adGroupId) {
            return ['error' => 'Ad group creation returned null', 'raw' => $result];
        }

        // Add keywords with proper match types
        $kwPayload = [];
        if (! empty($strategyKeywords)) {
            foreach (array_slice($strategyKeywords, 0, 30) as $kw) {
                $text = is_string($kw) ? $kw : ($kw['text'] ?? '');
                $matchType = $kw['match_type'] ?? 'Broad';
                // Microsoft uses Exact/Phrase/Broad (capitalised)
                $msMatch = match (strtoupper($matchType)) {
                    'EXACT' => 'Exact',
                    'PHRASE' => 'Phrase',
                    default => 'Broad',
                };
                if ($text) {
                    $kwPayload[] = ['text' => $text, 'match_type' => $msMatch, 'bid' => 1.50];
                }
            }
        }

        $kwResult = ! empty($kwPayload)
            ? $adGroupService->addKeywords($adGroupId, $kwPayload)
            : ['skipped' => 'No keywords in strategy'];

        // Source ad copy from strategy — prefer Microsoft-specific copy, fall back to any copy
        $adCopy = $context->strategy->adCopies()
            ->whereRaw('LOWER(platform) LIKE ?', ['%microsoft%'])
            ->first()
            ?? $context->strategy->adCopies()->first();

        $headlines = $adCopy?->headlines ?? [];
        $descriptions = $adCopy?->descriptions ?? [];

        // An ad group with no ad in it serves nothing, so these are deploy
        // failures rather than notes — they used to be recorded as successful
        // steps and the strategy was marked deployed.
        if (empty($headlines)) {
            Log::warning('[MicrosoftAdsExecutionAgent] No ad copy found in strategy, skipping ad creation', [
                'strategy_id' => $context->strategy->id,
            ]);

            return [
                'ad_group_id' => $adGroupId,
                'keywords_added' => $kwResult,
                'error' => 'Ad group created but no ad — the strategy has no ad copy',
            ];
        }

        $finalUrl = $biddingStrategy['landing_page_url']
            ?? $this->customer->website
            ?? null;

        if (! $finalUrl) {
            Log::warning('[MicrosoftAdsExecutionAgent] No landing page URL for ad, skipping ad creation', [
                'strategy_id' => $context->strategy->id,
            ]);

            return [
                'ad_group_id' => $adGroupId,
                'keywords_added' => $kwResult,
                'error' => 'Ad group created but no ad — no landing page URL on the campaign, strategy or customer',
            ];
        }

        $adResult = $adGroupService->addExpandedTextAds($adGroupId, [[
            'headlines' => array_slice($headlines, 0, 15),
            'descriptions' => array_slice($descriptions, 0, 4),
            'path1' => 'AI-Ads',
            'path2' => 'Managed',
            'final_url' => $finalUrl,
        ]]);

        // AddAds answers 200 with a PartialErrors block when it rejects an ad,
        // so `$adResult ? 'yes' : 'failed'` called every rejection a success.
        $adError = $adResult === null
            ? 'AddAds returned nothing'
            : $this->partialErrorMessage($adResult);

        if ($adError !== null) {
            return [
                'ad_group_id' => $adGroupId,
                'keywords_added' => $kwResult,
                'error' => 'Ad group created but the ad was rejected: '.$adError,
            ];
        }

        // Microsoft was the one platform that deployed no customer media at
        // all — AssetService's image upload existed with no caller. Best
        // effort: extensions are an enhancement, never a reason to fail the
        // text ads that just went live.
        $imageExtensions = $this->attachImageExtensions($context, $finalUrl);

        return [
            'ad_group_id' => $adGroupId,
            'keywords_added' => $kwResult,
            'ad_created' => 'yes',
            'image_extensions' => $imageExtensions,
        ];
    }

    /**
     * Upload the strategy's deployable images as image ad extensions and
     * associate them with the Microsoft campaign.
     */
    protected function attachImageExtensions(ExecutionContext $context, string $finalUrl): array
    {
        try {
            $campaign = $context->campaign;
            $strategy = $context->strategy;

            if (! $campaign->microsoft_ads_campaign_id) {
                return ['skipped' => 'no_microsoft_campaign_id'];
            }

            $images = \App\Models\ImageCollateral::forStrategy($strategy)
                ->where('is_active', true)
                ->where('should_deploy', true)
                ->limit(5)
                ->get();

            if ($images->isEmpty()) {
                return ['skipped' => 'no_images'];
            }

            $assetService = new AssetService($this->customer);
            $extensionIds = [];

            foreach ($images as $image) {
                $data = \App\Services\StorageHelper::get($image->s3_path);
                if (! $data) {
                    continue;
                }

                $mediaId = $assetService->uploadImage($data);
                if (! $mediaId) {
                    continue;
                }

                $extension = $assetService->createImageExtension([
                    'media_id' => $mediaId,
                    'final_url' => $finalUrl,
                ]);

                // The SOAP response nests identities; tolerate both shapes.
                $identities = $extension['AdExtensionIdentities']['AdExtensionIdentity']
                    ?? $extension['AdExtensionIdentities']
                    ?? [];
                $first = $identities[0] ?? $identities;
                if (! empty($first['Id'])) {
                    $extensionIds[] = $first['Id'];
                }
            }

            if (empty($extensionIds)) {
                return ['uploaded' => 0];
            }

            $linked = $assetService->linkExtensionsToCampaign(
                (string) $campaign->microsoft_ads_campaign_id,
                $extensionIds,
                'Image'
            );

            return ['uploaded' => count($extensionIds), 'linked' => $linked];
        } catch (\Throwable $e) {
            Log::warning('[MicrosoftAdsExecutionAgent] Image extension attachment failed: '.$e->getMessage(), [
                'strategy_id' => $context->strategy->id,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    protected function executeConfigureTracking(): array
    {
        try {
            $trackingService = new ConversionTrackingService($this->customer);

            // resolveUetTagId() finds or creates the tag and persists it to
            // customers.microsoft_uet_tag_id. The inline version this replaces
            // read $newTag['UetTagId'], which createUetTag() never returns — it
            // returns ['UetTags' => ['UetTag' => [...]]] — so the create branch
            // always produced null and the id was never stored on the customer.
            $tagId = $trackingService->resolveUetTagId();

            if (! $tagId) {
                return [
                    'status' => 'tracking_failed',
                    'error' => 'No UET tag could be found or created — conversions cannot be tracked or billed',
                ];
            }

            $goals = $trackingService->getConversionGoals();
            if (empty($goals)) {
                $trackingService->createUrlConversionGoal([
                    'name' => 'Website Conversion',
                    'uet_tag_id' => $tagId,
                    // createUrlConversionGoal() reads 'url_contains' and
                    // 'conversion_window_minutes'. The old 'url_expression'
                    // and 'conversion_window' keys were silently ignored,
                    // so UrlExpression went up null and matched nothing.
                    'url_contains' => '/thank-you',
                    'conversion_window_minutes' => 43200,
                ]);
            }

            return ['status' => 'tracking_configured', 'uet_tag_id' => $tagId];
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'tracking_skipped', 'error' => $e->getMessage()];
        }
    }
}
