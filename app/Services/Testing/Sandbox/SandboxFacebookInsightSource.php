<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\FacebookInsightSource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Services\FacebookAds\FacebookCampaignMetrics;
use App\Services\FacebookAds\InsightService;
use Illuminate\Support\Collection;

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
        int $limit = 100,
        ?string $campaignId = null,
    ): array {
        $rows = [];

        $campaigns = $this->customer->campaigns()->whereNotNull('facebook_ads_campaign_id')
            ->when($campaignId !== null, fn ($query) => $query->where('facebook_ads_campaign_id', $campaignId))->get();
        foreach ($campaigns as $campaign) {
            $period = $this->periodRows($campaign, $dateStart, $dateEnd);
            if ($period->isNotEmpty()) {
                $goal = FacebookCampaignMetrics::actionType(FacebookCampaignMetrics::strategy($campaign));
                $rows[] = $this->aggregate($period, $goal) + ['campaign_id' => $campaign->facebook_ads_campaign_id];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    public function getCampaignInsights(string $campaignId, string $dateStart, string $dateEnd, ?array $fields = null, bool $daily = true): ?array
    {
        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->where('facebook_ads_campaign_id', $campaignId)
            ->first();

        if (! $campaign) {
            return null;
        }
        $rows = $this->periodRows($campaign, $dateStart, $dateEnd);
        if ($rows->isEmpty()) {
            return [];
        }
        $goal = FacebookCampaignMetrics::actionType(FacebookCampaignMetrics::strategy($campaign));
        if (! $daily) {
            return [$this->aggregate($rows, $goal)];
        }

        return $rows->groupBy(fn (FacebookAdsPerformanceData $row) => $row->date->toDateString())
            ->map(fn (Collection $day, $date) => [...$this->aggregate($day, $goal), 'date_start' => $date, 'date_stop' => $date])
            ->values()->all();
    }

    public function parseAction(?array $actions, string $actionType = 'purchase'): float
    {
        return (new InsightService($this->customer))->parseAction($actions, $actionType);
    }

    private function periodRows(Campaign $campaign, string $dateStart, string $dateEnd): Collection
    {
        return FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
            ->whereBetween('date', [$dateStart, $dateEnd])->orderBy('date')->get();
    }

    private function aggregate(Collection $rows, string $goal): array
    {
        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $spend = (float) $rows->sum('cost');
        $conversions = (float) $rows->sum('conversions');

        return [
            'impressions' => (string) $impressions,
            'clicks' => (string) $clicks,
            'inline_link_clicks' => (string) $clicks,
            'spend' => (string) round($spend, 2),
            'reach' => (string) (int) round($impressions * 0.7),
            // Frequency is impressions over reach; a flat number would hide the
            // creative-fatigue signal the agent is looking for.
            'frequency' => (string) round($impressions / max(1, (int) round($impressions * 0.7)), 2),
            'cpc' => (string) ($clicks > 0 ? round($spend / $clicks, 2) : 0),
            'cpm' => (string) ($impressions > 0 ? round($spend / $impressions * 1000, 2) : 0),
            'actions' => [
                ['action_type' => $goal, 'value' => (string) $conversions],
            ],
        ];
    }
}
