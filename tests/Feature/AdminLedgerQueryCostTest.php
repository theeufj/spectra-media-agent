<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin credit ledger read the whole account and ran a platform SUM per
 * debit row — and for adjustment rows the date filter fell away, so each of
 * those summed the customer's all-time spend again. A year-old account meant
 * ~365 rows and well over a thousand aggregates for one page.
 *
 * Both shapes are fixed per page: deductions want the day they billed for,
 * adjustments all want the same account-wide total. Each is now fetched once.
 */
class AdminLedgerQueryCostTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $admin;
    }

    /**
     * A customer billed daily for $deductions days, $10 of Google and $4 of
     * Facebook spend on each of those days.
     *
     * @return array{0: Customer, 1: AdSpendCredit}
     */
    private function billedCustomer(int $deductions): array
    {
        $customer = Customer::factory()->create();
        $credit = AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        for ($day = 1; $day <= $deductions; $day++) {
            $billedFor = now()->subDays($day + 1)->startOfDay();

            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => $billedFor->toDateString(),
                'impressions' => 100, 'clicks' => 10,
                'cost' => 10, 'conversions' => 1, 'conversion_value' => 30,
            ]);

            FacebookAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'facebook_campaign_id' => 'fb_'.$campaign->id,
                'date' => $billedFor->toDateString(),
                'impressions' => 50, 'clicks' => 5,
                'cost' => 4, 'conversions' => 1, 'conversion_value' => 12,
            ]);

            $tx = AdSpendTransaction::create([
                'ad_spend_credit_id' => $credit->id,
                'type' => AdSpendTransaction::TYPE_DEDUCTION,
                'amount' => -14,
                'balance_after' => 100,
                'description' => 'Daily ad spend',
            ]);

            // The breakdown is keyed off created_at minus a day.
            $tx->created_at = $billedFor->copy()->addDay();
            $tx->save();
        }

        return [$customer, $credit];
    }

    /**
     * @return list<string>
     */
    private function record(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    private function countReads(array $queries, string $table): int
    {
        return count(array_filter($queries, fn ($sql) => str_contains($sql, $table)));
    }

    public function test_the_breakdown_does_not_cost_two_aggregates_per_debit_row(): void
    {
        $admin = $this->admin();
        $reads = [];

        foreach ([1, 8] as $deductions) {
            [$customer] = $this->billedCustomer($deductions);

            $queries = $this->record(function () use ($admin, $customer) {
                $this->actingAs($admin)
                    ->get(route('admin.customers.credit-ledger', $customer))
                    ->assertOk();
            });

            $reads[$deductions] = $this->countReads($queries, 'google_ads_performance_data');
        }

        $this->assertSame(
            $reads[1],
            $reads[8],
            'the ledger must read each platform table a fixed number of times, not once per debit row'
        );
    }

    public function test_a_deduction_still_breaks_down_to_the_day_it_billed_for(): void
    {
        $admin = $this->admin();
        [$customer] = $this->billedCustomer(3);

        $response = $this->actingAs($admin)->get(route('admin.customers.credit-ledger', $customer));
        $response->assertOk();

        $transactions = collect($response->viewData('page')['props']['transactions']);

        $this->assertCount(3, $transactions);

        foreach ($transactions as $tx) {
            $breakdown = collect($tx['platform_breakdown'])->keyBy('platform');

            // One day's spend, not the account's three days of it.
            $this->assertEquals(10, $breakdown['Google Ads']['spend']);
            $this->assertEquals(4, $breakdown['Facebook Ads']['spend']);
            $this->assertEquals(0, $breakdown['Microsoft Ads']['spend']);
        }
    }

    public function test_an_adjustment_still_shows_the_account_wide_total(): void
    {
        $admin = $this->admin();
        [$customer, $credit] = $this->billedCustomer(3);

        AdSpendTransaction::create([
            'ad_spend_credit_id' => $credit->id,
            'type' => AdSpendTransaction::TYPE_ADJUSTMENT,
            'amount' => -30,
            'balance_after' => 70,
            'description' => 'Admin reconciliation',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.customers.credit-ledger', $customer));
        $response->assertOk();

        $adjustment = collect($response->viewData('page')['props']['transactions'])
            ->firstWhere('type', AdSpendTransaction::TYPE_ADJUSTMENT);

        $breakdown = collect($adjustment['platform_breakdown'])->keyBy('platform');

        // Three days at $10 and $4 — the lump-sum row is deliberately all-time.
        $this->assertEquals(30, $breakdown['Google Ads']['spend']);
        $this->assertEquals(12, $breakdown['Facebook Ads']['spend']);
    }

    public function test_the_admin_customer_list_does_not_ship_every_campaign_row(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        Campaign::factory()->count(3)->create(['customer_id' => $customer->id]);

        $response = $this->actingAs($admin)->get(route('admin.customers.index'));
        $response->assertOk();

        // Serialised the way the page props are, so the assertion reads the
        // relations that were actually loaded rather than lazy-loading one.
        $rows = json_decode(json_encode($response->viewData('page')['props']['customers']), true);
        $row = collect($rows)->firstWhere('id', $customer->id);

        $this->assertEquals(3, $row['campaigns_count']);
        $this->assertArrayNotHasKey(
            'campaigns',
            $row,
            'the table reads campaigns_count only — eager-loading the relation ships every campaign in the system'
        );
    }
}
