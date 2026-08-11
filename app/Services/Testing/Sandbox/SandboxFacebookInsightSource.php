<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\FacebookInsightSource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Services\FacebookAds\InsightService;

/**
 * Synthetic Meta insights for sandbox customers.
 *
 * Aggregates the FacebookAdsPerformanceData rows SyntheticDataService seeds, so
 * a scenario configured as (say) creative fatigue actually presents that way.
 *
 * parseAction delegates to the real InsightService: it is pure parsing with no
 * I/O, and reimplementing it would mean the sandbox tested a copy of the logic
 * rather than the logic — the precise mistake this work exists to undo.
 */
class SandboxFacebookInsightSource implements FacebookInsightSource
{
    public function __construct(private Customer $customer) {}

    public function getAccountInsightsByLevel(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        string $level = 'account',
        ?array $fields = null,
        int $limit = 100
    ): array {
        $rows = [];

        foreach ($this->customer->campaigns()->whereNotNull('facebook_ads_campaign_id')->get() as $campaign) {
            $insight = $this->aggregate($campaign, $dateStart, $dateEnd);

            if ($insight !== null) {
                $rows[] = $insight + ['campaign_id' => $campaign->facebook_ads_campaign_id];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    public function getCampaignInsights(string $campaignId, string $dateStart, string $dateEnd, ?array $fields = null): ?array
    {
        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->where('facebook_ads_campaign_id', $campaignId)
            ->first();

        return $campaign ? $this->aggregate($campaign, $dateStart, $dateEnd) : null;
    }

    public function parseAction(?array $actions, string $actionType = 'purchase'): float
    {
        return (new InsightService($this->customer))->parseAction($actions, $actionType);
    }

    /** @return array<string, mixed>|null */
    private function aggregate(Campaign $campaign, string $dateStart, string $dateEnd): ?array
    {
        $rows = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
            ->whereBetween('date', [$dateStart, $dateEnd])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $spend = (float) $rows->sum('spend');
        $conversions = (float) $rows->sum('conversions');

        return [
            'impressions' => (string) $impressions,
            'clicks' => (string) $clicks,
            'spend' => (string) round($spend, 2),
            'reach' => (string) (int) round($impressions * 0.7),
            // Frequency is impressions over reach; a flat number would hide the
            // creative-fatigue signal the agent is looking for.
            'frequency' => (string) round($impressions / max(1, (int) round($impressions * 0.7)), 2),
            'cpc' => (string) ($clicks > 0 ? round($spend / $clicks, 2) : 0),
            'cpm' => (string) ($impressions > 0 ? round($spend / $impressions * 1000, 2) : 0),
            'actions' => [
                ['action_type' => 'purchase', 'value' => (string) $conversions],
            ],
        ];
    }
}
