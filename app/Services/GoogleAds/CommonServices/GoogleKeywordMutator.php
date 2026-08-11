<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\KeywordMutator;
use App\Models\Customer;

/**
 * Live implementation: sends keyword changes to Google Ads.
 *
 * A thin adapter over the existing AddKeyword / AddNegativeKeyword services so
 * agents depend on the KeywordMutator contract rather than constructing those
 * directly. Behaviour is unchanged — the point is only that the dependency can
 * now be substituted.
 */
class GoogleKeywordMutator implements KeywordMutator
{
    public function __construct(private Customer $customer) {}

    public function addKeyword(string $customerId, string $adGroupResourceName, string $keyword, int $matchType): ?string
    {
        return (new AddKeyword($this->customer))(
            $customerId,
            $adGroupResourceName,
            $keyword,
            $matchType
        );
    }

    public function addNegativeKeyword(string $customerId, string $campaignResourceName, string $keyword, int $matchType): ?string
    {
        return (new AddNegativeKeyword($this->customer))(
            $customerId,
            $campaignResourceName,
            $keyword,
            $matchType
        );
    }
}
