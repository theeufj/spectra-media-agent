<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Analytics\AdoptionMetrics;
use App\Services\Analytics\UsagePeriod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsageFunnelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // Every metric here is cached; without this the second test in the file
        // reads the first one's numbers.
        Cache::flush();

        // DatabaseTransactions rolls back, but this suite asserts on GLOBAL
        // counts, so anything left by a previous run or a seeder would be
        // counted. Start from a known-empty world.
        DB::table('strategies')->delete();
        DB::table('campaigns')->delete();
        DB::table('customer_user')->delete();
        DB::table('customers')->delete();
        DB::table('users')->delete();
    }

    private function metrics(string $period = '30'): AdoptionMetrics
    {
        return new AdoptionMetrics(UsagePeriod::fromRequest($period));
    }

    private function stepCount(array $funnel, string $key): int
    {
        foreach ($funnel as $step) {
            if ($step['key'] === $key) {
                return $step['count'];
            }
        }

        $this->fail("no funnel step '{$key}'");
    }

    /**
     * An account with an owner, since the funnel is anchored on users: a
     * customer nobody belongs to is unreachable from every step.
     */
    private function ownedCustomer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->create($attributes);

        User::factory()
            ->create(['created_at' => $customer->created_at])
            ->customers()
            ->attach($customer->id, ['role' => 'owner']);

        return $customer;
    }

    /** An account whose owner walks the whole funnel. */
    private function fullyActivatedCustomer(array $attributes = []): Customer
    {
        $customer = $this->ownedCustomer($attributes);

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'strategy_generation_completed_at' => now()->subDay(),
            'primary_status' => 'ELIGIBLE',
        ]);

        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'deployment_status' => 'deployed',
            'deployed_at' => now()->subDay(),
        ]);

        return $customer;
    }

    // ── The exclusions ───────────────────────────────────────────────────────

    public function test_sandbox_accounts_are_excluded_from_every_step(): void
    {
        // A sandbox account walks the entire funnel by construction, so leaving
        // them in inflates every conversion rate on the page — the most
        // embarrassing possible failure for a number shown to a board.
        $this->fullyActivatedCustomer(['is_sandbox' => true]);

        $funnel = $this->metrics()->funnel();

        $this->assertSame(0, $this->stepCount($funnel, 'created_account'));
        $this->assertSame(0, $this->stepCount($funnel, 'live'));
    }

    public function test_soft_deleted_accounts_are_excluded(): void
    {
        $customer = $this->fullyActivatedCustomer();
        $customer->delete();

        $funnel = $this->metrics()->funnel();

        $this->assertSame(0, $this->stepCount($funnel, 'created_account'));
    }

    public function test_accounts_created_outside_the_window_are_excluded(): void
    {
        // Cohort-scoped, not event-scoped: this account activated inside the
        // window but signed up long before it, and counting it would let old
        // accounts flatter this month's activation rate.
        $this->fullyActivatedCustomer(['created_at' => now()->subDays(200)]);

        $funnel = $this->metrics('30')->funnel();

        $this->assertSame(0, $this->stepCount($funnel, 'created_account'));
    }

    // ── The steps ────────────────────────────────────────────────────────────

    public function test_each_step_counts_only_users_that_reached_it(): void
    {
        // One user per depth, so every step has a distinct expected count.
        $this->ownedCustomer();                                           // account only

        $withCampaign = $this->ownedCustomer();
        Campaign::factory()->create(['customer_id' => $withCampaign->id]);

        $withStrategy = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $withStrategy->id,
            'strategy_generation_completed_at' => now(),
        ]);

        $withDeploy = $this->ownedCustomer();
        $deployCampaign = Campaign::factory()->create([
            'customer_id' => $withDeploy->id,
            'strategy_generation_completed_at' => now(),
        ]);
        Strategy::factory()->create([
            'campaign_id' => $deployCampaign->id,
            'deployment_status' => 'deployed',
            'deployed_at' => now(),
        ]);

        $this->fullyActivatedCustomer();                                  // all the way

        $funnel = $this->metrics()->funnel();

        $this->assertSame(5, $this->stepCount($funnel, 'created_account'));
        $this->assertSame(4, $this->stepCount($funnel, 'created_campaign'));
        $this->assertSame(3, $this->stepCount($funnel, 'generated_strategy'));
        $this->assertSame(2, $this->stepCount($funnel, 'deployed'));
        $this->assertSame(1, $this->stepCount($funnel, 'live'));
    }

    public function test_limited_and_learning_campaigns_count_as_live(): void
    {
        // Both serve impressions — constrained or still calibrating, but live.
        // Counting only ELIGIBLE would under-report activation.
        foreach (['ELIGIBLE', 'LIMITED', 'LEARNING'] as $status) {
            $customer = $this->ownedCustomer();
            Campaign::factory()->create(['customer_id' => $customer->id, 'primary_status' => $status]);
        }

        $this->assertSame(3, $this->stepCount($this->metrics()->funnel(), 'live'));
    }

    public function test_a_pending_campaign_is_not_yet_live(): void
    {
        $customer = $this->ownedCustomer();
        Campaign::factory()->create(['customer_id' => $customer->id, 'primary_status' => 'PENDING']);

        $this->assertSame(0, $this->stepCount($this->metrics()->funnel(), 'live'));
    }

    public function test_step_to_step_rates_are_computed_against_the_previous_step(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->ownedCustomer();
        }
        $withCampaign = $this->ownedCustomer();
        Campaign::factory()->create(['customer_id' => $withCampaign->id]);

        $funnel = $this->metrics()->funnel();
        $campaignStep = collect($funnel)->firstWhere('key', 'created_campaign');

        // 1 of 5 accounts made a campaign.
        $this->assertSame(20.0, $campaignStep['pct_of_previous']);
    }

    public function test_no_step_can_exceed_the_signup_count(): void
    {
        // Regression guard. An earlier version counted signups from `users` and
        // every later step from `customers` — two unrelated populations — so
        // "percent of signups" reached 7400% on real data. Every step must be a
        // subset of the one it descends from, which is what makes it a funnel
        // rather than six unrelated numbers on a shared axis.
        foreach (range(1, 3) as $ignored) {
            $this->fullyActivatedCustomer();
        }

        // One account owned by two people, the shape that broke the old version.
        $shared = $this->fullyActivatedCustomer();
        User::factory()->create()->customers()->attach($shared->id, ['role' => 'member']);

        $funnel = $this->metrics()->funnel();
        $signups = $this->stepCount($funnel, 'signed_up');

        foreach ($funnel as $step) {
            $this->assertLessThanOrEqual(
                $signups,
                $step['count'],
                "step '{$step['key']}' counted more than the signups it descends from",
            );
            $this->assertLessThanOrEqual(100.0, $step['pct_of_start']);
        }
    }

    // ── Status drift ─────────────────────────────────────────────────────────

    public function test_a_campaign_we_believe_is_live_but_is_not_shows_as_drift(): void
    {
        // The BILL-8 shape: billed as active while the platform stopped serving.
        $customer = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => 'MISCONFIGURED',
        ]);

        $drift = $this->metrics()->statusDrift();

        $this->assertSame(1, $drift['believed_live_but_not']);
        $this->assertSame(0, $drift['believed_stopped_but_live']);
    }

    public function test_a_campaign_still_serving_after_we_stopped_it_shows_as_drift(): void
    {
        $customer = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'primary_status' => 'ELIGIBLE',
        ]);

        $this->assertSame(1, $this->metrics()->statusDrift()['believed_stopped_but_live']);
    }

    public function test_an_active_campaign_never_reconciled_is_reported_separately(): void
    {
        $customer = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => null,
        ]);

        $drift = $this->metrics()->statusDrift();

        // Unknown is not the same claim as wrong, and must not be counted as it.
        $this->assertSame(1, $drift['unchecked']);
        $this->assertSame(0, $drift['believed_live_but_not']);
    }

    public function test_an_agreeing_campaign_is_not_drift(): void
    {
        $customer = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => 'ELIGIBLE',
        ]);

        $this->assertSame(
            ['believed_live_but_not' => 0, 'believed_stopped_but_live' => 0, 'unchecked' => 0],
            $this->metrics()->statusDrift(),
        );
    }

    // ── Time to value ────────────────────────────────────────────────────────

    public function test_time_to_value_reports_a_median_and_its_sample_size(): void
    {
        // 2, 4 and 6 days: the median is 4, the mean would also be 4 — so add a
        // far outlier to prove it is genuinely a median.
        foreach ([2, 4, 6, 300] as $days) {
            $customer = $this->ownedCustomer(['created_at' => now()->subDays(10)]);
            Campaign::factory()->create([
                'customer_id' => $customer->id,
                'created_at' => now()->subDays(10)->addDays($days),
            ]);
        }

        $toCampaign = collect($this->metrics()->timeToValue())->firstWhere('key', 'to_campaign');

        $this->assertSame(4, $toCampaign['sample']);
        // Median of 2,4,6,300 is 5. A mean would be 78.
        $this->assertSame(5.0, $toCampaign['median_days']);
    }

    public function test_time_to_value_is_null_rather_than_zero_when_nobody_reached_the_step(): void
    {
        $this->ownedCustomer();

        $toCampaign = collect($this->metrics()->timeToValue())->firstWhere('key', 'to_campaign');

        // "Nobody got here" and "everybody got here instantly" are different
        // statements; rendering the first as 0d would be a lie.
        $this->assertNull($toCampaign['median_days']);
        $this->assertSame(0, $toCampaign['sample']);
    }
}
