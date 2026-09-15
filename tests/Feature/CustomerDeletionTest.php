<?php

namespace Tests\Feature;

use App\Enums\ProductFeature;
use App\Models\Customer;
use App\Models\User;
use App\Services\FeatureUsage\FeatureRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A customer with usage history has to be deletable.
 *
 * Two deliberate decisions collided in feature_usage_daily. customer_id is
 * nullOnDelete so removing an account does not rewrite last quarter's adoption
 * figures, and the unique index is NULLS NOT DISTINCT so usage recorded before
 * an account is selected still deduplicates. Together, deleting a customer set
 * every one of their rows to NULL at once — and under NULLS NOT DISTINCT rows
 * that differed only by customer_id became duplicates of each other. Postgres
 * refused and the whole DELETE aborted.
 *
 * Any customer with more than one usage row could not be deleted at all. Found
 * while clearing test data; the same call would have failed on an erasure
 * request from a real account.
 */
class CustomerDeletionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_customer_with_several_usage_rows_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user);
        session(['active_customer_id' => $customer->id]);

        // More than one row for this customer is the whole condition: a single
        // row nulls without colliding with anything.
        FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', $customer->id, $user->id);
        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);
        FeatureRecorder::record(ProductFeature::Creatives, 'viewed', $customer->id, $user->id);

        $this->assertSame(3, DB::table('feature_usage_daily')->where('customer_key', $customer->id)->count());

        $customer->forceDelete();

        $this->assertNull(Customer::withoutGlobalScopes()->withTrashed()->find($customer->id));
    }

    public function test_the_history_outlives_the_customer(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user);
        FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', $customer->id, $user->id);
        FeatureRecorder::record(ProductFeature::Analytics, 'viewed', $customer->id, $user->id);

        $customer->forceDelete();

        /*
           The reason customer_id detaches rather than cascading: removing an
           account should not rewrite the adoption figures it contributed to.
           customer_key keeps the attribution that customer_id has to give up.
        */
        $rows = DB::table('feature_usage_daily')->where('customer_key', $customer->id)->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows->first()->customer_id);
    }

    public function test_unattributed_usage_still_deduplicates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        /*
           The reason the index is NULLS NOT DISTINCT. A user who has not
           selected an account yet — every new signup — would otherwise insert
           a fresh row on every single request, and the write amplification is
           unbounded. That property has to survive moving the index.
        */
        FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', null, $user->id);
        FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', null, $user->id);
        FeatureRecorder::record(ProductFeature::Dashboard, 'viewed', null, $user->id);

        $rows = DB::table('feature_usage_daily')
            ->whereNull('customer_key')
            ->where('user_id', $user->id)
            ->where('feature', ProductFeature::Dashboard->value)
            ->get();

        $this->assertCount(1, $rows, 'unattributed usage must collapse into one row');
        $this->assertSame(3, (int) $rows->first()->count);
    }
}
