<?php

namespace App\Contracts\Ads;

/** Budget writes that can prove an actual change with platform before/after reads. */
interface ReportsBudgetChanges extends BudgetMutator
{
    /**
     * @return array{success: bool, changes: list<array{platform: string, resource_name: string, old_budget_micros: int, new_budget_micros: int, currency: string}>}
     */
    public function updateDailyBudgetWithChanges(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): array;
}
