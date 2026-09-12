<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The helpers that tell a customer their work is ready.
 *
 * All nine of these had zero callers, and it was not an oversight: six of them
 * reached for `$campaign->customer->user`, a singular relation that does not
 * exist — ownership is the `customers` pivot on User and `customers.user_id`
 * was dropped — and three built links from route names that were never
 * registered (campaigns.strategies.show, campaigns.deployment.status). Calling
 * any of them threw. Nobody could have used them if they tried.
 *
 * The consequence was an asymmetry the customer feels: failures were announced,
 * successes never were. Nothing ever told them their strategy was ready to
 * review, which is the step the entire funnel is waiting on.
 *
 * The first repair sent each of these to a single owner, which is a quieter
 * version of the same bug: an account is routinely held by several people —
 * sitetospend's own has four — and `first()` picked whichever the database
 * returned first. Three of them would never learn a deployment had failed.
 * These now pin the fan-out, which is what every other notifying path in the
 * product already does.
 */
class NotificationServiceHelpersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * An account held by more than one person, which is the normal case: two
     * owners and an admin, as sitetospend's own account is arranged.
     *
     * @return array{\Illuminate\Support\Collection<int, User>, Campaign, Strategy}
     */
    private function scenario(): array
    {
        $customer = Customer::factory()->create();
        $owner = User::factory()->create();
        $coOwner = User::factory()->create();
        $admin = User::factory()->create();

        $customer->users()->attach($owner->id, ['role' => 'owner']);
        $customer->users()->attach($coOwner->id, ['role' => 'owner']);
        $customer->users()->attach($admin->id, ['role' => 'admin']);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'name' => 'Spring Lead Gen']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);

        return [collect([$owner, $coOwner, $admin]), $campaign, $strategy];
    }

    public function test_strategy_ready_reaches_everyone_on_the_account(): void
    {
        [$people, $campaign, $strategy] = $this->scenario();

        $rows = app(NotificationService::class)->notifyStrategyReady($campaign, $strategy);

        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            $people->pluck('id')->all(),
            $rows->pluck('user_id')->all(),
            'the co-owner and the admin were left out',
        );
        $this->assertStringContainsString('Spring Lead Gen', $rows->first()->message);
        $this->assertNotNull($rows->first()->action_url);
    }

    public function test_collateral_ready_reaches_everyone_on_the_account(): void
    {
        [$people, $campaign, $strategy] = $this->scenario();

        $rows = app(NotificationService::class)->notifyCollateralReady($campaign, $strategy);

        $this->assertEqualsCanonicalizing($people->pluck('id')->all(), $rows->pluck('user_id')->all());
    }

    /** @return array<string, array{string}> */
    public static function campaignHelpers(): array
    {
        return [
            'strategy ready' => ['notifyStrategyReady'],
            'collateral ready' => ['notifyCollateralReady'],
            'deployment started' => ['notifyDeploymentStarted'],
            'deployment completed' => ['notifyDeploymentCompleted'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('campaignHelpers')]
    public function test_every_campaign_helper_builds_a_real_link(string $method): void
    {
        // Three of them pointed at route names that were never registered, so
        // they threw RouteNotFoundException rather than notifying anyone.
        [, $campaign, $strategy] = $this->scenario();

        $rows = app(NotificationService::class)->{$method}($campaign, $strategy);

        $this->assertCount(3, $rows, "{$method} did not reach everyone on the account.");

        foreach ($rows as $row) {
            $this->assertStringStartsWith('http', (string) $row->action_url);
            $this->assertNotSame('Notification', $row->title);
            $this->assertNotSame('', $row->message);
        }
    }

    public function test_deployment_failed_carries_the_reason(): void
    {
        [, $campaign, $strategy] = $this->scenario();

        $rows = app(NotificationService::class)
            ->notifyDeploymentFailed($campaign, $strategy, 'Google rejected the budget.');

        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertStringContainsString('Google rejected the budget.', $row->message);
        }
    }

    public function test_a_customer_with_no_users_notifies_nobody_rather_than_throwing(): void
    {
        // A queue worker must not die because a customer has nobody attached.
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);

        $this->assertCount(0, app(NotificationService::class)->notifyStrategyReady($campaign, $strategy));
    }
}
