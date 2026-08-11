<?php

namespace App\Contracts\Ads;

/**
 * Writes a campaign's daily budget back to the platform.
 *
 * The write half for BudgetIntelligenceAgent. In the sandbox the change is
 * recorded rather than sent, so budget decisions can be reviewed without any
 * possibility of moving real money.
 */
interface BudgetMutator
{
    public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool;
}
