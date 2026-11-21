<?php

namespace App\Services\Agents;

use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use App\Services\FacebookAds\FacebookCampaignMetrics;
use App\Services\FacebookAds\InsightService;
use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;

class CreativeTreatmentMetrics
{
    /** A treatment is a provider ad or asset link under the approved campaign. */
    public function read(Strategy $strategy, string $from, string $to): array
    {
        $campaign = $strategy->campaign;
        $entry = collect(CampaignResourceInventory::entries($campaign))->firstWhere('strategy_id', $strategy->id);
        $binding = app(CampaignOutcomeEvidence::class)->binding($strategy);
        if (! $entry || ! $binding['destination'] || ! in_array($binding['goal'], ['signup', 'purchase', 'lead'], true)) {
            return [];
        }
        if ($entry['platform'] === 'google') {
            $pmax = str_contains(strtolower($strategy->platform.' '.$strategy->campaign_type), 'max');

            return (new GetAdPerformanceByAsset($campaign->customer))->treatmentMetrics(
                $entry['account_id'], $entry['resource_name'], $binding['destination'], $from, $to, $pmax);
        }

        $attribution = app(FacebookAttributionEvidence::class);
        $window = $attribution->current($strategy, $binding);
        if (! $window || $from < $window->first_full_day) {
            return [];
        }
        $to = min($to, now($window->timezone)->subDay()->toDateString());
        if ($from > $to) {
            return [];
        }

        $managed = array_values(array_filter($strategy->execution_result['platform_ids'] ?? [],
            fn ($key) => preg_match('/^(?:(?:image|video)_ad_\d+|ad)$/D', $key), ARRAY_FILTER_USE_KEY));
        $managed[] = $strategy->facebook_ad_id;
        $insights = app(InsightService::class, ['customer' => $campaign->customer]);
        $rows = $insights->treatmentInsights($window->adset_id, $from, $to);
        if ($rows === null) {
            throw new \RuntimeException('The complete Meta treatment report was unavailable.');
        }
        $remote = $insights->attributionAdSet($window->adset_id);
        if (! $remote || ! $attribution->stillMatches($window, $remote)) {
            return [];
        }

        $results = [];
        foreach ($rows as $row) {
            $id = (string) ($row['ad_id'] ?? '');
            if (! $attribution->matchesRow($window, $row) || ($row['date_start'] ?? null) !== $from || ($row['date_stop'] ?? null) !== $to) {
                return [];
            }
            if (! ctype_digit($id) || ! in_array($id, $managed, true)) {
                continue;
            }
            $count = FacebookCampaignMetrics::pixelAmount($row['actions'] ?? [], $window->goal);
            if ($count === null || isset($results[$id])) {
                return [];
            }
            $results[$id] = ['treatment_id' => $id, 'content' => $row['ad_name'] ?? $id, 'currency' => $window->currency,
                'impressions' => (int) ($row['impressions'] ?? 0), 'clicks' => (int) ($row['inline_link_clicks'] ?? 0),
                'conversions' => (float) $count, 'cost' => (float) ($row['spend'] ?? 0)];
        }

        return $results;
    }
}
