<?php

namespace Tests\Feature\Analytics;

use App\Enums\ProductFeature;
use App\Models\Customer;
use App\Models\User;
use App\Services\FeatureUsage\FeatureRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeatureUsageRecordingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        DB::table('feature_usage_daily')->delete();
    }

    private function rows(): \Illuminate\Support\Collection
    {
        return DB::table('feature_usage_daily')->get();
    }

    public function test_a_first_use_creates_the_row_with_a_count_of_one(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('analytics', $rows->first()->feature);
        $this->assertSame('viewed', $rows->first()->action);
        $this->assertSame(1, (int) $rows->first()->count);
        $this->assertNotNull($rows->first()->last_at);
    }

    public function test_repeat_use_on_the_same_day_increments_rather_than_appending(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        // The whole reason this is a counter table and not an event log: a user
        // refreshing a page 400 times must not write 400 rows.
        foreach (range(1, 5) as $ignored) {
            FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);
        }

        $rows = $this->rows();
        $this->assertCount(1, $rows, 'repeat use appended instead of incrementing');
        $this->assertSame(5, (int) $rows->first()->count);
    }

    public function test_a_null_customer_still_deduplicates(): void
    {
        $user = User::factory()->create();

        // Postgres treats NULLs as distinct in a unique index by default, so
        // without NULLS NOT DISTINCT this case silently appends forever — and
        // it is the case that matters most, because a user with no account yet
        // is exactly whose activation the dashboard is measuring.
        foreach (range(1, 3) as $ignored) {
            FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', null, $user->id);
        }

        $rows = $this->rows();
        $this->assertCount(1, $rows, 'null customer_id did not deduplicate — check NULLS NOT DISTINCT');
        $this->assertSame(3, (int) $rows->first()->count);
        $this->assertNull($rows->first()->customer_id);
    }

    public function test_a_different_day_starts_a_new_row(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        $this->travelTo(now()->subDay());
        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);

        $this->travelBack();
        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);

        $this->assertCount(2, $this->rows());
    }

    public function test_different_actions_on_one_feature_are_counted_separately(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        FeatureRecorder::record(ProductFeature::Reports, 'viewed', $customer->id, $user->id);
        FeatureRecorder::record(ProductFeature::Reports, 'downloaded', $customer->id, $user->id);

        // "Looked at the reports page" and "actually downloaded one" are very
        // different signals and must not collapse into each other.
        $this->assertCount(2, $this->rows());
    }

    public function test_the_kill_switch_stops_all_writes(): void
    {
        config(['feature_usage.enabled' => false]);

        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', 1, 1);

        $this->assertCount(0, $this->rows());
    }

    public function test_a_failure_does_not_break_the_caller_or_poison_its_transaction(): void
    {
        $customer = Customer::factory()->create();

        // A customer_id that violates the foreign key, standing in for any
        // runtime failure. Two things must hold: the caller's own work commits,
        // and the caller can keep querying afterwards. The second is the subtle
        // one — in Postgres a failed statement aborts the entire transaction, so
        // merely catching the exception would leave the request unable to run
        // another query. The savepoint inside FeatureRecorder is what saves it.
        $survived = DB::transaction(function () use ($customer) {
            DB::table('customers')->where('id', $customer->id)->update(['name' => 'still here']);

            FeatureRecorder::record(ProductFeature::Analytics, 'viewed', 999999999, null);

            return DB::table('customers')->where('id', $customer->id)->value('name');
        });

        $this->assertSame('still here', $survived, "the caller's transaction was poisoned by a failed usage write");
        $this->assertCount(0, $this->rows());
    }

    public function test_the_dashboard_records_a_view_for_the_active_account(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('dashboard'))
            ->assertSuccessful();

        $row = DB::table('feature_usage_daily')
            ->where('feature', ProductFeature::Dashboard->value)
            ->where('customer_id', $customer->id)
            ->first();

        $this->assertNotNull($row, 'visiting the dashboard recorded nothing — the only proxy for "last seen" past 30 days');
        $this->assertSame(1, (int) $row->count);
    }

    public function test_the_prunable_window_follows_config(): void
    {
        config(['feature_usage.retention_days' => 30]);

        $sql = (new \App\Models\FeatureUsageDaily)->prunable()->toSql();
        $bindings = (new \App\Models\FeatureUsageDaily)->prunable()->getBindings();

        $this->assertStringContainsString('"day" <', $sql);
        $this->assertSame(now()->subDays(30)->toDateString(), $bindings[0]);
    }
}
