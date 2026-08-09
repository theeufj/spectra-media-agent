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

    /**
     * Keyword changes the agent decided on but did not send, for sandbox runs.
     * Always empty for live customers, where changes are actually applied.
     *
     * @return list<array{action: string, keyword: string, match_type: int, target: string}>
     */
    public function recordedDecisions(Customer $customer): array;
}
