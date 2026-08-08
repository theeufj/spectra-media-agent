<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
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

            return $keywords;
        }

        // 2. Check targeting config keywords
        $targetingConfig = $strategy->targetingConfig;
        if ($targetingConfig && isset($targetingConfig->google_options['keywords']) && ! empty($targetingConfig->google_options['keywords'])) {
            $keywords = $targetingConfig->google_options['keywords'];
            Log::info('GoogleAdsExecutionAgent: Using targeting config keywords', ['count' => count($keywords)]);

            return $keywords;
        }

        // 3. Check execution plan keywords (AI-generated)
        $creativeStrategy = $plan->getCreativeStrategy();
        if (isset($creativeStrategy['keywords']) && ! empty($creativeStrategy['keywords'])) {
            $keywords = $creativeStrategy['keywords'];
            Log::info('GoogleAdsExecutionAgent: Using execution plan keywords', ['count' => count($keywords)]);

            return $keywords;
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
                    : 'BROAD';

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
            } catch (\Exception $e) {
                $result->addWarning('Failed to add keyword: '.$e->getMessage());
            }
        }
    }

    /**
     * Validate keywords through Google Keyword Planner and filter out low-volume terms.
     * Uses AI keywords as seeds, expands via Keyword Planner, and returns only viable keywords.
     */
    public function validateAndEnrichKeywords(string $customerId, array $keywords, Campaign $campaign, Strategy $strategy): array
    {
        try {
            // Extract keyword texts to use as seeds for Keyword Planner
            $seedTexts = array_map(function ($kw) {
                return is_array($kw) ? ($kw['text'] ?? $kw['keyword'] ?? '') : $kw;
            }, $keywords);
            $seedTexts = array_values(array_filter($seedTexts));

            if (empty($seedTexts)) {
                return $keywords;
            }

            $landingPageUrl = $campaign->landing_page_url ?? null;
            $generateIdeas = new \App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas($this->customer);
            $ideas = ($generateIdeas)($customerId, array_slice($seedTexts, 0, 20), $landingPageUrl);

            if (empty($ideas)) {
                Log::warning('GoogleAdsExecutionAgent: Keyword Planner returned no ideas, using original keywords');

                return $keywords;
            }

            // Build a lookup of keyword volumes from Planner results
            $ideaMap = [];
            foreach ($ideas as $idea) {
                $ideaMap[strtolower($idea['keyword'])] = $idea;
            }

            // Check which original keywords have sufficient volume
            $validated = [];
            $minVolume = 10;
            foreach ($keywords as $kw) {
                $text = is_array($kw) ? ($kw['text'] ?? $kw['keyword'] ?? '') : $kw;
                $lower = strtolower($text);
                if (isset($ideaMap[$lower]) && ($ideaMap[$lower]['avg_monthly_searches'] ?? 0) >= $minVolume) {
                    $idea = $ideaMap[$lower];
                    $validated[] = [
                        'text' => $text,
                        'match_type' => $this->recommendMatchTypeFromMetrics($idea),
                        'avg_monthly_searches' => $idea['avg_monthly_searches'],
                    ];
                }
            }

            // If too few original keywords survived, supplement with top Keyword Planner suggestions
            if (count($validated) < 6) {
                $researchService = new \App\Services\GoogleAds\KeywordResearch\KeywordResearchService($this->customer);
                $businessName = $campaign->business_name ?? $strategy->businessProfile?->business_name ?? 'Business';
                $industry = $strategy->businessProfile?->industry ?? null;
                $research = $researchService->research($customerId, $businessName, $industry, $landingPageUrl, 'languageConstants/1000', [], 20);
                $researchKeywords = $research['keywords'] ?? [];

                // Add research keywords that aren't already in validated set
                $existingTexts = array_map(fn ($v) => strtolower($v['text']), $validated);
                foreach ($researchKeywords as $rk) {
                    if (count($validated) >= 20) {
                        break;
                    }
                    $rkText = strtolower($rk['text'] ?? '');
                    if (! in_array($rkText, $existingTexts) && ($rk['avg_monthly_searches'] ?? 0) >= $minVolume) {
                        $validated[] = $rk;
                        $existingTexts[] = $rkText;
                    }
                }
            }

            Log::info('GoogleAdsExecutionAgent: Keyword validation complete', [
                'original_count' => count($keywords),
                'validated_count' => count($validated),
            ]);

            return ! empty($validated) ? $validated : $keywords;
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Keyword validation failed, using original keywords', [
                'error' => $e->getMessage(),
            ]);

            return $keywords;
        }
    }

    /**
     * Recommend match type from Keyword Planner metrics.
     */
    protected function recommendMatchTypeFromMetrics(array $idea): string
    {
        $volume = $idea['avg_monthly_searches'] ?? 0;
        $competitionIndex = $idea['competition_index'] ?? 50;
        $cpc = ($idea['average_cpc_micros'] ?? 0) / 1_000_000;

        if ($volume > 1000 && $competitionIndex < 30) {
            return 'BROAD';
        }
        if ($competitionIndex > 70 || $cpc > 5.0) {
            return 'EXACT';
        }

        return 'PHRASE';
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

            $businessName = $campaign->business_name
                ?? $strategy->businessProfile?->business_name
                ?? $this->customer->name
                ?? 'Business';
            $industry = $strategy->businessProfile?->industry ?? null;
            // Fall back to strategy landing page when campaign doesn't have one set
            $landingPageUrl = $campaign->landing_page_url
                ?? $strategy->bidding_strategy['landing_page_url']
                ?? $this->customer->website
                ?? null;

            $researchService = new \App\Services\GoogleAds\KeywordResearch\KeywordResearchService($this->customer);
            $research = $researchService->research($customerId, $businessName, $industry, $landingPageUrl);

            $keywords = $research['keywords'] ?? [];
            if (! empty($keywords)) {
                Log::info('GoogleAdsExecutionAgent: AI keyword research generated '.count($keywords).' keywords', [
                    'campaign_id' => $campaign->id,
                ]);
            }

            return $keywords;
        } catch (\Exception $e) {
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

            $businessName = $campaign->business_name ?? $strategy->businessProfile?->business_name ?? 'Business';
            $industry = $strategy->businessProfile?->industry ?? null;

            $researchService = new \App\Services\GoogleAds\KeywordResearch\KeywordResearchService($this->customer);
            $research = $researchService->research($customerId, $businessName, $industry);
            $negatives = $research['negative_keywords'] ?? [];

            if (empty($negatives)) {
                // Fallback: brand-protection negatives for any broad-match campaign
                $negatives = ['free', 'cheap', 'diy', 'tutorial', 'how to', 'torrent', 'crack', 'pirate', 'course'];
                Log::info('GoogleAdsExecutionAgent: No LLM negatives — using default broad-match protection negatives', [
                    'campaign_id' => $campaign->id,
                ]);
            }

            $addNegativeService = new AddNegativeKeyword($this->customer);
            $added = 0;
            foreach ($negatives as $negative) {
                try {
                    $resourceName = ($addNegativeService)($customerId, $campaignResourceName, $negative, \Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType::EXACT);
                    if ($resourceName) {
                        $added++;
                        $result->addPlatformId('negative_keyword', $resourceName);
                    }
                } catch (\Exception $e) {
                    // May fail if negative already exists
                }
            }

            if ($added > 0) {
                Log::info("GoogleAdsExecutionAgent: Added {$added} initial negative keywords", [
                    'campaign_id' => $campaign->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Failed to add initial negatives', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add audience targeting to ad group
     */
}
