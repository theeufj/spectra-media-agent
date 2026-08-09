<?php

namespace App\Services\GoogleAds\Exclusions;

use App\Services\GoogleAds\BaseGoogleAdsService;

/**
 * Finds placements that are consuming impressions without producing anything.
 *
 * Performance Max decides its own inventory, and left alone it gravitates to the
 * cheapest impressions it can find — which for a B2B advertiser means mobile
 * games and in-app banners. On this account a single placement (the X iOS app)
 * took 16,503 of 38,486 impressions, followed by colouring and jigsaw games,
 * while website inventory was under 2% and search intent was zero.
 *
 * Nothing in the platform could see this: `performance_max_placement_view` was
 * never queried, and TargetingConfig::excluded_placements was a column that no
 * code ever sent to Google.
 *
 * Reporting-only — this never mutates. ExcludePlacements applies the result.
 */
class FindWastefulPlacements extends BaseGoogleAdsService
{
    /** PlacementTypeEnum values we can act on. */
    private const TYPE_WEBSITE = 2;

    private const TYPE_MOBILE_APPLICATION = 4;

    /**
     * Placements with at least this many impressions and no conversions are
     * considered wasteful. Low enough to catch the long tail of app inventory,
     * high enough that a placement is not condemned on a handful of views.
     */
    private const MIN_IMPRESSIONS = 50;

    /**
     * @param  bool  $appsOnly  Restrict to mobile apps — the usual culprit, and
     *                          far safer to exclude wholesale than websites,
     *                          which may include genuine industry publications.
     * @return list<array{kind:string, identifier:string, name:string, impressions:int, clicks:int, cost:float}>
     */
    public function find(string $customerId, int $days = 30, bool $appsOnly = true): array
    {
        $this->ensureClient();

        $since = now()->subDays($days)->toDateString();
        $today = now()->toDateString();

        $query = "SELECT performance_max_placement_view.placement,
                         performance_max_placement_view.display_name,
                         performance_max_placement_view.placement_type,
                         metrics.impressions,
                         metrics.clicks,
                         metrics.cost_micros,
                         metrics.all_conversions
                  FROM performance_max_placement_view
                  WHERE segments.date BETWEEN '{$since}' AND '{$today}'
                  ORDER BY metrics.impressions DESC
                  LIMIT 500";

        $wasteful = [];

        try {
            foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
                $view = $row->getPerformanceMaxPlacementView();
                $metrics = $row->getMetrics();

                // A placement that converts has earned its impressions, however
                // odd it looks.
                if ($metrics->getAllConversions() > 0) {
                    continue;
                }

                if ($metrics->getImpressions() < self::MIN_IMPRESSIONS) {
                    continue;
                }

                $type = $view->getPlacementType();
                $identifier = $view->getPlacement();

                if (! $identifier) {
                    continue;
                }

                $kind = match ($type) {
                    self::TYPE_MOBILE_APPLICATION => 'mobile_application',
                    self::TYPE_WEBSITE => 'website',
                    // GOOGLE_PRODUCTS ("Google Owned & Operated") is Search,
                    // YouTube and Gmail — the inventory we actually want. It is
                    // also not excludable as a placement.
                    default => null,
                };

                if ($kind === null || ($appsOnly && $kind !== 'mobile_application')) {
                    continue;
                }

                $wasteful[] = [
                    'kind' => $kind,
                    'identifier' => $identifier,
                    'name' => $view->getDisplayName() ?: $identifier,
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'cost' => round($metrics->getCostMicros() / 1_000_000, 2),
                ];
            }
        } catch (\Throwable $e) {
            $this->logError('FindWastefulPlacements: query failed', $e);

            return [];
        }

        return $wasteful;
    }
}
