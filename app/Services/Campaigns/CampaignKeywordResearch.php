<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;

/** Business relevance research is distinct from a platform-specific cost/reach forecast. */
class CampaignKeywordResearch extends KeywordResearchService
{
    public function discover(Campaign $campaign, Strategy $strategy, array $context, array $selected, bool $categoryOnly = false): array
    {
        $this->categoryOnly = $categoryOnly;
        $business = ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'goals' => $campaign->goals, 'platform' => $strategy->platform, 'channel' => $strategy->campaign_type,
            'reviewed_business_profile' => $this->customer->brandGuideline?->only(['brand_voice', 'unique_selling_propositions', 'target_audience', 'service_lines']),
            'existing_keywords' => array_column($selected, 'text')];
        if (! empty($context['customer_id'])) {
            $result = $this->research($context['customer_id'], $this->customer->name, $this->customer->business_type,
                $campaign->landing_page_url ?: $this->customer->website,
                count($context['language_constants'] ?? []) === 1 ? $context['language_constants'][0] : null,
                $context['geo_target_constants'] ?? [], 12, [], $business);

            return $result + ['source' => 'google_keyword_planner_and_business_relevance',
                'note' => 'Google keyword demand is proxy evidence for other platforms, never their reach or pricing.'];
        }
        $this->businessContext = $business;
        $seeds = $this->generateSeedKeywords($this->customer->name, $this->customer->business_type, $campaign->landing_page_url ?: $this->customer->website);

        return ['keywords' => array_map(fn ($text) => ['text' => $text, 'match_type' => 'PHRASE',
            'selection_reason' => 'Suggested from the business offer; market demand and cost are unverified.'], $seeds),
            'negative_keywords' => [], 'source' => 'business_context_only',
            'note' => 'No account-scoped platform demand, pricing or reach estimates were available.'];
    }
}
