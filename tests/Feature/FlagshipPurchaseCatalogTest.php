<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Services\SetupFeeService;
use Database\Seeders\StarterPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FlagshipPurchaseCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_flagship_signup_cannot_choose_new_setup_or_buy_a_retired_plan_on_another_host(): void
    {
        $this->seed(StarterPlanSeeder::class);
        $growth = $this->growthPlan();
        $user = User::factory()->create(['tenant_key' => 'sitetospend']);
        $host = 'https://realpropertyads.com';

        $this->actingAs($user)->get($host.'/quick-start')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('QuickStart')->where('setupAvailable', false));
        $this->post($host.'/quick-start', ['website_url' => 'https://example.org', 'service_type' => 'setup_only'])
            ->assertSessionHasErrors('service_type');
        $this->assertFalse($user->customers()->exists());

        $this->get($host.'/subscription/pricing')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Subscription/Pricing')->has('plans', 1)->where('plans.0.slug', 'starter')
            ->where('plans.0.price_cents', 9900)->where('setupFee', null));
        $this->post($host.'/subscription/checkout', ['price_id' => $growth->stripe_price_id])
            ->assertRedirect($host.'/subscription/pricing');
        $this->assertNull($user->fresh()->stripe_id);
    }

    public function test_flagship_workspace_restricts_a_real_property_user_and_keeps_paid_setup_available(): void
    {
        $user = User::factory()->create(['tenant_key' => 'realpropertyads']);
        $customer = Customer::factory()->create(['tenant_key' => 'sitetospend', 'service_type' => 'setup_only']);
        $user->customers()->attach($customer, ['role' => 'owner']);
        $host = 'https://realpropertyads.com';

        $service = $this->createMock(SetupFeeService::class);
        $service->expects($this->never())->method('checkoutUrl');
        $this->app->instance(SetupFeeService::class, $service);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);
        $this->post($host.'/setup-fee/checkout')->assertRedirect($host.'/subscription/pricing');
        $this->assertNull($customer->fresh()->setup_fee_paid_at);

        $customer->update(['setup_fee_paid_at' => now()]);
        $service->expects($this->once())->method('confirm')->with(
            $this->callback(fn (User $buyer) => $buyer->is($user)),
            $this->callback(fn (Customer $workspace) => $workspace->is($customer)),
            'cs_previous_setup',
        )->willReturn(true);
        $this->get($host.'/setup-fee/success?session_id=cs_previous_setup')->assertRedirect($host.'/dashboard');
        $this->get($host.'/subscription/pricing')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setupFee.intent', true)->where('setupFee.paid', true));
    }

    public function test_real_property_ads_keeps_the_existing_purchase_catalog(): void
    {
        $growth = $this->growthPlan();
        $user = User::factory()->create(['tenant_key' => 'realpropertyads']);
        $host = 'https://realpropertyads.com';

        $this->actingAs($user)->get($host.'/subscription/pricing')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('singlePlan', false)->has('setupFee')->where('plans', fn ($plans) => collect($plans)->contains('id', $growth->id)));
        $this->get($host.'/quick-start')->assertInertia(fn (AssertableInertia $page) => $page->where('setupAvailable', true));
    }

    public function test_a_flagship_legacy_subscriber_keeps_their_plan_and_billing_portal(): void
    {
        $growth = $this->growthPlan();
        $user = User::factory()->create(['tenant_key' => 'sitetospend']);
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_legacy_catalog', 'stripe_status' => 'active', 'stripe_price' => $growth->stripe_price_id]);

        $this->actingAs($user)->get(route('subscription.pricing'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.subscription_plan', 'Growth')->where('manageSubscription', true));
        $this->post(route('subscription.checkout'), ['price_id' => $growth->stripe_price_id])->assertRedirect(route('subscription.portal'));
    }

    private function growthPlan(): Plan
    {
        return Plan::updateOrCreate(['slug' => 'growth'], [
            'name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month', 'stripe_price_id' => 'price_catalog_growth', 'is_active' => true,
        ]);
    }
}
