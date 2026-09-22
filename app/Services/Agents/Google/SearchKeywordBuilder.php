<?php

namespace App\Services\Agents\Google;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
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
        $this->forecastViability($customerId, $keywords, $campaign, $ideaMap);

        return $keywords;
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
     * Ask Google what this keyword set would deliver at this budget, before it
     * goes live.
     *
     * Step 2 of Google's own keyword-planning workflow, and the one this platform
     * skipped. GenerateKeywordIdeas above supplies volume, competition and bid
     * ranges, and the code filters on them — but nothing asked the question that
     * decides whether a campaign can work: at this budget and this bid, how many
     * clicks is that?
     *
     * A budget too small to win the auctions for its own keywords produces
     * exactly what this account saw: spend, no conversions, and Smart Bidding
     * left with nothing to learn from. Finding that out after a month of spend
     * is expensive; finding it out here costs one read-only API call.
     *
     * Never throws and never blocks deployment. A forecast is advice, and being
     * unable to get it is not a reason to refuse to launch.
     *
     * @param  array<int, mixed>  $keywords
     * @param  array<string, mixed>  $ideaMap  Keyword Planner metrics, keyed lowercase
     */
    protected function forecastViability(string $customerId, array $keywords, Campaign $campaign, array $ideaMap = []): void
    {
        try {
            $texts = array_values(array_filter(array_map(
                fn ($kw) => is_array($kw) ? ($kw['text'] ?? $kw['keyword'] ?? '') : $kw,
                $keywords
            )));

            $dailyBudget = (float) ($campaign->daily_budget ?? 0);

            if ($texts === [] || $dailyBudget <= 0) {
                return;
            }

            $maxCpc = $this->suggestedBid($texts, $ideaMap, $dailyBudget);

            $forecast = (new \App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast($this->customer))(
                $customerId,
                array_slice($texts, 0, 50),
                $maxCpc
            );

            if (! $forecast['success']) {
                Log::info('SearchKeywordBuilder: forecast unavailable', ['error' => $forecast['error']]);

                return;
            }

            $clicks = (float) ($forecast['clicks'] ?? 0);
            $cost = (float) ($forecast['cost'] ?? 0);
            $monthlyBudget = $dailyBudget * 30;

            $concerns = [];

            // Smart Bidding needs a signal to learn from. Well under a click a
            // day will not produce one in any useful timeframe.
            if ($clicks < 30) {
                $concerns[] = sprintf('only %.0f clicks forecast over 30 days', $clicks);
            }

            // Wanting to spend more than the budget allows means the bid cannot
            // be sustained: the campaign will run out of budget each day and
            // show for a fraction of the searches it is targeting.
            if ($cost > $monthlyBudget * 1.2) {
                $concerns[] = sprintf('forecast spend %.2f exceeds the %.2f monthly budget at a %.2f bid', $cost, $monthlyBudget, $maxCpc);
            }

            Log::info('SearchKeywordBuilder: pre-launch forecast', [
                'campaign_id' => $campaign->id,
                'keywords' => count($texts),
                'max_cpc' => $maxCpc,
                'clicks' => $clicks,
                'cost' => $cost,
                'impressions' => $forecast['impressions'] ?? null,
            ]);

            // Recorded either way. A forecast nobody sees is the same as no
            // forecast, and "we checked and it looks fine" is worth stating.
            AgentActivity::record(
                'deployment',
                $concerns === [] ? 'forecast_ok' : 'forecast_warning',
                $concerns === []
                    ? sprintf('Forecast for "%s": %.0f clicks and %.2f spend over 30 days at a %.2f bid', $campaign->name, $clicks, $cost, $maxCpc)
                    : sprintf('Budget concern for "%s": %s', $campaign->name, implode('; ', $concerns)),
                $campaign->customer_id,
                $campaign->id,
                [
                    'max_cpc' => $maxCpc,
                    'daily_budget' => $dailyBudget,
                    'forecast' => $forecast,
                    'concerns' => $concerns,
                ]
            );
        } catch (\Throwable $e) {
            // Advisory only — never let a forecast stop a deployment.
            Log::warning('SearchKeywordBuilder: forecast failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A bid to forecast at.
     *
     * Google's own top-of-page estimates for these keywords are the best guide;
     * without them, a tenth of the daily budget is a reasonable stand-in, since
     * a campaign wants more than a handful of clicks a day.
     *
     * @param  list<string>  $texts
     * @param  array<string, mixed>  $ideaMap
     */
    public function suggestedBid(array $texts, array $ideaMap, float $dailyBudget): float
    {
        $bids = [];

        foreach ($texts as $text) {
            $idea = $ideaMap[strtolower($text)] ?? null;

            if ($idea && ! empty($idea['high_top_of_page_bid_micros'])) {
                $bids[] = $idea['high_top_of_page_bid_micros'] / 1_000_000;
            }
        }

        if ($bids !== []) {
            return round(array_sum($bids) / count($bids), 2);
        }

        return max(0.5, round($dailyBudget / 10, 2));
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
