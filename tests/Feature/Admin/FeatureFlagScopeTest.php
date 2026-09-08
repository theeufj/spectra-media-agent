<?php

namespace Tests\Feature\Admin;

use App\Features\AutoHealing;
use App\Features\PerUserGoogleToken;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * The admin feature-flag page evaluated every flag against every user.
 *
 * Pennant hands resolve() whatever Feature::for() was given, so asking
 * AutoHealing — typed for Customer — about a User is a TypeError, and the page
 * 500'd on its first row. Had it rendered, its per-user toggles would have
 * written values against a scope nothing reads back: SelfHealingAgent,
 * PauseWastefulAdGroups and RecommendationApplier all ask
 * Feature::for($customer).
 */
class FeatureFlagScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_the_page_renders_with_a_customer_scoped_flag_defined(): void
    {
        Customer::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.feature-flags.index'))
            ->assertOk();
    }

    public function test_flags_are_listed_against_the_scope_their_resolver_declares(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.feature-flags.index'));

        $userFeatures = array_column($response->viewData('page')['props']['features'], 'class');
        $customerFeatures = array_column($response->viewData('page')['props']['customerFeatures'], 'class');

        $this->assertContains(PerUserGoogleToken::class, $userFeatures);
        $this->assertNotContains(AutoHealing::class, $userFeatures);

        $this->assertContains(AutoHealing::class, $customerFeatures);
        $this->assertNotContains(PerUserGoogleToken::class, $customerFeatures);
    }

    public function test_toggling_a_customer_scoped_flag_against_a_user_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $target = User::factory()->create();
        $target->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($this->admin())
            ->post(route('admin.feature-flags.toggle', 'AutoHealing'), [
                'user_id' => $target->id,
                'active' => false,
            ])
            ->assertSessionHas('flash.type', 'error');

        // The value the agent actually reads must be untouched.
        $this->assertTrue(Feature::for($customer)->active(AutoHealing::class));
    }

    public function test_toggling_a_customer_scoped_flag_against_a_customer_is_what_the_agent_reads(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.feature-flags.toggle', 'AutoHealing'), [
                'customer_id' => $customer->id,
                'active' => false,
            ])
            ->assertSessionHas('flash.type', 'success');

        Feature::flushCache();

        $this->assertFalse(Feature::for($customer)->active(AutoHealing::class));
    }

    public function test_toggling_a_user_scoped_flag_against_a_customer_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.feature-flags.toggle', 'PerUserGoogleToken'), [
                'customer_id' => $customer->id,
                'active' => true,
            ])
            ->assertSessionHas('flash.type', 'error');
    }

    public function test_a_user_scoped_flag_still_toggles_per_user(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.feature-flags.toggle', 'PerUserGoogleToken'), [
                'user_id' => $target->id,
                'active' => true,
            ])
            ->assertSessionHas('flash.type', 'success');

        Feature::flushCache();

        $this->assertTrue(Feature::for($target)->active(PerUserGoogleToken::class));
    }
}
