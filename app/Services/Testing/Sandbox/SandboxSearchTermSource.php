<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\SearchTermSource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Keyword;

/**
 * Synthetic search-terms report for sandbox customers.
 *
 * Builds terms from the campaign's seeded keywords so the data is recognisable,
 * then spreads them deliberately across the decision boundaries in
 * SearchTermMiningAgent::evaluateSearchTerm — a clear promote, a clear
 * high-cost negative, a clear low-CTR negative, and several that must be left
 * alone. The point is to prove the agent's real thresholds fire correctly, so
 * the fixture is built to straddle them rather than to look plausible.
 *
 * Deterministic: no randomness, so a sandbox run is reproducible and a changed
 * result means changed behaviour rather than a different roll.
 */
class SandboxSearchTermSource implements SearchTermSource
{
    public function __construct(private Customer $customer) {}

    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->where(function ($q) use ($campaignResourceName) {
                $q->where('google_ads_campaign_id', $campaignResourceName)
                    ->orWhere('google_ads_campaign_id', 'like', '%'.$campaignResourceName);
            })
            ->first();

        $seeds = $campaign
            ? Keyword::where('campaign_id', $campaign->id)->pluck('keyword_text')->all()
            : [];

        if ($seeds === []) {
            $seeds = ['project management software', 'best task management tool', 'free project tracker'];
        }

        $adGroup = $campaignResourceName.'/adGroups/sandbox';

        // Each row is labelled with the branch it is designed to trigger, so a
        // sandbox result can be checked against intent rather than eyeballed.
        $fixtures = [
            // Promote: enough impressions and clicks, strong CTR, converting.
            ['near me '.$seeds[0], 1200, 90, 45.00, 12.0, 0.075],
            // Promote at PHRASE: mid conversion volume.
            ['buy '.$seeds[0], 800, 40, 30.00, 6.0, 0.050],
            // Negative: expensive, never converts.
            ['free '.$seeds[0].' crack', 900, 70, 85.00, 0.0, 0.078],
            // Negative: plenty of impressions, nobody clicks, no conversions.
            ['what is '.$seeds[0], 4000, 4, 3.00, 0.0, 0.001],
            // Left alone: below the impression floor.
            [$seeds[0].' pricing', 120, 9, 8.00, 1.0, 0.075],
            // Left alone: converted, so must never be negated despite poor CTR.
            [($seeds[1] ?? $seeds[0]).' review', 2000, 6, 60.00, 2.0, 0.003],
        ];

        return array_map(fn ($f) => [
            'search_term' => $f[0],
            'status' => 2,
            'impressions' => $f[1],
            'clicks' => $f[2],
            'cost_micros' => (int) ($f[3] * 1_000_000),
            'cost' => $f[3],
            'conversions' => $f[4],
            'ctr' => $f[5],
            'campaign_resource_name' => $campaignResourceName,
            'ad_group_resource_name' => $adGroup,
        ], $fixtures);
    }
}
