<?php

namespace App\Services\Agents\Google;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\CampaignStatusHelper;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Support\Facades\Log;

/**
 * Sources, validates and attaches keywords (and initial negatives) for search
 * campaigns.
 */
class SearchKeywordBuilder
{
    public function __construct(protected Customer $customer) {}

    public function getKeywords(Campaign $campaign, Strategy $strategy, ExecutionPlan $plan): array
    {
        $keywords = [];

        // 1. Check campaign-level keywords (highest priority)
        if (! empty($campaign->keywords)) {
            $keywords = $campaign->keywords;
            Log::info('GoogleAdsExecutionAgent: Using campaign keywords', ['count' => count($keywords)]);

            return $this->normalizeKeywords($keywords);
        }

        // 2. Check targeting config keywords
        $targetingConfig = $strategy->targetingConfig;
        if ($targetingConfig && isset($targetingConfig->google_options['keywords']) && ! empty($targetingConfig->google_options['keywords'])) {
            $keywords = $targetingConfig->google_options['keywords'];
            Log::info('GoogleAdsExecutionAgent: Using targeting config keywords', ['count' => count($keywords)]);

            return $this->normalizeKeywords($keywords);
        }

        // Use the reviewed strategy before an execution agent's optional suggestions.
        if (! empty($strategy->bidding_strategy['keywords'])) {
            return $this->normalizeKeywords($strategy->bidding_strategy['keywords'], false);
        }

        $creativeStrategy = $plan->getCreativeStrategy();
        if (! empty($creativeStrategy['keywords'])) {
            return $this->normalizeKeywords($creativeStrategy['keywords'], false);
        }
        foreach ($plan->steps as $step) {
            if (($step['action'] ?? '') === 'add_keywords' && ! empty($step['parameters']['keywords'])) {
                return $this->normalizeKeywords($step['parameters']['keywords'], false);
            }
        }

        Log::warning('GoogleAdsExecutionAgent: No keywords found for campaign');

        return [];
    }

    /**
     * Add keywords to ad group
     */
    public function addKeywords(string $customerId, string $adGroupResourceName, array $keywords, ExecutionResult $result): void
    {
        $addCriterionService = new AddAdGroupCriterion($this->customer);

        foreach ($keywords as $keyword) {
            try {
                $keywordText = is_array($keyword) ? ($keyword['text'] ?? $keyword['keyword'] ?? '') : $keyword;
                $matchType = is_array($keyword) && isset($keyword['match_type'])
                    ? $keyword['match_type']
                    : 'PHRASE';

                if (empty($keywordText)) {
                    continue;
                }

                $criterionResourceName = ($addCriterionService)($customerId, $adGroupResourceName, [
                    'type' => 'KEYWORD',
                    'text' => $keywordText,
                    'matchType' => $matchType,
                ]);
                if ($criterionResourceName) {
                    $result->addPlatformId('keyword', $criterionResourceName);
                }
            } catch (\Throwable $e) {
                report($e);
                $result->addWarning('Failed to add keyword: '.$e->getMessage());
            }
        }
    }

    /** Enrich the selected set with metrics; never replace it with Planner suggestions. */
    public function validateAndEnrichKeywords(string $customerId, array $keywords, Campaign $campaign, Strategy $strategy): array
    {
        $keywords = $this->normalizeKeywords($keywords);
        if ($keywords === []) {
            return [];
        }
        $ideaMap = [];
        try {
            $generateIdeas = app(GenerateKeywordIdeas::class, ['customer' => $this->customer]);
            $ideas = ($generateIdeas)($customerId, array_slice(array_column($keywords, 'text'), 0, 20), $this->landingPage($campaign, $strategy));
            foreach ($ideas as $idea) {
                $ideaMap[mb_strtolower($idea['keyword'])] = $idea;
            }
            foreach ($keywords as &$keyword) {
                $idea = $ideaMap[mb_strtolower($keyword['text'])] ?? [];
                // Missing from a suggestions response does not mean zero search volume.
                $keyword['avg_monthly_searches'] = $idea['avg_monthly_searches'] ?? null;
                $keyword['competition_index'] = $idea['competition_index'] ?? null;
            }
            unset($keyword);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Keyword metrics unavailable; preserving selected keywords', ['campaign_id' => $campaign->id]);
        }

        return $keywords;
    }

    /** Check the staged campaign's actual bid, match types and targeting before adding serving ads. */
    public function preflightReach(Campaign $campaign, Strategy $strategy, string $customerId, string $campaignResource, ExecutionResult $result): bool
    {
        $campaign->refresh()->load('customer');
        if ($campaign->customer?->cleanGoogleCustomerId() !== $customerId
            || $campaign->customer_id !== $this->customer->id
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaignResource)) {
            $result->addError('search_reach_account_mismatch', 'The staged Google campaign does not match this customer account. Deployment requires review.');

            return false;
        }
        try {
            $initialStatus = $campaign->status;
            $snapshot = $this->inspectStage($campaign, $customerId, $campaignResource);
            $fingerprint = GoogleSearchReachGate::settingsFingerprint($snapshot);
            $enabledKeywords = array_values(array_filter($snapshot['keywords'] ?? [], fn ($keyword) => ($keyword['status'] ?? 'ENABLED') === 'ENABLED'
                && ($keyword['ad_group_status'] ?? 'ENABLED') === 'ENABLED'));
            $assessment = app(GoogleSearchReachPlanner::class)->assessMutation($campaign, $snapshot, $enabledKeywords);
            // Provider research can take minutes. Authorize against current
            // customer approval and a new remote read, never the old settings.
            $campaign->refresh()->load('customer');
            $snapshot = $this->inspectStage($campaign, $customerId, $campaignResource);
            $campaign->refresh()->load('customer');
            $gate = app(GoogleSearchReachGate::class)->evaluateInitialLaunch($campaign, $snapshot, $assessment);
            if ($campaign->customer?->cleanGoogleCustomerId() !== $customerId || $campaign->customer_id !== $this->customer->id) {
                $gate = $this->holdGate($gate, 'search_reach_account_mismatch', 'The customer account changed while researching reach. Review the staged resource.');
            }
            if ($fingerprint !== GoogleSearchReachGate::settingsFingerprint($snapshot)) {
                $gate = $this->holdGate($gate, 'search_reach_settings_changed_during_forecast', 'Google Search settings changed while researching reach. Repeat the check with the current settings.');
            }
            if (($snapshot['status'] ?? null) !== GoogleCampaignStatus::PAUSED) {
                $gate = $this->holdGate($gate, 'search_reach_staging_pause_unverified', 'The staged Google Search campaign is no longer verified paused.');
            }
            if (($campaign->status === CampaignStatus::Paused && $initialStatus !== CampaignStatus::Paused)
                || $campaign->hasPassedEndDate() || in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Ended], true)) {
                $gate = $this->holdGate($gate, 'search_reach_launch_authorization_changed', 'The owner paused or ended this campaign while researching reach. Review the current launch authorization.');
            }
            $result->addMetadata('search_reach_preflight', [...$assessment, 'gate' => $gate,
                'validated_campaign_resource' => $campaignResource, 'validated_settings_fingerprint' => GoogleSearchReachGate::settingsFingerprint($snapshot)]);
            $blocked = ! $gate['ready'];
            $firstGateIssue = $gate['issues'][0] ?? null;
            $state = [
                'status' => $blocked ? ($gate['status'] === 'unavailable' ? 'unavailable' : 'needs_review') : 'collecting_evidence',
                'checked_at' => now()->toIso8601String(), 'evaluation_started_at' => now()->toIso8601String(),
                'currency_code' => $snapshot['currency_code'] ?? null,
                'diagnosis' => [...$assessment, 'issues' => [...($assessment['issues'] ?? []), ...$gate['issues']]],
                'reach_gate' => $gate, 'phase' => 'prelaunch', 'strategy_id' => $strategy->id,
                'campaign_resource' => $campaignResource,
                'blocked_reason' => $firstGateIssue['code'] ?? null,
                'mutation_allowed' => false,
            ];
            // The campaign card monitors its primary Google campaign. A second
            // Search strategy keeps its own assessment in execution metadata.
            if ($campaign->googleAdsResourceName() === $campaignResource || ! $campaign->google_ads_campaign_id) {
                $this->savePreflightState($campaign, $state);
            }
            foreach (\App\Services\Agents\AgentIssue::list($assessment['issues'] ?? []) as $assessmentIssue) {
                $result->addWarning($assessmentIssue->code, $assessmentIssue->message);
            }
            foreach (\App\Services\Agents\AgentIssue::list($gate['warnings'] ?? []) as $warning) {
                $result->addWarning($warning->code, $warning->message);
            }
            AgentActivity::record('deployment', $blocked ? 'search_reach_preflight_blocked' : 'search_reach_preflight_checked',
                $blocked ? 'Search launch held: '.($firstGateIssue['message'] ?? 'Keyword reach could not be verified.')
                    : ($gate['status'] === 'estimated_with_limitations'
                        ? 'Useful Search traffic is estimated within the staged spending settings. Retained negative keywords are not modeled; actual traffic may be lower.'
                        : 'Verified useful Search keyword reach within the staged bid, targeting and approved spending limits.'),
                $campaign->customer_id, $campaign->id, ['assessment' => $assessment, 'gate' => $gate]);
            foreach (\App\Services\Agents\AgentIssue::list($gate['issues']) as $gateIssue) {
                $result->addError($gateIssue->code, $gateIssue->message.' No serving ads were added.');
            }

            return ! $blocked;
        } catch (\Throwable $e) {
            report($e);
            $result->addError('search_reach_unavailable', 'Pre-launch keyword reach could not be verified. Deployment is held for a fresh check; no serving ads were added.');
            $gate = ['ready' => false, 'status' => 'unavailable',
                'issues' => [['code' => 'search_reach_unavailable', 'message' => 'Pre-launch keyword reach could not be verified.']], 'metadata' => []];
            $result->addMetadata('search_reach_preflight', ['status' => 'unavailable', 'checked_at' => now()->toIso8601String(), 'gate' => $gate]);
            if ($campaign->googleAdsResourceName() === $campaignResource || ! $campaign->google_ads_campaign_id) {
                $this->savePreflightState($campaign, ['status' => 'unavailable',
                    'checked_at' => now()->toIso8601String(), 'phase' => 'prelaunch',
                    'evaluation_started_at' => now()->toIso8601String(), 'campaign_resource' => $campaignResource,
                    'mutation_allowed' => false, 'blocked_reason' => 'reach_evidence_unavailable', 'reach_gate' => $gate]);
            }
            AgentActivity::record('deployment', 'search_reach_preflight_blocked',
                'Search launch held because fresh demand and traffic-forecast evidence could not be verified.',
                $campaign->customer_id, $campaign->id, ['gate' => $gate]);

            return false;
        }
    }

    /** Recheck the saved reach proof immediately before an enable, without another slow forecast. */
    public function confirmActivationProof(Campaign $campaign, string $customerId, string $campaignResource, array $proof, ExecutionResult $result): bool
    {
        try {
            $campaign->refresh()->load('customer');
            $snapshot = $this->inspectStage($campaign, $customerId, $campaignResource);
            // Inspection itself may outlast an owner change to budget or pause.
            $campaign->refresh()->load('customer');
            $gate = app(GoogleSearchReachGate::class)->evaluateInitialLaunch($campaign, $snapshot, $proof);
            if ($campaign->customer?->cleanGoogleCustomerId() !== $customerId || $campaign->customer_id !== $this->customer->id) {
                $gate = $this->holdGate($gate, 'search_reach_account_mismatch', 'The customer account changed while verifying activation. Review the staged resource.');
            }
            if (($proof['gate']['ready'] ?? false) !== true || ($proof['validated_campaign_resource'] ?? null) !== $campaignResource
                || ($proof['validated_settings_fingerprint'] ?? null) !== GoogleSearchReachGate::settingsFingerprint($snapshot)) {
                $gate = $this->holdGate($gate, 'search_reach_activation_settings_changed', 'The staged Search settings no longer match their validated reach proof. Repeat the check before activation.');
            }
            if (($snapshot['status'] ?? null) !== GoogleCampaignStatus::PAUSED || ! CampaignSpendGuardrails::canEnable($campaign)
                || CampaignSpendGuardrails::activeTrial($campaign) || CampaignStatusHelper::getGoogleAdsStatus(null, $campaign->customer) !== GoogleCampaignStatus::ENABLED) {
                $gate = $this->holdGate($gate, 'search_reach_activation_authorization_changed', 'Current campaign pause, end date, spending hold or launch settings do not allow activation.');
            }
            $result->addMetadata('search_reach_activation_check', $gate);
            foreach (\App\Services\Agents\AgentIssue::list($gate['warnings'] ?? []) as $warning) {
                $result->addWarning($warning->code, $warning->message);
            }
            foreach (\App\Services\Agents\AgentIssue::list($gate['issues']) as $issue) {
                $result->addError($issue->code, $issue->message);
            }

            return $gate['ready'];
        } catch (\Throwable $e) {
            report($e);
            $result->addError('search_reach_activation_unavailable', 'Current Search settings and launch authorization could not be verified. Activation is held.');

            return false;
        }
    }

    private function inspectStage(Campaign $campaign, string $customerId, string $campaignResource): array
    {
        if ($campaign->customer?->cleanGoogleCustomerId() !== $customerId || $campaign->customer_id !== $this->customer->id
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaignResource)) {
            throw new \RuntimeException('The current customer does not own this staged Google resource.');
        }
        $staged = clone $campaign;
        $staged->google_ads_campaign_id = $campaignResource;
        $snapshot = app(InspectSearchDelivery::class, ['customer' => $this->customer])->inspect($staged);
        if (! $snapshot) {
            throw new \RuntimeException('Google did not return staged Search settings.');
        }
        if (CampaignSpendGuardrails::activeTrial($campaign)) {
            $spend = app(GetCampaignSpendSafety::class, ['customer' => $this->customer])->forCampaign($staged);
            $snapshot['reported_lifetime_cost_micros'] = $spend['cost_micros'] ?? null;
            $snapshot['reported_spend_checked_at'] = now()->toIso8601String();
        }

        return $snapshot;
    }

    private function holdGate(array $gate, string $code, string $message): array
    {
        $gate['ready'] = false;
        $gate['status'] = 'blocked';
        $gate['issues'][] = ['code' => $code, 'message' => $message];

        return $gate;
    }

    private function savePreflightState(Campaign $campaign, array $assessment): void
    {
        // Retrying deployment against the same Google resource must not erase
        // a durable remote-write attempt or its unresolved read-back errors.
        $state = [...($campaign->search_delivery_state ?? []), ...$assessment, 'measurement' => null];
        if (! empty($state['repair']['errors'])) {
            $state['status'] = 'needs_review';
            $state['blocked_reason'] = 'partial_repair_unresolved';
        }
        $campaign->update(['search_delivery_state' => $state]);
    }

    protected function normalizeKeywords(array $keywords, bool $allowBroad = true): array
    {
        $normalized = [];
        foreach ($keywords as $keyword) {
            $text = is_array($keyword) ? ($keyword['text'] ?? $keyword['keyword'] ?? '') : $keyword;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $text = trim($text);
            $match = is_array($keyword) ? strtoupper($keyword['match_type'] ?? 'PHRASE') : 'PHRASE';
            if (! in_array($match, $allowBroad ? ['EXACT', 'PHRASE', 'BROAD'] : ['EXACT', 'PHRASE'], true)) {
                $match = 'PHRASE';
            }
            $normalized[mb_strtolower($text).'|'.$match] = array_merge(is_array($keyword) ? $keyword : [], ['text' => $text, 'match_type' => $match]);
        }

        return array_values($normalized);
    }

    protected function landingPage(Campaign $campaign, Strategy $strategy): ?string
    {
        return $campaign->landing_page_url ?: ($strategy->bidding_strategy['landing_page_url'] ?? $this->customer->website);
    }

    protected function businessContext(Campaign $campaign): array
    {
        return ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market, 'goals' => $campaign->goals];
    }

    /**
     * Use AI-powered keyword research when no keywords are configured.
     */
    public function researchKeywords(string $customerId, Campaign $campaign, Strategy $strategy): array
    {
        try {
            if (! config('services.gemini.api_key')) {
                return [];
            }

            $researchService = app(KeywordResearchService::class, ['customer' => $this->customer]);
            $research = $researchService->research(
                $customerId, $this->customer->name, $this->customer->business_type,
                $this->landingPage($campaign, $strategy), businessContext: $this->businessContext($campaign)
            );

            $keywords = $research['keywords'] ?? [];
            if (! empty($keywords)) {
                Log::info('GoogleAdsExecutionAgent: AI keyword research generated '.count($keywords).' keywords', [
                    'campaign_id' => $campaign->id,
                ]);
            }

            return $keywords;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: AI keyword research failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Add initial negative keywords at campaign creation time.
     */
    public function addInitialNegativeKeywords(string $customerId, string $campaignResourceName, Campaign $campaign, Strategy $strategy, ExecutionResult $result): void
    {
        try {
            if (! config('services.gemini.api_key')) {
                return;
            }

            $researchService = app(KeywordResearchService::class, ['customer' => $this->customer]);
            $negatives = $researchService->generateNegativeKeywords(
                $this->customer->name, $this->customer->business_type,
                array_merge($this->businessContext($campaign), ['positive_keywords' => $result->metadata['selected_keywords'] ?? []])
            );
            if ($negatives === []) {
                $result->addWarning('negative_keywords_unavailable', 'No relevant negative keywords were confirmed; review search terms after launch.');

                return;
            }

            $addNegativeService = new AddNegativeKeyword($this->customer);
            $added = 0;
            foreach ($negatives as $negative) {
                try {
                    $resourceName = ($addNegativeService)($customerId, $campaignResourceName, $negative, $this->negativeMatchType($negative));
                    if ($resourceName) {
                        $added++;
                        $result->addPlatformId('negative_keyword', $resourceName);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    // May fail if negative already exists
                }
            }

            if ($added > 0) {
                Log::info("GoogleAdsExecutionAgent: Added {$added} initial negative keywords", [
                    'campaign_id' => $campaign->id,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Failed to add initial negatives', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Match type for an initial brand-protection negative.
     *
     * These are intent terms, never observed search terms, and the campaign's
     * positives can include broad match. At EXACT — which is what
     * every one of them used to go in as — 'free' blocks only the literal query
     * "free", so "free crm tutorial" kept being paid for while the log and the
     * Google UI both showed the negative sitting there. A negative broad blocks
     * any query containing all of its terms, which is the protection intended.
     *
     * Multi-word terms go in as PHRASE rather than BROAD: negative broad ignores
     * word order, so "how to" would block anything containing both words
     * separately. EXACT is reserved for negatives derived from a search term we
     * actually saw, which none of these are.
     */
    protected function negativeMatchType(string $negative): int
    {
        return preg_match('/\s/', trim($negative))
            ? KeywordMatchType::PHRASE
            : KeywordMatchType::BROAD;
    }

    /**
     * Add audience targeting to ad group
     */
}
