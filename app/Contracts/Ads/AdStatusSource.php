<?php

namespace App\Contracts\Ads;

/**
 * Reports the status and approval state of ads in a campaign.
 *
 * Used by SelfHealingAgent to spot disapproved or paused ads. Seamed so the
 * agent's healing decisions can be exercised without a live account.
 */
interface AdStatusSource
{
    public function __invoke(string $customerId, ?string $campaignResourceName = null, ?string $adGroupResourceName = null): array;
}
