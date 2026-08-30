<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Deleting a user must not strand their customers.
 *
 * `customer_user.user_id` is ON DELETE CASCADE, so a deleted user takes their
 * pivot rows with them — and nothing cascades on to the customer. The customer
 * survives with no owner: unreachable by every user, invisible in every UI, and
 * still holding whatever campaigns and ad-spend credit it had. Six of
 * twenty-two customers in production got there this way. The admin delete path
 * made it worse by calling customers()->detach() first, so even a handler that
 * read the pivot would have seen nothing.
 */
class UserDeletionTest extends TestCase
{
    use DatabaseTransactions;

    // No Customer::unsetEventDispatcher() here, tempting as it is to silence
    // CustomerObserver: that static lives on Eloquent's base Model and is shared
    // by every model, so switching it off takes UserObserver — the thing under
    // test — with it. The base TestCase's Queue::fake() already stops the
    // observer's jobs from running.

    private function customerOwnedBy(User $user): Customer
    {
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return $customer;
    }

    public function test_a_customer_with_nothing_of_value_goes_with_its_last_owner(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerOwnedBy($user);

        $user->delete();

        // Soft-deleted, not destroyed: Customer uses SoftDeletes, so the row
        // leaves every query and every screen while the record survives for
        // anyone who later needs to know it existed.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_with_another_owner_is_untouched(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();
        $customer = $this->customerOwnedBy($user);
        $colleague->customers()->attach($customer->id, ['role' => 'owner']);

        $user->delete();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertTrue($customer->fresh()->users()->whereKey($colleague->id)->exists());
    }

    public function test_a_customer_with_a_deployed_campaign_is_kept_and_admins_are_told(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $customer = $this->customerOwnedBy($user);
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/123/campaigns/456',
        ]);

        $user->delete();

        // Deleting it would take live ads down with it; that is a human's call.
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_holding_credit_is_kept(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $customer = $this->customerOwnedBy($user);
        AdSpendCredit::factory()->create([
            'customer_id' => $customer->id,
            'current_balance' => 250.00,
        ]);

        $user->delete();

        // Real money. Removing the row would lose the trail to it.
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_no_deletion_path_leaves_an_orphan(): void
    {
        $user = User::factory()->create();
        $this->customerOwnedBy($user);

        $before = $this->orphanCount();

        $user->delete();

        $this->assertSame($before, $this->orphanCount(), 'a customer was left with no owner');
    }

    private function orphanCount(): int
    {
        return Customer::query()->whereDoesntHave('users')->count();
    }
}
