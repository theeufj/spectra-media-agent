<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPrechargeJourneyTest extends TestCase
{
    use DatabaseTransactions;

    public static function plans(): array
    {
        return [['starter', ['google']], ['growth', ['google', 'facebook']]];
    }

    #[DataProvider('plans')]
    public function test_an_owner_can_select_a_plan_and_return_to_durable_channel_reasons_without_starting_a_payment(string $slug, array $channels): void
    {
        $plan = Plan::updateOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'price_cents' => $slug === 'growth' ? 24900 : 9900,
            'stripe_price_id' => 'price_qa_'.$slug, 'billing_interval' => 'month', 'is_active' => true]);
        Setting::set('deployment_enabled', true);
        $user = User::factory()->create(['stripe_id' => null]);
        $customer = Customer::factory()->create(['website' => 'https://example.org', 'google_ads_customer_id' => null,
            'facebook_ads_account_id' => null, 'facebook_page_id' => null]);
        $user->customers()->attach($customer, ['role' => 'owner']);
        $this->assertFalse($user->hasRole('admin'));
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);
        $this->get(route('subscription.pricing'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Subscription/Pricing'));
        $this->post(route('subscription.checkout'), ['price_id' => $plan->stripe_price_id, 'platforms' => $channels])
            ->assertRedirect(route('subscription.pricing'));
        $this->get(route('subscription.pricing'))->assertOk()->assertInertia(function (AssertableInertia $page) use ($channels) {
            $page->where('prequalification.ready', false);
            foreach ($channels as $channel) {
                $page->where("prequalification.channels.{$channel}.ready", false)->has("prequalification.channels.{$channel}.message");
            }
        });
        $this->assertNull($user->fresh()->stripe_id);
        $this->assertFalse(DB::table('subscriptions')->where('user_id', $user->id)->exists());
        $this->assertFalse(DB::table('ad_spend_credits')->where('customer_id', $customer->id)->exists());
    }

    public function test_starter_refuses_meta_before_funding_or_checkout(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer, ['role' => 'owner']);
        $plan = Plan::updateOrCreate(['slug' => 'starter'], ['name' => 'Starter', 'price_cents' => 9900,
            'stripe_price_id' => 'price_qa_starter', 'billing_interval' => 'month', 'is_active' => true]);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->from(route('subscription.pricing'))->post(route('subscription.checkout'), ['price_id' => $plan->stripe_price_id, 'platforms' => ['facebook']])
            ->assertRedirect(route('subscription.pricing'))->assertSessionHas('flash.type', 'error');
        $this->assertFalse(DB::table('channel_checkout_readiness')->where('customer_id', $customer->id)->exists());
        $this->assertNull($user->fresh()->stripe_id);
    }
}
