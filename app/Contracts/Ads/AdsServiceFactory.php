<?php

namespace App\Contracts\Ads;

use App\Models\Customer;

/**
 * Hands an agent the right platform services for a given customer.
 *
 * Routing is per-customer rather than by a global "sandbox mode" flag, and
 * deliberately so: a mode switch can be left on, set by one request and read by
 * another, or forgotten in a queue worker. Keying off Customer::is_sandbox makes
 * it structurally impossible for a sandbox run to touch a real ad account, or
 * for a real run to be silently served fake data.
 */
interface AdsServiceFactory
{
    public function searchTerms(Customer $customer): SearchTermSource;

    public function keywords(Customer $customer): KeywordMutator;

    public function campaignPerformance(Customer $customer): CampaignPerformanceSource;

    public function budgets(Customer $customer): BudgetMutator;

    public function accountStatus(Customer $customer): AccountStatusSource;

    public function adStatus(Customer $customer): AdStatusSource;

    public function assetPerformance(Customer $customer): AssetPerformanceSource;

    public function facebookInsights(Customer $customer): FacebookInsightSource;

    public function facebookAds(Customer $customer): FacebookAdManager;

    /**
     * Keyword changes the agent decided on but did not send, for sandbox runs.
     * Always empty for live customers, where changes are actually applied.
     *
     * @return list<array{action: string, keyword: string, match_type: int, target: string}>
     */
    public function recordedDecisions(Customer $customer): array;

    /**
     * Budget changes decided but not sent, for sandbox runs. Empty when live.
     *
     * @return list<array{campaign: string, daily_budget_micros: float}>
     */
    public function recordedBudgetChanges(Customer $customer): array;

    /**
     * Meta ad/creative changes decided but not sent, for sandbox runs.
     *
     * @return list<array{action: string, target: string, detail: string}>
     */
    public function recordedFacebookChanges(Customer $customer): array;
}
