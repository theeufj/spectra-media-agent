<?php

namespace App\Services\Ads;

use App\Contracts\Ads\AccountStatusSource;
use App\Contracts\Ads\AdsServiceFactory;
use App\Contracts\Ads\AdStatusSource;
use App\Contracts\Ads\AssetPerformanceSource;
use App\Contracts\Ads\BudgetMutator;
use App\Contracts\Ads\CampaignPerformanceSource;
use App\Contracts\Ads\FacebookAdManager;
use App\Contracts\Ads\FacebookInsightSource;
use App\Contracts\Ads\KeywordMutator;
use App\Contracts\Ads\SearchTermSource;
use App\Models\Customer;
use App\Services\FacebookAds\Adapters\LiveFacebookAdManager;
use App\Services\FacebookAds\Adapters\LiveFacebookInsightSource;
use App\Services\GoogleAds\CommonServices\GetAccountStatus;
use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\GoogleBudgetMutator;
use App\Services\GoogleAds\CommonServices\GoogleKeywordMutator;
use App\Services\Testing\Sandbox\SandboxAccountStatusSource;
use App\Services\Testing\Sandbox\SandboxAdStatusSource;
use App\Services\Testing\Sandbox\SandboxAssetPerformanceSource;
use App\Services\Testing\Sandbox\SandboxBudgetMutator;
use App\Services\Testing\Sandbox\SandboxCampaignPerformanceSource;
use App\Services\Testing\Sandbox\SandboxFacebookAdManager;
use App\Services\Testing\Sandbox\SandboxFacebookInsightSource;
use App\Services\Testing\Sandbox\SandboxKeywordMutator;
use App\Services\Testing\Sandbox\SandboxSearchTermSource;

/**
 * Chooses live or sandbox platform services based on the customer alone.
 *
 * The single decision point is Customer::is_sandbox. There is no global mode to
 * set, so a sandbox run cannot leak into a real ad account and a real run cannot
 * be quietly served synthetic data — the two cases are decided by data, not by
 * ambient state that a queue worker might inherit or a request might forget to
 * reset.
 *
 * Sandbox instances are memoised per customer so a caller can retrieve the same
 * SandboxKeywordMutator afterwards and inspect what the agent decided to do.
 */
class CustomerRoutedAdsServiceFactory implements AdsServiceFactory
{
    /** @var array<int, KeywordMutator> */
    private array $mutators = [];

    /** @var array<int, BudgetMutator> */
    private array $budgetMutators = [];

    /** @var array<int, FacebookAdManager> */
    private array $facebookManagers = [];

    public function searchTerms(Customer $customer): SearchTermSource
    {
        return $customer->is_sandbox
            ? new SandboxSearchTermSource($customer)
            : new GetSearchTermsReport($customer);
    }

    public function keywords(Customer $customer): KeywordMutator
    {
        if ($customer->is_sandbox) {
            return $this->mutators[$customer->id] ??= new SandboxKeywordMutator($customer);
        }

        return new GoogleKeywordMutator($customer);
    }

    public function campaignPerformance(Customer $customer): CampaignPerformanceSource
    {
        return $customer->is_sandbox
            ? new SandboxCampaignPerformanceSource($customer)
            : new GetCampaignPerformance($customer);
    }

    public function budgets(Customer $customer): BudgetMutator
    {
        if ($customer->is_sandbox) {
            return $this->budgetMutators[$customer->id] ??= new SandboxBudgetMutator($customer);
        }

        return new GoogleBudgetMutator($customer);
    }

    public function accountStatus(Customer $customer): AccountStatusSource
    {
        return $customer->is_sandbox
            ? new SandboxAccountStatusSource($customer)
            : new GetAccountStatus($customer);
    }

    public function adStatus(Customer $customer): AdStatusSource
    {
        return $customer->is_sandbox
            ? new SandboxAdStatusSource($customer)
            : new GetAdStatus($customer);
    }

    public function assetPerformance(Customer $customer): AssetPerformanceSource
    {
        return $customer->is_sandbox
            ? new SandboxAssetPerformanceSource($customer)
            : new GetAdPerformanceByAsset($customer);
    }

    public function facebookInsights(Customer $customer): FacebookInsightSource
    {
        return $customer->is_sandbox
            ? new SandboxFacebookInsightSource($customer)
            : new LiveFacebookInsightSource($customer);
    }

    public function facebookAds(Customer $customer): FacebookAdManager
    {
        if ($customer->is_sandbox) {
            return $this->facebookManagers[$customer->id] ??= new SandboxFacebookAdManager($customer);
        }

        return new LiveFacebookAdManager($customer);
    }

    /**
     * @return list<array{action: string, target: string, detail: string}>
     */
    public function recordedFacebookChanges(Customer $customer): array
    {
        $manager = $this->facebookManagers[$customer->id] ?? null;

        return $manager instanceof SandboxFacebookAdManager ? $manager->recorded() : [];
    }

    /**
     * @return list<array{campaign: string, daily_budget_micros: float}>
     */
    public function recordedBudgetChanges(Customer $customer): array
    {
        $mutator = $this->budgetMutators[$customer->id] ?? null;

        return $mutator instanceof SandboxBudgetMutator ? $mutator->recorded() : [];
    }

    /**
     * What the agent decided to do, for sandbox runs.
     *
     * Lets a sandbox report the agent's actual decisions rather than
     * re-deriving them — that re-derivation is what made the old sandbox
     * misleading. Live customers return empty: their changes were really sent.
     *
     * @return list<array{action: string, keyword: string, match_type: int, target: string}>
     */
    public function recordedDecisions(Customer $customer): array
    {
        $mutator = $this->mutators[$customer->id] ?? null;

        return $mutator instanceof SandboxKeywordMutator ? $mutator->recorded() : [];
    }
}
