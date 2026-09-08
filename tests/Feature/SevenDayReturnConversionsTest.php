<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteConversion;
use App\Jobs\Scheduled\RecordSevenDayReturnConversions;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The seven-day return conversion sweep must actually run.
 *
 * `users` is a belongsToMany through customer_user, so a whereHas() on it
 * joins both tables — and both carry created_at. Filtering on the unqualified
 * column made Postgres reject the entire query as ambiguous, and this job threw
 * every night at 10:00 without uploading a single return conversion to Google.
 *
 * It went unnoticed for as long as it did because report() was not reaching the
 * admin dashboard; the failure appeared within a day of fixing that. These
 * assertions execute the real query, which is the only thing that catches an
 * ambiguity — it is invisible to static analysis and to any test that mocks.
 */
class SevenDayReturnConversionsTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithUser(?string $gclid, int $signedUpDaysAgo): Customer
    {
        $customer = Customer::factory()->create();

        $user = User::factory()->create([
            'gclid' => $gclid,
            'created_at' => now()->subDays($signedUpDaysAgo)->setTime(11, 0),
        ]);

        $customer->users()->attach($user->id, ['role' => 'owner']);

        return $customer;
    }

    public function test_a_customer_who_arrived_from_a_google_click_seven_days_ago_is_swept(): void
    {
        Queue::fake();

        $customer = $this->customerWithUser('Cj0KCQiA-demo-click-id', 7);

        (new RecordSevenDayReturnConversions)->handle();

        Queue::assertPushed(
            RecordSiteConversion::class,
            fn ($job) => (fn () => $this->customer->id)->call($job) === $customer->id
        );
    }

    public function test_a_user_who_arrived_without_a_click_id_is_not_swept(): void
    {
        Queue::fake();

        $this->customerWithUser(null, 7);

        (new RecordSevenDayReturnConversions)->handle();

        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_a_user_who_signed_up_on_a_different_day_is_not_swept(): void
    {
        Queue::fake();

        $this->customerWithUser('Cj0KCQiA-demo-click-id', 3);

        (new RecordSevenDayReturnConversions)->handle();

        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_the_sweep_filters_on_the_users_signup_date_not_the_pivots(): void
    {
        Queue::fake();

        // The row that makes the ambiguity a correctness question and not just a
        // syntax one: the user signed up seven days ago, but was attached to this
        // customer today. Only users.created_at gives the intended answer.
        $customer = Customer::factory()->create();
        $user = User::factory()->create([
            'gclid' => 'Cj0KCQiA-demo-click-id',
            'created_at' => now()->subDays(7)->setTime(11, 0),
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner', 'created_at' => now()]);

        (new RecordSevenDayReturnConversions)->handle();

        Queue::assertPushed(RecordSiteConversion::class);
    }
}
