<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Scopes\CustomerScope;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the tenant scope costs per query is what it costs per page.
 *
 * Thirty-one models carry BelongsToCustomer, and the scope runs on every query
 * against every one of them. canAccessAdmin() sat in *front* of the memoised
 * pivot lookup, and hasRole() is an exists() on a relation builder — it
 * re-queries even when `roles` is loaded — so a plain user paid two extra round
 * trips per scoped query rather than two per request.
 *
 * Memoising the whole answer has to keep the behaviour identical: admins
 * unscoped, queue workers unscoped, everyone else scoped to their pivot.
 */
class CustomerScopeQueryCostTest extends TestCase
{
    use DatabaseTransactions;

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

    private function userWithCustomer(): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    public function test_the_role_and_pivot_lookups_run_once_per_request_not_once_per_query(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        Campaign::factory()->count(2)->create(['customer_id' => $customer->id]);

        $this->actingAs($user);
        CustomerScope::flush();

        $queries = $this->record(function () {
            Campaign::query()->get();
            Campaign::query()->get();
            Campaign::query()->get();
            Campaign::query()->get();
            Campaign::query()->get();
        });

        // hasRole('admin') and hasRole('support'), resolved once — not twice
        // for each of the five scoped queries.
        $this->assertSame(2, $this->countReads($queries, 'role_user'));
        $this->assertSame(1, $this->countReads($queries, 'customer_user'));
    }

    public function test_a_plain_user_still_sees_only_their_own_customers(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $other = Customer::factory()->create();

        Campaign::factory()->create(['customer_id' => $customer->id, 'name' => 'Mine']);
        Campaign::factory()->create(['customer_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($user);
        CustomerScope::flush();

        $this->assertSame(['Mine'], Campaign::query()->pluck('name')->all());
    }

    public function test_an_admin_resolves_to_no_scope_rather_than_an_empty_list(): void
    {
        // The distinction matters: an empty list would become `customer_id in ()`
        // — 0 = 1 — and empty the admin console. Only null means "do not apply".
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        Campaign::factory()->create(['customer_id' => $mine->id]);
        Campaign::factory()->create(['customer_id' => $theirs->id]);

        $this->actingAs($admin);
        CustomerScope::flush();

        $this->assertNull(CustomerScope::visibleCustomerIds());
        // Twice: the memoised admin answer must still read as "no scope".
        $this->assertNull(CustomerScope::visibleCustomerIds());

        // Both tenants, neither of which the admin is attached to.
        $this->assertSame(2, Campaign::query()->whereIn('customer_id', [$mine->id, $theirs->id])->count());
    }

    public function test_the_scope_stays_inert_without_an_authenticated_user(): void
    {
        // Queue workers and scheduled commands iterate every customer by design.
        CustomerScope::flush();

        $this->assertNull(CustomerScope::visibleCustomerIds());
    }

    public function test_the_memo_does_not_leak_between_users(): void
    {
        [$first, $firstCustomer] = $this->userWithCustomer();
        [$second, $secondCustomer] = $this->userWithCustomer();

        Campaign::factory()->create(['customer_id' => $firstCustomer->id, 'name' => 'First']);
        Campaign::factory()->create(['customer_id' => $secondCustomer->id, 'name' => 'Second']);

        $this->actingAs($first);
        CustomerScope::flush();
        $this->assertSame(['First'], Campaign::query()->pluck('name')->all());

        $this->actingAs($second);
        $this->assertSame(['Second'], Campaign::query()->pluck('name')->all());
    }
}
