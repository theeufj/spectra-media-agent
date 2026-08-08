<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;

/**
 * Deploys one Google Ads campaign type (Search, Display, Performance Max, …).
 *
 * Implementations mutate $result rather than throwing for partial failure: a
 * deploy that creates the campaign but fails two ad extensions is a success with
 * warnings, not an exception. Only unrecoverable errors should propagate.
 */
interface CampaignTypeExecutor
{
    public function execute(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void;
}
