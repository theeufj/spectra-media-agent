<?php

namespace App\Services\Ads;

use App\Contracts\Ads\AdsServiceFactory;
use App\Contracts\Ads\KeywordMutator;
use App\Contracts\Ads\SearchTermSource;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\GoogleKeywordMutator;
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
