<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * There is no free tier. The 'free' plan row exists only as the internal
 * default-limits profile plan-less users resolve by slug — it must never
 * render as a purchasable tier, and it must never let anyone deploy.
 */
class FreeTierTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_seeded_free_plan_is_not_a_tier(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $free = Plan::where('slug', 'free')->firstOrFail();

        $this->assertFalse($free->is_active);
        $this->assertFalse(Plan::active()->where('slug', 'free')->exists());
    }

    public function test_the_pricing_page_never_lists_the_free_plan(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $user = User::factory()->create();

        $names = collect(
            $this->actingAs($user)->get(route('subscription.pricing'))
                ->viewData('page')['props']['plans']
        )->pluck('name');

        $this->assertFalse($names->contains('Free'));
        $this->assertTrue($names->contains('Starter'));
    }

    public function test_the_free_plan_deploys_nothing(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $free = Plan::where('slug', 'free')->firstOrFail();

        $user = User::factory()->create(['assigned_plan_id' => $free->id]);
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $this->assertFalse($user->hasSubscriptionAccess($customer));

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id])
            ->assertRedirect(route('subscription.pricing', absolute: false));
    }

    public function test_plan_less_users_still_resolve_the_default_limits(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $user = User::factory()->create(['assigned_plan_id' => null]);

        // The slug lookup ignores is_active on purpose — deactivating the
        // tier must not strip plan-less users of their default limits.
        $this->assertNotNull($user->resolveCurrentPlan());
        $this->assertSame('free', $user->resolveCurrentPlan()->slug);
    }
}
