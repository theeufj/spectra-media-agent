<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;

final class SingleGoogleSearchLandingPage
{
    public static function enabled(Strategy $strategy): bool
    {
        return $strategy->campaign_type === 'search'
            && str_contains(strtolower((string) $strategy->platform), 'google')
            && ($strategy->bidding_strategy['single_landing_page'] ?? null) === true;
    }

    public static function required(Strategy $strategy): bool
    {
        return self::enabled($strategy)
            || array_key_exists('single_landing_page_url', $strategy->execution_result['metadata']['google_search_baseline'] ?? []);
    }

    /** Scope optimizer and recovery writes to the exact provider campaign they target. */
    public static function protectsResource(Campaign $campaign, ?string $resource): bool
    {
        if (! is_string($resource) || ! preg_match('#^customers/(\d+)/campaigns/(\d+)$#D', $resource, $parts)
            || $parts[1] !== $campaign->customer?->cleanGoogleCustomerId()) {
            return false;
        }

        return $campaign->strategies()->where('campaign_type', 'search')
            ->where(fn ($query) => $query->where('bidding_strategy->single_landing_page', true)
                ->orWhereNotNull('execution_result->metadata->google_search_baseline->single_landing_page_url'))->get()
            ->contains(fn (Strategy $strategy) => self::required($strategy) && basename((string) $strategy->reusableGoogleCampaignId()) === $parts[2]);
    }

    /** Keep the operator's complete campaign destination, including any query or fragment. */
    public static function url(Campaign $campaign, Strategy $strategy): string
    {
        $url = $campaign->landing_page_url;
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException('Single-destination Search ads require a valid campaign landing page.');
        }

        return CampaignTrackingUrl::forStrategy($url, $campaign, $strategy, 'google');
    }
}
