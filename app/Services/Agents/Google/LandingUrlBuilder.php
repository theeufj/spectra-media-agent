<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;

/**
 * Builds the final landing-page URL for an ad, including UTM tagging.
 */
class LandingUrlBuilder
{
    public function __construct(protected Customer $customer) {}

    public function getFinalUrl(Campaign $campaign, Strategy $strategy, ExecutionPlan $plan): ?string
    {
        // 1. Check Strategy recommendation (most specific)
        if (isset($strategy->bidding_strategy['landing_page_url']) && ! empty($strategy->bidding_strategy['landing_page_url'])) {
            $url = $strategy->bidding_strategy['landing_page_url'];
        }
        // 2. Check Campaign default
        elseif (! empty($campaign->landing_page_url)) {
            $url = $campaign->landing_page_url;
        }
        // 3. Check Execution Plan (AI generated during execution)
        else {
            $url = null;
            foreach ($plan->steps as $step) {
                if (isset($step['parameters']['final_urls'][0]) && ! empty($step['parameters']['final_urls'][0])) {
                    $url = $step['parameters']['final_urls'][0];
                    break;
                }
            }
        }

        if (! $url) {
            return null;
        }

        // Append Spectra UTM parameters for attribution tracking
        return $this->appendUtmParameters($url, $campaign, $strategy, 'google');
    }

    /**
     * Append UTM parameters for attribution tracking.
     */
    protected function appendUtmParameters(string $url, Campaign $campaign, Strategy $strategy, string $platform): string
    {
        $params = [
            'utm_source' => $platform,
            'utm_medium' => $strategy->campaign_type ?? 'cpc',
            'utm_campaign' => 'spectra_'.$campaign->id,
            'utm_content' => 'strategy_'.$strategy->id,
        ];

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    /**
     * Handle execution errors with AI-powered recovery
     */
}
