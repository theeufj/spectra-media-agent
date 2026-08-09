<?php

namespace App\Contracts\Ads;

/**
 * Supplies the search-terms report for a campaign.
 *
 * Exists so SearchTermMiningAgent can be exercised against synthetic data
 * without calling a live ad platform. Previously the agent constructed
 * GetSearchTermsReport directly, which made it untestable and unrunnable in the
 * sandbox — so the sandbox reimplemented the agent's logic instead of calling
 * it, and reported the results under the real agent's name. That produced
 * evidence the agent worked when it had never once been invoked.
 */
interface SearchTermSource
{
    /**
     * @return list<array{
     *     search_term: string,
     *     impressions: int,
     *     clicks: int,
     *     cost: float,
     *     conversions: float,
     *     ctr: float,
     *     campaign_resource_name?: string,
     *     ad_group_resource_name?: string
     * }>
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array;
}
