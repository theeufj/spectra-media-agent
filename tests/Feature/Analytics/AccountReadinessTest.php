<?php

namespace Tests\Feature\Analytics;

use App\Jobs\MonitorAccountReadiness;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Analytics\AccountReadiness;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();

        // The report is platform-wide, so anything left behind would be counted.
        DB::table('strategies')->delete();
        DB::table('campaigns')->delete();
        DB::table('ad_spend_credits')->delete();
        DB::table('customer_user')->delete();
        DB::table('customers')->delete();
        DB::table('agent_runs')->delete();
    }

    /** A customer with the prerequisites satisfied, so tests isolate one blocker at a time. */
    private function readyish(array $attributes = []): Customer
    {
        // google_ads_customer_id is unique, so it cannot be a fixed literal in
        // a helper called many times per test.
        static $seq = 0;
        $seq++;

        $customer = Customer::factory()->create([
            'google_ads_customer_id' => sprintf('100-000-%04d', $seq),
            'google_ads_link_status' => 'active',
            'conversion_tracking_verified_at' => now(),
            'gtm_installed' => true,
            ...$attributes,
        ]);

        DB::table('ad_spend_credits')->insert([
            'customer_id' => $customer->id,
            'initial_credit_amount' => 500, 'current_balance' => 500,
            'currency' => 'USD', 'status' => 'active', 'payment_status' => 'current',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $customer;
    }

    private function blockerKeys(int $customerId): array
    {
        $report = (new AccountReadiness)->report();
        $account = collect($report['accounts'])->firstWhere('id', $customerId);

        return array_column($account['blockers'], 'key');
    }

    // ── Blocked: cannot advertise at all ─────────────────────────────────────

    public function test_an_account_with_no_ad_spend_credit_is_blocked(): void
    {
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '123', 'google_ads_link_status' => 'active',
            'conversion_tracking_verified_at' => now(), 'gtm_installed' => true,
        ]);
        Campaign::factory()->create(['customer_id' => $customer->id, 'primary_status' => 'ELIGIBLE']);

        // The audit found 14 of 15 real accounts in exactly this state: linked,
        // provisioned, and unable to be billed for a single click.
        $this->assertContains('no_ad_spend_credit', $this->blockerKeys($customer->id));
        $this->assertSame(
            AccountReadiness::SEVERITY_BLOCKED,
            collect((new AccountReadiness)->report()['accounts'])->firstWhere('id', $customer->id)['severity'],
        );
    }

    public function test_a_pending_google_link_reports_how_long_it_has_waited(): void
    {
        $customer = $this->readyish([
            'google_ads_link_status' => 'pending',
            'google_ads_link_requested_at' => now()->subDays(12),
        ]);

        $account = collect((new AccountReadiness)->report()['accounts'])->firstWhere('id', $customer->id);
        $blocker = collect($account['blockers'])->firstWhere('key', 'google_link_pending');

        // The customer accepts the invitation in their own Google account, so
        // age is the whole story: a day is normal, twelve means it was missed.
        $this->assertNotNull($blocker);
        $this->assertStringContainsString('12d', $blocker['detail']);
    }

    public function test_an_account_with_no_platform_at_all_is_blocked(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => null]);

        $this->assertContains('no_platform_account', $this->blockerKeys($customer->id));
    }

    public function test_a_depleted_credit_account_is_blocked(): void
    {
        $customer = $this->readyish();
        DB::table('ad_spend_credits')->where('customer_id', $customer->id)
            ->update(['status' => 'depleted', 'payment_status' => 'failed']);

        $this->assertContains('credit_unhealthy', $this->blockerKeys($customer->id));
    }

    // ── Stalled: work started, never finished ────────────────────────────────

    public function test_an_account_that_never_created_a_campaign_is_stalled(): void
    {
        $customer = $this->readyish();

        $this->assertContains('no_campaigns', $this->blockerKeys($customer->id));
    }

    public function test_a_strategy_that_started_and_never_finished_is_reported(): void
    {
        $customer = $this->readyish();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'strategy_generation_started_at' => now()->subDay(),
            'strategy_generation_completed_at' => null,
            'strategy_generation_error' => null,
        ]);

        // No completion and no error: nothing retries it and nothing reports
        // it, so it is invisible without this check. Production had two.
        $this->assertContains('strategy_stuck', $this->blockerKeys($customer->id));
    }

    public function test_a_strategy_still_running_within_the_grace_window_is_not_flagged(): void
    {
        $customer = $this->readyish();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'strategy_generation_started_at' => now()->subMinutes(5),
            'strategy_generation_completed_at' => null,
        ]);

        $this->assertNotContains('strategy_stuck', $this->blockerKeys($customer->id));
    }

    public function test_a_failed_deployment_is_reported(): void
    {
        $customer = $this->readyish();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'deployment_error' => 'Deployment did not complete',
        ]);

        $this->assertContains('deploy_failed', $this->blockerKeys($customer->id));
    }

    public function test_campaigns_created_but_never_deployed_are_reported(): void
    {
        $customer = $this->readyish();
        Campaign::factory()->count(3)->create(['customer_id' => $customer->id]);

        $this->assertContains('never_deployed', $this->blockerKeys($customer->id));
    }

    public function test_an_active_campaign_never_checked_against_the_platform_is_reported(): void
    {
        $customer = $this->readyish();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => null,
        ]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'deployment_status' => 'deployed']);

        // Billing on a status nothing has verified — the BILL-8 shape.
        $this->assertContains('never_reconciled', $this->blockerKeys($customer->id));
    }

    // ── Ready ────────────────────────────────────────────────────────────────

    public function test_a_fully_working_account_has_nothing_outstanding(): void
    {
        $customer = $this->readyish();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => 'ELIGIBLE',
        ]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'deployment_status' => 'deployed']);

        $report = (new AccountReadiness)->report();
        $account = collect($report['accounts'])->firstWhere('id', $customer->id);

        $this->assertSame([], $account['blockers']);
        $this->assertSame('ready', $account['severity']);
        $this->assertSame(1, $report['summary']['serving']);
    }

    public function test_sandbox_and_deleted_accounts_are_excluded(): void
    {
        Customer::factory()->create(['is_sandbox' => true]);
        Customer::factory()->create()->delete();

        $this->assertSame(0, (new AccountReadiness)->report()['summary']['total']);
    }

    // ── Shape and cost ───────────────────────────────────────────────────────

    public function test_blockers_are_counted_across_accounts(): void
    {
        foreach (range(1, 3) as $ignored) {
            Customer::factory()->create([
                'google_ads_customer_id' => '200-000-'.$ignored,
                'google_ads_link_status' => 'active',
                'conversion_tracking_verified_at' => now(), 'gtm_installed' => true,
            ]);
        }

        $counts = collect((new AccountReadiness)->report()['blocker_counts']);

        // The "fix this once, unblock three accounts" view.
        $this->assertSame(3, $counts->firstWhere('key', 'no_ad_spend_credit')['accounts']);
        $this->assertSame(AccountReadiness::SEVERITY_BLOCKED, $counts->first()['severity']);
    }

    public function test_the_report_does_not_query_per_account(): void
    {
        foreach (range(1, 20) as $ignored) {
            $c = $this->readyish();
            Campaign::factory()->create(['customer_id' => $c->id]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        (new AccountReadiness)->report();

        // Four aggregate queries regardless of account count. Fifteen accounts
        // would forgive an N+1; the point of measuring adoption is that the
        // number grows.
        $this->assertLessThanOrEqual(6, $queries, "the report scales per account ({$queries} queries for 20)");
    }

    public function test_the_nightly_job_records_a_run_trace(): void
    {
        Customer::factory()->create(['google_ads_customer_id' => null]);

        (new MonitorAccountReadiness)->handle(new AccountReadiness);

        $run = AgentRun::where('job', 'MonitorAccountReadiness')->latest('id')->firstOrFail();

        // Blocked counts as an error, not a warning: the customer is paying and
        // structurally cannot advertise.
        $this->assertSame(1, $run->errors);
        $this->assertSame(1, $run->details['summary']['blocked']);
        $this->assertNotEmpty($run->details['blocked_accounts']);
    }
}
