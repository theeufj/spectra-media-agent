<?php

namespace App\Services\GoogleAds\Exclusions;

use App\Services\GoogleAds\BaseGoogleAdsService;

/**
 * Finds placements worth excluding from a Performance Max account.
 *
 * PMax picks its own inventory, and left alone it gravitates to the cheapest
 * impressions available — which for a B2B advertiser means mobile games and
 * in-app banners. On this account the X iOS app alone took 16,503 of 38,486
 * impressions, followed by colouring and jigsaw games, while website inventory
 * was under 2% and search intent was zero.
 *
 * An important API limitation shapes this: `performance_max_placement_view`
 * exposes ONLY `metrics.impressions`. Clicks, cost and conversions are all
 * rejected on that resource, so it is impossible to prove that a given PMax
 * placement failed to convert. Selection is therefore by inventory *type* and
 * volume, not by measured performance:
 *
 *   - mobile app placements above an impression floor are excluded, because app
 *     inventory is categorically wrong for the advertisers this platform serves
 *   - websites are left alone by default: a trade publication and a content farm
 *     are indistinguishable here, and excluding real publishers does harm
 *
 * Reporting-only — never mutates. ExcludePlacements applies the result.
 */
class FindWastefulPlacements extends BaseGoogleAdsService
{
    /** PlacementTypeEnum values. */
    private const TYPE_WEBSITE = 2;

    private const TYPE_MOBILE_APPLICATION = 4;

    /**
     * Impression floor before a placement is worth excluding. Low enough to
     * catch the long tail of app inventory, high enough that a placement is not
     * condemned on a handful of views.
     */
    private const MIN_IMPRESSIONS = 50;

    /**
     * @param  bool  $appsOnly  Restrict to mobile apps — the usual culprit, and
     *                          far safer to exclude wholesale than websites.
     * @return list<array{kind:string, identifier:string, name:string, impressions:int}>
     *
     * @throws \Throwable when the query fails — a failure must not be
     *                    indistinguishable from "nothing to exclude"
     */
    public function find(string $customerId, int $days = 30, bool $appsOnly = true): array
    {
        $this->ensureClient();

        $since = now()->subDays($days)->toDateString();
        $today = now()->toDateString();

        // metrics.impressions is the ONLY metric this resource accepts; adding
        // clicks/cost/conversions makes Google reject the whole request.
        $query = "SELECT performance_max_placement_view.placement,
                         performance_max_placement_view.display_name,
                         performance_max_placement_view.placement_type,
                         metrics.impressions
                  FROM performance_max_placement_view
                  WHERE segments.date BETWEEN '{$since}' AND '{$today}'
                  ORDER BY metrics.impressions DESC
                  LIMIT 500";

        $candidates = [];

        try {
            foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
                $view = $row->getPerformanceMaxPlacementView();
                $impressions = $row->getMetrics()->getImpressions();

                if ($impressions < self::MIN_IMPRESSIONS) {
                    continue;
                }

                $identifier = $view->getPlacement();
                if (! $identifier) {
                    continue;
                }

                $kind = match ($view->getPlacementType()) {
                    self::TYPE_MOBILE_APPLICATION => 'mobile_application',
                    self::TYPE_WEBSITE => 'website',
                    // GOOGLE_PRODUCTS ("Google Owned & Operated") is Search,
                    // YouTube and Gmail — the inventory we actually want, and
                    // not excludable as a placement in any case.
                    default => null,
                };

                if ($kind === null || ($appsOnly && $kind !== 'mobile_application')) {
                    continue;
                }

                $candidates[] = [
                    'kind' => $kind,
                    'identifier' => $identifier,
                    'name' => $view->getDisplayName() ?: $identifier,
                    'impressions' => $impressions,
                ];
            }
        } catch (\Throwable $e) {
            // Deliberately rethrown. Returning [] would report a broken query as
            // "no wasteful placements" — the same silent-success failure this
            // whole change exists to eliminate.
            $this->logError('FindWastefulPlacements: query failed', $e);

            throw $e;
        }

        return $candidates;
    }
}
