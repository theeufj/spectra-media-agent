<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\SearchKeywordBuilder;

/** Reuse the executor's selected keywords and geo precedence without creating platform objects. */
class SearchMarketInputs extends GeoTargetResolver
{
    public function forStrategy(Campaign $campaign, Strategy $strategy): array
    {
        $plan = new ExecutionPlan([]);
        $keywords = (new SearchKeywordBuilder($this->customer))->getKeywords($campaign, $strategy, $plan);
        [$source, $locations] = $this->configuredLocations($campaign, $strategy, $plan);
        $geos = [];
        foreach ($locations as $location) {
            $id = $this->resolveGeoTargetId($location);
            if (! $id || $id <= 0) {
                throw new \RuntimeException('A selected target location could not be resolved. Market research cannot substitute other countries.');
            }
            $geos[] = 'geoTargetConstants/'.$id;
        }
        if ($geos === []) {
            throw new \RuntimeException('Specific campaign target markets are required before a credible budget can be proposed.');
        }
        $targeting = $strategy->targetingConfig;
        $unsupported = [];
        // These settings are not currently reproduced by the Search builder.
        // Retain them as a visible evidence gap rather than forecasting another audience.
        foreach (['excluded_geo_locations', 'languages', 'device_types', 'custom_audiences', 'lookalike_audiences'] as $field) {
            if (! empty($targeting?->getAttribute($field))) {
                $unsupported[] = ['type' => 'unverified_planned_'.$field];
            }
        }
        foreach (array_diff_key($targeting?->getAttribute('google_options') ?? [], ['keywords' => true]) as $field => $value) {
            if ($value !== null && $value !== [] && $value !== false) {
                $unsupported[] = ['type' => 'unverified_planned_google_option', 'field' => $field];
            }
        }
        foreach (['ad_schedule', 'excluded_geo_target_constants', 'device_bid_modifiers', 'search_partners_enabled'] as $field) {
            if (! empty($strategy->bidding_strategy[$field])) {
                $unsupported[] = ['type' => 'unverified_planned_'.$field];
            }
        }
        $negatives = [];
        foreach ($strategy->bidding_strategy['negative_keywords'] ?? [] as $negative) {
            $text = is_string($negative) ? $negative : ($negative['text'] ?? null);
            if (is_string($text) && trim($text) !== '') {
                $negatives[] = ['scope' => 'campaign', 'text' => trim($text),
                    'match_type' => is_array($negative) ? ($negative['match_type'] ?? 'PHRASE') : (str_contains(trim($text), ' ') ? 'PHRASE' : 'BROAD')];
            }
        }

        return ['keywords' => $keywords, 'geo_source' => $source,
            'geo_target_constants' => array_values(array_unique($geos)), 'language_constants' => [],
            'negative_keywords' => $negatives, 'excluded_geo_target_constants' => [], 'ad_schedule' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => $unsupported,
            'search_partners_enabled' => false];
    }
}
