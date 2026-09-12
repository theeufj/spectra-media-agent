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
 */
class NotificationServiceHelpersTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign, Strategy} */
    private function scenario(): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'name' => 'Spring Lead Gen']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);

        return [$user, $campaign, $strategy];
    }

    public function test_strategy_ready_reaches_the_owner(): void
    {
        [$user, $campaign, $strategy] = $this->scenario();

        $row = app(NotificationService::class)->notifyStrategyReady($campaign, $strategy);

        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->user_id);
        $this->assertStringContainsString('Spring Lead Gen', $row->message);
        $this->assertNotNull($row->action_url);
    }

    public function test_collateral_ready_reaches_the_owner(): void
    {
        [$user, $campaign, $strategy] = $this->scenario();

        $row = app(NotificationService::class)->notifyCollateralReady($campaign, $strategy);

        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->user_id);
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

        $row = app(NotificationService::class)->{$method}($campaign, $strategy);

        $this->assertNotNull($row, "{$method} produced no notification.");
        $this->assertStringStartsWith('http', (string) $row->action_url);
        $this->assertNotSame('Notification', $row->title);
        $this->assertNotSame('', $row->message);
    }

    public function test_deployment_failed_carries_the_reason(): void
    {
        [, $campaign, $strategy] = $this->scenario();

        $row = app(NotificationService::class)
            ->notifyDeploymentFailed($campaign, $strategy, 'Google rejected the budget.');

        $this->assertNotNull($row);
        $this->assertStringContainsString('Google rejected the budget.', $row->message);
    }

    public function test_a_customer_with_no_users_returns_null_rather_than_throwing(): void
    {
        // A queue worker must not die because a customer has no owner attached.
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);

        $this->assertNull(app(NotificationService::class)->notifyStrategyReady($campaign, $strategy));
    }
}
