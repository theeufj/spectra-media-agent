<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\Analytics\AdoptionMetrics;
use App\Services\Analytics\UsagePeriod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsageDashboardCachingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();

        DB::table('campaigns')->delete();
        DB::table('customer_user')->delete();
        DB::table('customers')->delete();
        DB::table('users')->delete();
    }

    /**
     * An owned account. The funnel is anchored on users, so a customer nobody
     * belongs to is invisible to every step.
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

    public function test_a_second_read_inside_the_ttl_does_not_requery(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->ownedCustomer();
        }

        $metrics = new AdoptionMetrics(UsagePeriod::fromRequest('30'));
        $metrics->funnel();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $metrics->funnel();

        $this->assertSame(0, $queries, 'the funnel was recomputed despite being cached');
    }

    public function test_flushing_the_cache_recomputes(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->ownedCustomer();
        }

        $metrics = new AdoptionMetrics(UsagePeriod::fromRequest('30'));
        $first = $metrics->funnel();

        foreach (range(1, 2) as $ignored) {
            $this->ownedCustomer();
        }
        Cache::flush();

        $second = (new AdoptionMetrics(UsagePeriod::fromRequest('30')))->funnel();

        $this->assertSame(3, $first[1]['count']);
        $this->assertSame(5, $second[1]['count']);
    }

    public function test_the_cache_key_includes_the_period(): void
    {
        // The silent bug this guards: switching the period selector and being
        // shown the previous period's numbers under the new label. Nothing on
        // screen looks wrong, so only a test catches it.
        $this->ownedCustomer(['created_at' => now()->subDays(50)]);
        $this->ownedCustomer(['created_at' => now()->subDays(2)]);

        $ninety = (new AdoptionMetrics(UsagePeriod::fromRequest('90')))->funnel();
        $seven = (new AdoptionMetrics(UsagePeriod::fromRequest('7')))->funnel();

        $this->assertSame(2, $ninety[1]['count'], '90-day window should see both signups');
        $this->assertSame(1, $seven[1]['count'], '7-day window returned the 90-day numbers');
    }

    public function test_each_metric_caches_under_its_own_key(): void
    {
        $customer = $this->ownedCustomer();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => 'MISCONFIGURED',
        ]);

        $metrics = new AdoptionMetrics(UsagePeriod::fromRequest('30'));

        // A shared key would make the second call return the first's payload —
        // so read drift, read the funnel in between, then read drift again.
        $this->assertSame(1, $metrics->statusDrift()['believed_live_but_not']);
        $this->assertCount(6, $metrics->funnel());
        $this->assertSame(1, $metrics->statusDrift()['believed_live_but_not']);
    }
}
