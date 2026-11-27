<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\PlatformFunding;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Fakes\ManagedCheckoutStripeTransport;
use Tests\TestCase;

class ManagedSubscriptionCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private ClientInterface $previous;

    private ManagedCheckoutStripeTransport $stripe;

    private string $store;

    private Plan $plan;

    private Customer $workspace;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = tempnam(sys_get_temp_dir(), 'managed_checkout_');
        $this->stripe = new ManagedCheckoutStripeTransport($this->store);
        $this->previous = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($this->stripe);
        config(['cashier.secret' => 'sk_test_managed_checkout', 'services.stripe.secret' => 'sk_test_managed_checkout']);
        $funding = $this->createMock(PlatformFunding::class);
        $funding->method('forPlan')->willReturn(['ready' => true, 'channels' => ['google' => ['ready' => true]]]);
        $this->app->instance(PlatformFunding::class, $funding);
        $this->plan = Plan::where('slug', 'starter')->firstOrFail();
        $this->workspace = Customer::factory()->create();
        $this->payer = User::factory()->create(['stripe_id' => 'cus_replay']);
        $this->workspace->users()->attach($this->payer->id, ['role' => 'owner']);
        $this->payer->roles()->attach(\App\Models\Role::unguarded(fn () => \App\Models\Role::firstOrCreate(['name' => 'user']))->id);
        $this->actingAs($this->payer)->withSession(['active_customer_id' => $this->workspace->id]);
        Setting::set('deployment_enabled', true);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previous);
        unlink($this->store);
        parent::tearDown();
    }

    private function checkout(?Plan $plan = null)
    {
        return $this->post(route('subscription.checkout'), ['price_id' => ($plan ?? $this->plan)->stripe_price_id, 'platforms' => ['google']]);
    }

    public function test_a_lost_stripe_response_and_refresh_resume_the_same_checkout_then_wait_for_a_delayed_webhook(): void
    {
        $this->stripe->loseNextCreateResponse = true;
        $this->checkout()->assertRedirect(route('subscription.pricing'));
        $this->assertDatabaseHas('managed_subscription_checkouts', ['customer_id' => $this->workspace->id, 'stripe_session_id' => null, 'status' => 'pending']);
        $id = array_key_first($this->stripe->state()['sessions']);
        $this->checkout()->assertRedirect('https://checkout.stripe.test/'.$id);
        $this->checkout()->assertRedirect('https://checkout.stripe.test/'.$id);
        $state = $this->stripe->state();
        $this->assertCount(1, $state['sessions']);
        $this->assertCount(2, $state['creates']);
        $this->assertSame($state['creates'][0], $state['creates'][1]);
        $state['sessions'][$id]['status'] = 'complete';
        $state['sessions'][$id]['subscription'] = 'sub_waiting';
        $state['subscriptions']['sub_waiting'] = ['id' => 'sub_waiting', 'object' => 'subscription', 'customer' => 'cus_replay', 'status' => 'active', 'metadata' => ['type' => 'default']];
        $this->stripe->set($state);
        $this->checkout()->assertRedirect(route('subscription.pricing'))->assertSessionHas('flash.message', 'Stripe is confirming the existing subscription. Your billing status will update after confirmation.');
        $this->assertDatabaseHas('managed_subscription_checkouts', ['stripe_session_id' => $id, 'status' => 'complete', 'closed_at' => null]);
        // Subscription ended at Stripe before the local creation webhook was processed.
        $state['subscriptions']['sub_waiting']['status'] = 'canceled';
        $this->stripe->set($state);
        $this->checkout()->assertRedirect();
        $this->assertDatabaseHas('managed_subscription_checkouts', ['stripe_session_id' => $id, 'status' => 'canceled']);
        $this->assertCount(2, $this->stripe->state()['sessions']);
    }

    public function test_cancelled_or_expired_checkout_can_be_retried_and_switching_plans_expires_only_the_previous_session(): void
    {
        $this->workspace->update(['tenant_key' => 'realpropertyads']);
        $this->checkout()->assertRedirect();
        $state = $this->stripe->state();
        $this->get($state['creates'][0]['parameters']['cancel_url'])->assertRedirect(route('subscription.pricing'));
        $this->assertSame('expired', array_values($this->stripe->state()['sessions'])[0]['status']);
        $this->checkout()->assertRedirect();
        $state = $this->stripe->state();
        $second = array_key_last($state['sessions']);
        $state['sessions'][$second]['status'] = 'expired';
        $this->stripe->set($state);
        $this->checkout()->assertRedirect();
        $growth = Plan::updateOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 24900, 'stripe_price_id' => 'price_test_growth', 'billing_interval' => 'month', 'is_active' => true]);
        $this->checkout($growth)->assertRedirect();
        $state = $this->stripe->state();
        $this->assertCount(4, $state['sessions']);
        $this->assertSame(['expired', 'expired', 'expired', 'open'], array_column($state['sessions'], 'status'));
        $this->assertSame(4, count(array_unique(array_column($state['creates'], 'key'))));
        $this->assertSame($growth->stripe_price_id, $state['creates'][3]['parameters']['line_items'][0]['price']);
        $this->assertSame(1, DB::table('managed_subscription_checkouts')->where('customer_id', $this->workspace->id)->whereNull('closed_at')->count());
    }

    public function test_ambiguous_checkout_recovered_after_stripe_key_retention_cannot_repeat_the_purchase(): void
    {
        $this->stripe->loseNextCreateResponse = true;
        $this->checkout()->assertRedirect(route('subscription.pricing'));
        $state = $this->stripe->state();
        $id = array_key_first($state['sessions']);
        $state['sessions'][$id]['status'] = 'complete';
        $state['sessions'][$id]['subscription'] = 'sub_recovered';
        $state['subscriptions']['sub_recovered'] = ['id' => 'sub_recovered', 'object' => 'subscription', 'customer' => 'cus_replay', 'status' => 'active', 'metadata' => ['type' => 'default']];
        $this->stripe->set($state);
        $this->travel(2)->days();
        $this->checkout()->assertRedirect(route('subscription.pricing'));
        $this->assertCount(1, $this->stripe->state()['creates']);
        $this->assertDatabaseHas('managed_subscription_checkouts', ['customer_id' => $this->workspace->id, 'stripe_session_id' => $id, 'status' => 'complete', 'closed_at' => null]);
    }

    public function test_an_open_checkout_created_before_the_ledger_blocks_a_second_recurring_purchase(): void
    {
        $state = $this->stripe->state();
        $state['sessions']['cs_legacy'] = ['id' => 'cs_legacy', 'object' => 'checkout.session', 'mode' => 'subscription', 'customer' => 'cus_replay',
            'status' => 'open', 'price' => $this->plan->stripe_price_id];
        $this->stripe->set($state);
        $this->checkout()->assertRedirect(route('subscription.pricing'))->assertSessionHas('flash.message', 'Stripe already has a subscription or checkout in progress. Finish that checkout or wait for billing confirmation.');
        $this->assertCount(0, $this->stripe->state()['creates']);
    }

    public function test_existing_local_plan_goes_to_billing_portal_and_a_historical_remote_purchase_blocks_a_new_session(): void
    {
        $state = $this->stripe->state();
        $state['subscriptions']['sub_legacy'] = ['id' => 'sub_legacy', 'object' => 'subscription', 'customer' => 'cus_replay', 'status' => 'active', 'metadata' => ['type' => 'default']];
        $this->stripe->set($state);
        $this->checkout()->assertRedirect(route('subscription.pricing'))->assertSessionHas('flash.message', 'Stripe already has a subscription or checkout in progress. Finish that checkout or wait for billing confirmation.');
        DB::table('subscriptions')->insert(['user_id' => $this->payer->id, 'type' => 'default', 'stripe_id' => 'sub_legacy', 'stripe_status' => 'active', 'stripe_price' => $this->plan->stripe_price_id]);
        $this->checkout()->assertRedirect(route('subscription.portal'));
        $this->assertCount(0, $this->stripe->state()['creates']);
    }
}
