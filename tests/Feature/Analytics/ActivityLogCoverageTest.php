<?php

namespace Tests\Feature\Analytics;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The activity log knew twenty-two action types and only ever recorded two.
 *
 * Every entry in production was a login or a logout, so the page read as a
 * sign-in history rather than a record of what happens on the platform. The
 * labels, the filters and the CSV export were all built for events nothing
 * wrote. These tests pin the events that now do.
 */
class ActivityLogCoverageTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();
        DB::table('activity_logs')->delete();

        $this->customer = Customer::factory()->create();
        // campaigns.store is behind the `subscribed` middleware, so an
        // unsubscribed user is redirected before the controller runs.
        $this->user = User::factory()->create(['subscription_status' => 'active']);
        $this->user->customers()->attach($this->customer->id, ['role' => 'owner']);
    }

    public function test_creating_a_campaign_is_recorded(): void
    {
        $this->actingAs($this->user);

        ActivityLogger::campaign('created', Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Spring sale',
        ]));

        $entry = ActivityLog::latest('id')->first();

        $this->assertSame('campaign_created', $entry->action);
        $this->assertStringContainsString('Spring sale', $entry->description);
        $this->assertSame($this->user->id, $entry->user_id);
    }

    public function test_a_deployment_is_recorded_with_a_description_that_stands_alone(): void
    {
        // Deployment runs on the queue, so there is no authenticated user on
        // the row. If the description did not carry the campaign name the entry
        // would read as an anonymous "campaign action" and be useless.
        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Winter promo',
        ]);

        ActivityLogger::campaign('deployed', $campaign, ['successful' => 2, 'failed' => 0]);

        $entry = ActivityLog::latest('id')->first();

        $this->assertSame('campaign_deployed', $entry->action);
        $this->assertStringContainsString('Winter promo', $entry->description);
        $this->assertStringNotContainsString('Campaign action', $entry->description);
        $this->assertNull($entry->user_id);
        $this->assertSame(2, $entry->properties['successful']);
    }

    public function test_a_partial_deployment_is_distinguishable_from_a_clean_one(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        ActivityLogger::campaign('deploy_blocked', $campaign, ['successful' => 1, 'failed' => 2]);

        $entry = ActivityLog::latest('id')->first();

        $this->assertSame('campaign_deploy_blocked', $entry->action);
        $this->assertStringNotContainsString('Campaign action', $entry->description);
    }

    public function test_creating_an_account_is_recorded(): void
    {
        $this->actingAs($this->user);

        ActivityLogger::customer('created', $this->customer);

        $this->assertSame('customer_created', ActivityLog::latest('id')->first()->action);
    }

    public function test_billing_setup_is_recorded(): void
    {
        $this->actingAs($this->user);

        // The readiness audit found 14 of 15 accounts with no credit account,
        // so this is the event that unblocks the platform.
        ActivityLogger::adSpendBillingSetup($this->customer, 50.0);

        $entry = ActivityLog::latest('id')->first();

        $this->assertSame('ad_spend_billing_setup', $entry->action);
        $this->assertEquals(50.0, $entry->properties['daily_budget']);
    }

    public function test_the_campaign_store_endpoint_records_the_creation(): void
    {
        // End-to-end through the real route: the logger being correct is worth
        // nothing if it is not wired into the path a customer actually takes.
        $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('campaigns.store'), [
                'name' => 'Endpoint campaign',
                'reason' => 'Launching a new product line',
                'goals' => 'Drive qualified traffic',
                'target_market' => 'Small businesses in Australia',
                'voice' => 'Direct and practical',
                'primary_kpi' => 'conversions',
                'total_budget' => 500,
                'daily_budget' => 25,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'platforms' => ['google'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'campaign_created',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_every_recorded_action_has_a_human_label(): void
    {
        // The admin page renders getActionLabelAttribute. An action written
        // without a matching label shows a raw slug, which is how a log stops
        // being readable.
        foreach (['campaign_created', 'campaign_deployed', 'campaign_deploy_blocked',
            'customer_created', 'ad_spend_billing_setup'] as $action) {
            $entry = new ActivityLog(['action' => $action]);

            $this->assertNotSame(
                $action,
                $entry->action_label,
                "action {$action} renders as a raw slug in the admin log",
            );
        }
    }
}
