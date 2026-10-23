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
        $business = ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'goals' => $campaign->goals, 'platform' => $strategy->platform, 'channel' => $strategy->campaign_type,
            'reviewed_business_profile' => $this->customer->brandGuideline?->only(['brand_voice', 'unique_selling_propositions', 'target_audience', 'service_lines']),
            'target_locations' => $campaign->geographic_targeting ?: $strategy->targetingConfig?->geo_locations,
            'geo_target_constants' => $context['geo_target_constants'] ?? [],
            'existing_keywords' => array_column($selected, 'text')];
        $landingPage = $campaign->landing_page_url ?: $this->customer->website;
        $sets = [];
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

        return ['keywords' => $this->balancedKeywords($sets), 'keyword_sets' => $sets, 'negative_keywords' => $negatives,
            'discovery' => ['profiles_attempted' => array_column($sets, 'kind'), 'category_recovery_attempted' => false],
            'source' => empty($context['customer_id']) ? 'business_context_only' : 'google_keyword_planner_and_business_relevance',
            'note' => empty($context['customer_id'])
                ? 'No account-scoped platform demand, pricing or reach estimates were available.'
                : 'Google keyword demand is proxy evidence for other platforms, never their reach or pricing.'];
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
