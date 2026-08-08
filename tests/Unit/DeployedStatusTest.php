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
 * Production only ever holds: deployed, deploying, verified, null.
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

    public function test_values_the_column_never_holds_do_not_count(): void
    {
        // 'live' and 'active' were in the old allow-list but are not real values.
        // If either ever becomes one, this test should fail and force a decision.
        $this->assertFalse((new Strategy(['deployment_status' => 'live']))->isDeployed());
        $this->assertFalse((new Strategy(['deployment_status' => 'active']))->isDeployed());
    }

    public function test_the_canonical_set_is_exactly_deployed_and_verified(): void
    {
        $this->assertSame(['deployed', 'verified'], Strategy::DEPLOYED_STATUSES);
    }

    public function test_the_scope_uses_the_same_definition(): void
    {
        $sql = Strategy::query()->deployed()->toRawSql();

        foreach (Strategy::DEPLOYED_STATUSES as $status) {
            $this->assertStringContainsString($status, $sql);
        }
    }
}
