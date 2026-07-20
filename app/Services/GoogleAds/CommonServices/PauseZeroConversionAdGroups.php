<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

/**
 * Deterministically pause ad groups that have spent real money over a trailing window
 * without a single conversion. Queries live ad-group metrics via GAQL (no local
 * ad-group table needed) and pauses the wasteful ones.
 *
 * Guardrails:
 *  - only ENABLED ad groups with conversions == 0 and spend >= $minSpend
 *  - never pauses every enabled ad group in a campaign (that would kill it)
 *  - highest-spend offenders first, capped per run
 */
class PauseZeroConversionAdGroups extends BaseGoogleAdsService
{
    /**
     * @return array{paused: array<int,array{resource:string,name:string,cost:float}>, scanned: int}
     */
    public function forCampaign(Campaign $campaign, float $minSpend, int $windowDays, int $maxPause): array
    {
        $this->ensureClient();

        $campaignId = preg_replace('/\D/', '', (string) $campaign->google_ads_campaign_id);
        if ($campaignId === '') {
            return ['paused' => [], 'scanned' => 0];
        }

        $customerId = $this->customer->cleanGoogleCustomerId();
        $start = now()->subDays($windowDays)->toDateString();
        $end   = now()->subDay()->toDateString();

        $query = "SELECT ad_group.resource_name, ad_group.name, metrics.cost_micros, metrics.conversions "
            . "FROM ad_group "
            . "WHERE campaign.id = {$campaignId} AND ad_group.status = 'ENABLED' "
            . "AND segments.date BETWEEN '{$start}' AND '{$end}'";

        try {
            $rows = [];
            foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                $adGroup  = $row->getAdGroup();
                $metrics  = $row->getMetrics();
                $resource = $adGroup->getResourceName();

                // Aggregate in PHP in case the API segments by date.
                $rows[$resource] ??= ['name' => $adGroup->getName(), 'cost' => 0.0, 'conversions' => 0.0];
                $rows[$resource]['cost']        += ($metrics->getCostMicros() ?? 0) / 1_000_000;
                $rows[$resource]['conversions'] += $metrics->getConversions() ?? 0;
            }

            $enabledCount = count($rows);
            $candidates = array_filter(
                $rows,
                fn ($r) => $r['conversions'] == 0 && $r['cost'] >= $minSpend
            );

            // Never pause the last remaining ad group, and never pause literally every
            // enabled ad group (a whole-campaign problem is not an ad-group fix).
            if ($enabledCount <= 1 || count($candidates) >= $enabledCount) {
                return ['paused' => [], 'scanned' => $enabledCount];
            }

            // Highest-spend offenders first, capped.
            uasort($candidates, fn ($a, $b) => $b['cost'] <=> $a['cost']);
            $candidates = array_slice($candidates, 0, max(1, $maxPause), true);

            $pauser = new UpdateAdGroupStatus($this->customer);
            $paused = [];

            foreach ($candidates as $resource => $info) {
                $result = $pauser->pause($customerId, $resource);
                if ($result['success']) {
                    $paused[] = [
                        'resource' => $resource,
                        'name'     => $info['name'],
                        'cost'     => round($info['cost'], 2),
                    ];
                }
            }

            return ['paused' => $paused, 'scanned' => $enabledCount];

        } catch (GoogleAdsException $e) {
            $this->logError("PauseZeroConversionAdGroups: query/pause failed for campaign {$campaign->id}: " . $e->getMessage());
            return ['paused' => [], 'scanned' => 0];
        }
    }
}
