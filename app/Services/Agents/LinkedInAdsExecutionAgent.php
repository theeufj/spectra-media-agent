<?php

namespace App\Services\Agents;

use App\Services\CampaignStatusHelper;
use App\Services\LinkedInAds\CampaignService;
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
    /**
     * Statuses a step may report and still count as work landing on LinkedIn.
     *
     * Everything else — 'skipped' very much included — means nothing was
     * created. `$allSucceeded` used to be `status !== 'failed'`, so a run whose
     * every step returned 'skipped' ("No LinkedIn campaign ID", "No ad copy
     * available", "No landing page URL") was reported as a successful deploy:
     * DeploymentService wrote deployment_status='deployed', DeployCampaign
     * mailed "deployment completed", and because nothing retries a success the
     * campaign never existed and was never monitored, billed or paused.
     */
    private const SUCCESSFUL_STEP_STATUSES = ['success', 'already_deployed'];

    protected string $platform = 'linkedin';

    protected function getPlatformName(): string
    {
        return 'LinkedIn Ads';
    }

    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $errors = [];

        if (! $this->customer->linkedin_ads_account_id) {
            $errors[] = 'LinkedIn Ads account ID not configured';
        }

        $clientId = config('linkedinads.client_id');
        $clientSecret = config('linkedinads.client_secret');

        if (! $clientId || ! $clientSecret) {
            $errors[] = 'LinkedIn API credentials not configured';
        }

        if (! config('linkedinads.refresh_token')) {
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

NOTE: This integration creates text creatives only (headline, description,
destination URL). Do not plan image or video ads — media cannot be attached.

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

        // The prompt is built inline here rather than in app/Prompts, unlike the
        // other three platforms. A LinkedInAdsExecutionPrompt class did exist but
        // nothing referenced it, and it asked the model for "params" while this
        // agent reads "parameters" — wiring it up as-is would have silently
        // emptied every step's targeting and budget. It was removed rather than
        // left as a trap; moving this prompt into app/Prompts is still worth
        // doing, with executePlan()'s key as the contract.
        $result = $this->gemini->generateContent(config('ai.models.default'), $prompt, [
            'temperature' => 0.3,
            'maxOutputTokens' => 2048,
        ]);

        return ExecutionPlan::fromJson($result['text'] ?? '{}');
    }

    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $campaignService = $this->campaignService();
        $results = [];

        // LinkedIn has no separate targeting endpoint — targetingCriteria goes
        // up with the campaign — so the set_targeting step has to be read
        // before create_campaign runs, whatever order the planner emitted them
        // in. It was previously ignored outright: $createParams carried only
        // name/budget/objective/status, `createSponsoredContentCampaign()`'s
        // `if (! empty($params['targeting']))` never fired, buildTargetingCriteria()
        // was dead code, and every B2B campaign went up untargeted while this
        // agent logged that targeting had been applied.
        $targeting = $this->collectTargeting($plan);

        foreach ($plan->steps as $step) {
            $action = $this->stepAction($step);

            try {
                $result = match ($action) {
                    'create_campaign' => $this->executeCreateCampaign($campaignService, $step, $context, $targeting),
                    'set_targeting' => $this->executeSetTargeting($targeting),
                    'create_creatives' => $this->executeCreateCreatives($campaignService, $step, $context),
                    'setup_conversion_tracking' => $this->executeSetupTracking($campaignService),
                    default => ['status' => 'skipped', 'reason' => 'Unknown action'],
                };

                $results[] = array_merge(['step' => $action], $result);
            } catch (\Throwable $e) {
                // \Throwable, not \Exception: a TypeError from an SDK signature
                // change is exactly the per-step failure this loop exists to
                // contain, and \Exception does not catch \Error.
                report($e);
                $results[] = [
                    'step' => $action,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $failed = collect($results)->reject(
            fn ($r) => in_array($r['status'] ?? '', self::SUCCESSFUL_STEP_STATUSES, true)
        );

        $platformIds = [];
        if ($context->campaign->linkedin_campaign_id) {
            $platformIds['campaign'] = (string) $context->campaign->linkedin_campaign_id;
        }

        $errors = $failed->map(fn ($r, $i) => new AgentIssue(
            'linkedin_step_failed',
            ($r['step'] ?? "step {$i}").': '.($r['error'] ?? $r['reason'] ?? 'failed with no reason given'),
        ))->values()->all();

        // A plan whose steps all "succeeded" but left no campaign URN behind is
        // the same silent failure in a different disguise — every downstream
        // consumer (MonitorCampaignStatus, FetchLinkedInAdsPerformanceData,
        // AdSpendBillingService, DeactivateCustomerService) keys off
        // linkedin_campaign_id.
        if ($errors === [] && $platformIds === []) {
            $errors[] = new AgentIssue(
                'no_platform_ids',
                'No LinkedIn campaign ID was recorded — nothing was created on the platform',
            );
        }

        return new ExecutionResult(
            success: $errors === [],
            errors: $errors,
            platformIds: $platformIds,
            metadata: ['steps' => $results],
        );
    }

    /**
     * Build the LinkedIn service the plan runs against.
     *
     * A seam, not indirection for its own sake: executePlan() is the only
     * behaviour worth testing here and it cannot be exercised without one.
     */
    protected function campaignService(): CampaignService
    {
        return new CampaignService($this->customer);
    }

    /**
     * The set_targeting step's parameters, if the planner emitted one.
     */
    protected function collectTargeting(ExecutionPlan $plan): array
    {
        foreach ($plan->steps as $step) {
            if ($this->stepAction($step) === 'set_targeting') {
                return $this->stepParams($step);
            }
        }

        return [];
    }

    /**
     * Steps arrive as plain arrays from the planner and as ExecutionStep
     * objects from a fallback plan. Reading both shapes with `??` alone is not
     * safe — `$object['key']` is a fatal Error, not a suppressible notice.
     */
    protected function stepAction(mixed $step): string
    {
        return (string) (is_array($step) ? ($step['action'] ?? '') : ($step->action ?? ''));
    }

    protected function stepParams(mixed $step): array
    {
        if (is_array($step)) {
            return (array) ($step['parameters'] ?? $step['params'] ?? []);
        }

        return (array) ($step->parameters ?? $step->params ?? []);
    }

    protected function executeCreateCampaign(CampaignService $service, $step, ExecutionContext $context, array $targeting = []): array
    {
        $params = $this->stepParams($step);
        $campaign = $context->campaign;

        // Idempotency: skip if this campaign was already deployed to LinkedIn
        if ($campaign && $campaign->linkedin_campaign_id) {
            Log::info('[LinkedInAdsExecutionAgent] Campaign already deployed to LinkedIn, skipping creation', [
                'campaign_id' => $campaign->id,
                'linkedin_campaign_id' => $campaign->linkedin_campaign_id,
            ]);

            return ['status' => 'already_deployed', 'linkedin_campaign_id' => $campaign->linkedin_campaign_id];
        }

        $campaignType = $params['campaign_type'] ?? 'SPONSORED_UPDATES';

        $createParams = [
            'name' => $campaign?->name ?? 'New LinkedIn Campaign',
            'daily_budget' => $params['daily_budget'] ?? $context->strategy->daily_budget ?? 50,
            'objective' => $params['objective'] ?? 'WEBSITE_VISITS',
            'status' => $this->deployStatus(),
            'targeting' => $targeting,
        ];

        $result = match ($campaignType) {
            'SPONSORED_INMAILS' => $service->createMessageAdsCampaign($createParams),
            default => $service->createSponsoredContentCampaign($createParams),
        };

        // The campaign URN is the whole point of this step. LinkedIn's REST
        // CREATE answers 201 with an empty body and the id in the `x-restli-id`
        // header, which BaseLinkedInAdsService::apiCall() discards — so a
        // truthy `['success' => true]` came back, `$result['id']` was null,
        // linkedin_campaign_id stayed null, and the step still reported
        // success. Nothing downstream can find a campaign without the URN, so
        // no id means the step failed.
        $campaignId = $result['id'] ?? null;

        if (! $campaignId) {
            return [
                'status' => 'failed',
                'error' => 'LinkedIn did not return a campaign ID',
                'result' => $result,
            ];
        }

        $campaign->update(['linkedin_campaign_id' => $campaignId]);

        return ['status' => 'success', 'linkedin_campaign_id' => $campaignId, 'result' => $result];
    }

    protected function executeSetTargeting(array $targeting): array
    {
        // Targeting is applied inside createSponsoredContentCampaign() via
        // targetingCriteria — there is genuinely no separate call — but this
        // step used to log that unconditionally while the parameters it was
        // reporting on were being thrown away.
        if ($targeting === []) {
            return ['status' => 'skipped', 'reason' => 'Plan carried no targeting facets to send with the campaign'];
        }

        Log::info('[LinkedInAdsExecutionAgent] Targeting sent with the campaign as targetingCriteria', [
            'customer_id' => $this->customer->id,
            'facets' => array_keys($targeting),
        ]);

        return ['status' => 'success', 'facets' => array_keys($targeting)];
    }

    /**
     * Status a freshly created LinkedIn campaign should launch with.
     *
     * This was hardcoded 'PAUSED'. Nothing ever turned those campaigns on:
     * ActivateCampaigns::handle() dispatches for google and facebook and
     * `continue`s otherwise, and SelfHealingAgent's zero-impressions branch
     * needs performance rows a paused campaign never produces. Meanwhile
     * DeployCampaign flipped the local row to Active and mailed "deployment
     * completed". CampaignStatusHelper is where testing mode and
     * `campaigns.default_status` are resolved for the other platforms;
     * LinkedIn's campaign status vocabulary is ACTIVE/PAUSED, the same strings
     * it already resolves for Facebook, so this reuses that decision rather
     * than growing a third copy of it.
     */
    protected function deployStatus(): string
    {
        return CampaignStatusHelper::getFacebookAdsStatus();
    }

    protected function executeCreateCreatives(CampaignService $service, $step, ExecutionContext $context): array
    {
        $campaign = $context->campaign;
        $strategy = $context->strategy;

        $campaignId = $campaign->linkedin_campaign_id ?? null;
        if (! $campaignId) {
            return ['status' => 'skipped', 'reason' => 'No LinkedIn campaign ID — campaign creation must succeed first'];
        }

        $adCopy = $strategy->adCopies()
            ->whereRaw('LOWER(platform) LIKE ?', ['%linkedin%'])
            ->first()
            ?? $strategy->adCopies()->first();

        if (! $adCopy) {
            Log::warning('[LinkedInAdsExecutionAgent] No ad copy for creative creation', ['strategy_id' => $strategy->id]);

            return ['status' => 'skipped', 'reason' => 'No ad copy available'];
        }

        $landingUrl = $campaign->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $this->customer->website
            ?? null;

        if (! $landingUrl) {
            Log::warning('[LinkedInAdsExecutionAgent] No landing URL for creative', ['strategy_id' => $strategy->id]);

            return ['status' => 'skipped', 'reason' => 'No landing page URL'];
        }

        $creative = $service->createCreative($campaignId, [
            'headline' => $adCopy->headlines[0] ?? 'Learn More',
            'description' => $adCopy->descriptions[0] ?? '',
            'destination' => $landingUrl,
        ]);

        if ($creative) {
            Log::info('[LinkedInAdsExecutionAgent] Created LinkedIn creative', [
                'campaign_id' => $campaign->id,
                'creative' => $creative,
            ]);
            $strategy->update(['linkedin_creative_id' => $creative['id'] ?? null]);

            return ['status' => 'success', 'creative' => $creative];
        }

        return ['status' => 'failed', 'reason' => 'LinkedIn API did not return a creative ID'];
    }

    protected function executeSetupTracking(CampaignService $service): array
    {
        $tag = $service->getInsightTag();

        if (! $tag) {
            return [
                'status' => 'skipped',
                'reason' => 'LinkedIn returned no Insight Tag for the ad account — conversions cannot be tracked or billed',
            ];
        }

        return ['status' => 'success', 'insight_tag' => $tag];
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

    protected function handleExecutionError(\Throwable $error, ExecutionContext $context): RecoveryPlan
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
