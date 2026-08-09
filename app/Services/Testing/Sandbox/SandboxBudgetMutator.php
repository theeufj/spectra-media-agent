<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\BudgetMutator;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Records budget changes instead of applying them.
 *
 * Returns true so the agent's success path runs exactly as it would live —
 * every decision, log line and result field is real; only the network call is
 * not, and no real money can move.
 */
class SandboxBudgetMutator implements BudgetMutator
{
    /** @var list<array{campaign: string, daily_budget_micros: float}> */
    private array $recorded = [];

    public function __construct(private Customer $customer) {}

    public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
    {
        $this->recorded[] = [
            'campaign' => $campaignResourceName,
            'daily_budget_micros' => $newDailyBudgetMicros,
        ];

        Log::info('SandboxBudgetMutator: recorded intended budget change (nothing sent)', [
            'customer_id' => $this->customer->id,
            'campaign' => $campaignResourceName,
            'daily_budget' => $newDailyBudgetMicros / 1_000_000,
        ]);

        return true;
    }

    /** @return list<array{campaign: string, daily_budget_micros: float}> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
