<?php

namespace Tests\Unit;

use App\Models\Strategy;
use Tests\TestCase;

/**
 * "Deployed" must mean the same thing everywhere.
 *
 * AutoStartABTests checked `in_array($strategy->deployment_status, ['deployed',
 * 'live', 'active'])`. Two of those are values the column never holds, and
 * 'verified' — the terminal success state, set once VerifyDeployment confirms the
 * objects exist on the platform — was missing.
 *
 * So the strategies that had deployed *most* successfully were exactly the ones
 * excluded from A/B testing. Across 20 scheduled runs it started zero tests, and
 * EvaluateABTests then had nothing to evaluate: 40 runs, 0 actions.
 *
 * ActivateCampaigns also writes 'active' after enabling the platform campaign.
 */
class DeployedStatusTest extends TestCase
{
    public function test_verified_counts_as_deployed(): void
    {
        // The regression: 'verified' is the success state, not an edge case.
        $this->assertTrue((new Strategy(['deployment_status' => 'verified']))->isDeployed());
    }

    public function test_deployed_counts_as_deployed(): void
    {
        $this->assertTrue((new Strategy(['deployment_status' => 'deployed']))->isDeployed());
    }

    public function test_in_flight_and_unset_do_not_count(): void
    {
        $this->assertFalse((new Strategy(['deployment_status' => 'deploying']))->isDeployed());
        $this->assertFalse((new Strategy(['deployment_status' => null]))->isDeployed());
    }

    public function test_active_campaigns_still_count_as_deployed(): void
    {
        $this->assertTrue((new Strategy(['deployment_status' => 'active']))->isDeployed());
        $this->assertFalse((new Strategy(['deployment_status' => 'live']))->isDeployed());
    }

    public function test_the_canonical_set_includes_all_live_states(): void
    {
        $this->assertSame(['deployed', 'verified', 'active'], Strategy::DEPLOYED_STATUSES);
        $this->assertSame(['deployed', 'verified'], Strategy::ACTIVATABLE_STATUSES);
    }

    public function test_the_scope_uses_the_same_definition(): void
    {
        $sql = Strategy::query()->deployed()->toRawSql();

        foreach (Strategy::DEPLOYED_STATUSES as $status) {
            $this->assertStringContainsString($status, $sql);
        }
    }
}
