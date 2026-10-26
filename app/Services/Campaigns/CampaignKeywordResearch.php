<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Illuminate\Support\Facades\Log;

/** Business relevance research is distinct from a platform-specific cost/reach forecast. */
class CampaignKeywordResearch extends KeywordResearchService
{
    public function discover(Campaign $campaign, Strategy $strategy, array $context, array $selected, bool $categoryOnly = false): array
    {
        $business = $this->businessContextFor($campaign, $strategy, $context, $selected);
        $landingPage = $campaign->landing_page_url ?: $this->customer->website;
        $sets = [];
        $costCandidates = [];
        $previousProfile = $this->discoveryProfile;
        try {
            foreach ($categoryOnly ? ['category'] : ['category', 'specific'] as $kind) {
                $this->discoveryProfile = $kind;
                try {
                    if (! empty($context['customer_id'])) {
                        $result = $this->research($context['customer_id'], $this->customer->name, $this->customer->business_type,
                            $landingPage,
                            count($context['language_constants'] ?? []) === 1 ? $context['language_constants'][0] : null,
                            $context['geo_target_constants'] ?? [], 12, [], $business);
                    } else {
                        $this->businessContext = $business;
                        $seeds = $kind === 'category'
                            ? $this->generateCategorySeedKeywords($this->customer->name, $this->customer->business_type, $landingPage)
                            : $this->generateSpecificSeedKeywords($this->customer->name, $this->customer->business_type, $landingPage);
                        $seeds = array_filter(array_map(fn ($seed) => is_string($seed) ? trim($seed) : '', $seeds), fn ($seed) => $seed !== '');
                        $result = ['keywords' => $this->seedsToKeywords(array_slice(array_values(array_unique($seeds)), 0, 12))];
                    }
                    $costCandidates = [...$costCandidates, ...($result['cost_candidates'] ?? [])];
                    $verified = ($result['discovery']['planner_metrics_available'] ?? false) === true;
                    $sets[] = ['kind' => $kind, 'keywords' => $result['keywords'],
                        'status' => $verified ? 'ready' : 'needs_review', 'demand_verified' => $verified,
                        'reason' => $verified
                            ? 'Relevant keyword demand was checked. Click costs and campaign reach still require a separate forecast.'
                            : 'Keyword demand could not be verified for this profile. Any suggestions still need cost and reach checks.'];
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('Campaign keyword profile discovery unavailable', ['campaign_id' => $campaign->id, 'profile' => $kind]);
                    $sets[] = ['kind' => $kind, 'keywords' => [], 'status' => 'needs_review', 'demand_verified' => false,
                        'reason' => 'This keyword profile could not be researched. No demand or traffic has been assumed.'];
                }
            }
        } finally {
            $this->discoveryProfile = $previousProfile;
        }

        $combined = $this->balancedKeywords($sets);
        $discoveredProfiles = array_column($sets, 'kind');
        $priced = $this->costEfficientKeywords($costCandidates, 24);
        // Compare the cheaper half separately instead of letting costly head
        // terms or unknown-demand jargon dilute every candidate group.
        $cutoff = $priced[min(6, intdiv(count($priced) + 1, 2)) - 1]['cost_evidence']['amount_micros'] ?? null;
        if ($cutoff !== null) {
            // A large expensive majority must not drag the cheaper cohort back
            // up to head-term prices. This is a sampling rule, not a CPC limit.
            $cutoff = min($cutoff, $priced[0]['cost_evidence']['amount_micros'] * 2);
        }
        $economical = array_slice(array_values(array_filter($priced, fn ($keyword) => $keyword['cost_evidence']['amount_micros'] <= $cutoff)), 0, 6);
        $sets[] = ['kind' => 'cost_efficient', 'keywords' => $economical,
            'status' => $economical === [] ? 'needs_review' : 'ready', 'demand_verified' => $economical !== [],
            'reason' => $economical === []
                ? 'No relevant keyword group had both measured demand and usable cost estimates. Cheap traffic has not been assumed.'
                : 'This group tests the lower-cost relevant terms from the same research. Historical prices are estimates; traffic and purchase results still need separate checks.'];

        $negatives = [];
        $allPositive = array_merge(...array_column($sets, 'keywords'));
        if ($allPositive !== []) {
            try {
                $negatives = $this->generateNegativeKeywords($this->customer->name, $this->customer->business_type,
                    [...$business, 'positive_keywords' => array_values(array_unique(array_column($allPositive, 'text')))]);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Campaign negative keyword discovery unavailable', ['campaign_id' => $campaign->id]);
            }
        }

        return ['keywords' => $combined, 'keyword_sets' => $sets, 'negative_keywords' => $negatives,
            'discovery' => ['profiles_attempted' => $discoveredProfiles, 'derived_profiles' => ['cost_efficient'],
                'cost_cohort' => ['max_keywords' => 6, 'max_relative_measured_cost' => 2], 'category_recovery_attempted' => false],
            'source' => empty($context['customer_id']) ? 'business_context_only' : 'google_keyword_planner_and_business_relevance',
            'note' => empty($context['customer_id'])
                ? 'No account-scoped platform demand, pricing or reach estimates were available.'
                : 'Google keyword demand is proxy evidence for other platforms, never their reach or pricing.'];
    }

    /** One bounded new-theme discovery after ordinary profiles fail their affordable-reach test. */
    public function discoverAffordableAlternatives(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        if (empty($context['customer_id'])) {
            return ['keywords' => [], 'cost_candidates' => [], 'negative_keywords' => [],
                'discovery' => ['affordable_recovery_attempted' => true],
                'reason' => 'Affordable alternatives need an identified platform account. No demand or pricing was assumed.'];
        }
        $result = $this->researchAffordableAlternatives($context['customer_id'], $this->customer->name,
            $this->customer->business_type, $campaign->landing_page_url ?: $this->customer->website,
            count($context['language_constants'] ?? []) === 1 ? $context['language_constants'][0] : null,
            $context['geo_target_constants'] ?? [], 12, [],
            $this->businessContextFor($campaign, $strategy, $context, $selected));

        return [...$result, 'discovery' => [...($result['discovery'] ?? []), 'affordable_recovery_attempted' => true],
            'source' => 'google_keyword_planner_and_business_relevance',
            'note' => 'Alternative buyer themes remain proposals until their full-portfolio traffic and costs are forecast within the retained limits.'];
    }

    private function businessContextFor(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        return ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'goals' => $campaign->goals, 'platform' => $strategy->platform, 'channel' => $strategy->campaign_type,
            'reviewed_business_profile' => $this->customer->brandGuideline?->only(['brand_voice', 'unique_selling_propositions', 'target_audience', 'service_lines']),
            'target_locations' => $campaign->geographic_targeting ?: $strategy->targetingConfig?->geo_locations,
            'geo_target_constants' => $context['geo_target_constants'] ?? [],
            'existing_keywords' => array_column($selected, 'text'),
            'daily_budget_micros' => $context['daily_budget_micros'] ?? null,
            'max_cpc_bid_micros' => $context['max_cpc_bid_micros'] ?? null,
            'currency_code' => $context['currency_code'] ?? null,
            'failed_candidates' => $context['failed_candidates'] ?? [],
            'failed_forecasts' => $context['failed_forecasts'] ?? []];
    }

    /** Keep lower-volume specific buying terms alongside category discovery. */
    private function balancedKeywords(array $sets): array
    {
        $keywords = [];
        for ($index = 0; $index < 12 && count($keywords) < 12; $index++) {
            foreach ($sets as $set) {
                $keyword = $set['keywords'][$index] ?? null;
                if ($keyword === null) {
                    continue;
                }
                $key = mb_strtolower(trim($keyword['text']));
                $keywords[$key] ??= $keyword;
                if (count($keywords) === 12) {
                    break;
                }
            }
        }

        return array_values($keywords);
    }
}
