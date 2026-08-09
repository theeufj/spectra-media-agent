<?php

namespace App\Contracts\Ads;

/**
 * Writes keyword changes back to an ad platform.
 *
 * The write half of the seam described on SearchTermSource. In the sandbox this
 * records the intended change instead of sending it, so the agent's decisions
 * can be inspected without touching a real account or spending anything.
 */
interface KeywordMutator
{
    /**
     * @return string|null Resource name of the created criterion, or null on failure
     */
    public function addKeyword(string $customerId, string $adGroupResourceName, string $keyword, int $matchType): ?string;

    /**
     * @return string|null Resource name of the created criterion, or null on failure
     */
    public function addNegativeKeyword(string $customerId, string $campaignResourceName, string $keyword, int $matchType): ?string;
}
