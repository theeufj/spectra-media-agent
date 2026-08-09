<?php

namespace App\Contracts\Ads;

/**
 * Supplies aggregate campaign performance.
 *
 * The most widely shared platform read in the codebase — BudgetIntelligenceAgent
 * and GoogleAdsHealthChecker both depend on it — so putting a seam here lets
 * both run against synthetic data at once.
 */
interface CampaignPerformanceSource
{
    /**
     * Callers receive at least: impressions, clicks, cost_micros, conversions,
     * ctr, average_cpc, cost_per_conversion.
     *
     * Deliberately typed loosely rather than as a sealed shape: implementations
     * legitimately return supersets (some paths include conversion_value), and
     * pinning an exact shape here would flag the agents' existing defensive key
     * access as redundant when it is not.
     *
     * @return array<string, mixed>|null
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): ?array;
}
