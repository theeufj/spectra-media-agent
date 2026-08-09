<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\CampaignPerformanceSource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;

/**
 * Aggregates the synthetic performance rows SyntheticDataService already seeds.
 *
 * Reads real GoogleAdsPerformanceData rather than inventing numbers, so a
 * sandbox scenario configured to look like (say) a high-spend/no-conversion
 * campaign actually presents that way to the agent, and the agent's real
 * thresholds decide what to do about it.
 */
class SandboxCampaignPerformanceSource implements CampaignPerformanceSource
{
    public function __construct(private Customer $customer) {}

    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): ?array
    {
        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->where(function ($q) use ($campaignResourceName) {
                $q->where('google_ads_campaign_id', $campaignResourceName)
                    ->orWhere('google_ads_campaign_id', 'like', '%'.$campaignResourceName);
            })
            ->first();

        if (! $campaign) {
            return null;
        }

        $days = str_contains($dateRange, '7') ? 7 : 30;

        $rows = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $cost = (float) $rows->sum('cost');
        $conversions = (float) $rows->sum('conversions');

        // Derived rather than summed: averaging an average across days would be
        // wrong, and these are exactly the fields agents threshold on.
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'cost_micros' => (int) round($cost * 1_000_000),
            'conversions' => $conversions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'average_cpc' => $clicks > 0 ? $cost / $clicks : 0.0,
            'cost_per_conversion' => $conversions > 0 ? $cost / $conversions : 0.0,
        ];
    }
}
