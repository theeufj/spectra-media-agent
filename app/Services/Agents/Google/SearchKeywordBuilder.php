<?php

namespace App\Services\Agents\Google;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
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
            $staged = clone $campaign;
            $staged->google_ads_campaign_id = $campaignResource;
            $snapshot = app(InspectSearchDelivery::class, ['customer' => $this->customer])->inspect($staged);
            if (! $snapshot) {
                throw new \RuntimeException('Google did not return staged Search settings.');
            }
            $assessment = app(GoogleSearchReachPlanner::class)->assess($campaign, $snapshot);
            $result->addMetadata('search_reach_preflight', $assessment);
            $forecast = $assessment['forecast'] ?? [];
            $blocked = ($forecast['success'] ?? false) && ($forecast['auto_repair_safe'] ?? false)
                && (($forecast['impressions'] ?? 0) <= 0 || ($forecast['clicks'] ?? 0) <= 0);
            $state = [
                'status' => $blocked ? 'needs_review' : (($assessment['status'] ?? null) === 'unavailable' ? 'unavailable' : 'collecting_evidence'),
                'checked_at' => now()->toIso8601String(), 'evaluation_started_at' => now()->toIso8601String(),
                'currency_code' => $snapshot['currency_code'] ?? null,
                'diagnosis' => $assessment, 'phase' => 'prelaunch', 'strategy_id' => $strategy->id,
                'campaign_resource' => $campaignResource,
                'blocked_reason' => $blocked ? 'The staged campaign is forecast to receive no traffic at its current bid and targeting.' : null,
                'mutation_allowed' => false,
            ];
            // The campaign card monitors its primary Google campaign. A second
            // Search strategy keeps its own assessment in execution metadata.
            if ($campaign->googleAdsResourceName() === $campaignResource || ! $campaign->google_ads_campaign_id) {
                $this->savePreflightState($campaign, $state);
            }
            foreach (\App\Services\Agents\AgentIssue::list($assessment['issues'] ?? []) as $issue) {
                $result->addWarning($issue->code, $issue->message);
            }
            AgentActivity::record('deployment', $blocked ? 'search_reach_preflight_blocked' : 'search_reach_preflight_checked',
                $blocked ? 'Search launch needs review: no traffic is forecast at the staged settings.' : 'Checked Search keyword reach against the staged bid and targeting.',
                $campaign->customer_id, $campaign->id, ['assessment' => $assessment]);
            if ($blocked) {
                $result->addError('search_reach_unviable', 'No traffic is forecast at the current Search settings. Review keywords and the bid ceiling before retrying; no serving ads were added.');
            }

            return ! $blocked;
        } catch (\Throwable $e) {
            report($e);
            $result->addWarning('search_reach_unavailable', 'Pre-launch keyword reach could not be verified. The delivery monitor will retry; this is not evidence that traffic will arrive.');
            $result->addMetadata('search_reach_preflight', ['status' => 'unavailable', 'checked_at' => now()->toIso8601String()]);
            if ($campaign->googleAdsResourceName() === $campaignResource || ! $campaign->google_ads_campaign_id) {
                $this->savePreflightState($campaign, ['status' => 'unavailable',
                    'checked_at' => now()->toIso8601String(), 'phase' => 'prelaunch',
                    'evaluation_started_at' => now()->toIso8601String(), 'campaign_resource' => $campaignResource,
                    'mutation_allowed' => false, 'blocked_reason' => 'reach_evidence_unavailable']);
            }

            return true;
        }
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
