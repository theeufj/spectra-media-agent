<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Customers\CreateBusinessWorkspace;
use Database\Seeders\StarterPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class GoogleStarterPlanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_repeated_activation_preserves_other_plans_and_only_sells_the_new_monthly_price(): void
    {
        $old = 'price_existing_starter';
        $growth = Plan::updateOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month', 'stripe_price_id' => 'price_growth', 'is_active' => true]);
        Plan::where('slug', 'starter')->update(['stripe_price_id' => $old]);
        $before = $growth->fresh()->getAttributes();
        $this->seed(StarterPlanSeeder::class);
        $this->seed(StarterPlanSeeder::class);
        $starter = Plan::active()->where('slug', 'starter')->firstOrFail();
        $this->assertSame($before, $growth->fresh()->getAttributes());
        $this->assertSame(9900, $starter->price_cents);
        $this->assertSame('month', $starter->billing_interval);
        $this->assertSame(config('services.stripe.starter_price_id'), $starter->stripe_price_id);
        $this->assertEquals(['image_generations' => 12, 'video_generations' => 2, 'refinements' => 6,
            'max_refinements_per_item' => 2, 'max_extensions_per_video' => 1], $starter->creative_limits);
        $this->assertSame($starter->id, Plan::forStripePrices([$old])->value('id'));
        $this->assertFalse(Plan::active()->where('stripe_price_id', $old)->exists());
        $this->assertSame(['google'], Plan::platformsFor($starter->slug));
    }

    public function test_a_subscription_with_multiple_items_only_entitles_its_selected_business(): void
    {
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $user = User::factory()->create(['starter_platform' => 'facebook', 'subscription_status' => 'active']);
        $other = Customer::factory()->create();
        $selected = Customer::factory()->create(['starter_platform' => 'facebook']);
        $user->customers()->attach([$other->id, $selected->id]);
        $sub = DB::table('subscriptions')->insertGetId([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_starter_qa', 'stripe_status' => 'active',
            'starter_customer_id' => $selected->id,
        ]);
        foreach (['price_1Tfzd0ClfeR0n0yLuYUEUg6D', 'price_other_meter'] as $id) {
            DB::table('subscription_items')->insert(['subscription_id' => $sub, 'stripe_id' => 'si_'.$id, 'stripe_price' => $id, 'stripe_product' => 'prod_test', 'quantity' => 1]);
        }
        $this->assertSame($plan->id, $user->fresh()->resolveCurrentPlan()?->id);
        $this->assertSame(['google'], $user->fresh()->allowedPlatforms());
        $this->assertSame($plan->id, $selected->fresh()->subscribedPlan()?->id);
        $this->assertFalse($selected->allowsAdPlatform('facebook'));
        $this->assertTrue($selected->hasPaidDeploymentAccess());
        $this->assertNull($other->fresh()->subscribedPlan());
        $this->assertFalse($other->hasPaidDeploymentAccess());
    }

    public function test_checkout_sells_the_monthly_price_and_its_signed_webhook_grants_only_the_selected_workspace(): void
    {
        $this->withoutExceptionHandling();
        $funding = $this->createMock(\App\Services\Billing\PlatformFunding::class);
        $funding->expects($this->once())->method('forPlan')->willReturn(['ready' => true, 'channels' => ['google' => ['ready' => true]]]);
        $this->app->instance(\App\Services\Billing\PlatformFunding::class, $funding);
        config(['cashier.secret' => 'sk_test_starter_fixture', 'services.stripe.secret' => 'sk_test_starter_fixture', 'cashier.webhook.secret' => 'whsec_starter_fixture',
            'services.stripe.webhook.secret' => 'whsec_starter_fixture']);
        Setting::set('deployment_enabled', true);
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $user = User::factory()->create(['stripe_id' => 'cus_starter_fixture', 'starter_platform' => 'facebook']);
        $other = Customer::factory()->create();
        $business = Customer::factory()->create(['tenant_key' => 'sitetospend', 'service_type' => 'setup_only']);
        $user->customers()->attach([$other->id, $business->id]);
        $user->roles()->attach(\App\Models\Role::unguarded(fn () => \App\Models\Role::firstOrCreate(['name' => 'user']))->id);
        $mock = new class implements ClientInterface
        {
            public array $session = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                if ($method === 'get' && $path === '/v1/customers/cus_starter_fixture') {
                    return ['{"id":"cus_starter_fixture","object":"customer"}', 200, []];
                }
                if ($method === 'get' && in_array($path, ['/v1/checkout/sessions', '/v1/subscriptions'], true)) {
                    return ['{"object":"list","data":[],"has_more":false}', 200, []];
                }
                if ($method === 'post' && $path === '/v1/checkout/sessions') {
                    $this->session = $params;

                    return [json_encode(['id' => 'cs_test_starter', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/starter', 'status' => 'open', 'customer' => $params['customer'], 'client_reference_id' => $params['client_reference_id'], 'mode' => 'subscription'], JSON_THROW_ON_ERROR), 200, []];
                }
                throw new \RuntimeException("Unexpected Stripe path: {$path}");
            }
        };
        $old = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($mock);
        try {
            $this->actingAs($user)->withSession(['active_customer_id' => $business->id])
                ->post(route('subscription.checkout'), ['price_id' => $starter->stripe_price_id])
                ->assertRedirect('https://checkout.stripe.test/starter');
        } finally {
            ApiRequestor::setHttpClient($old);
        }
        $this->assertSame('managed', $business->fresh()->service_type);
        $this->assertSame('subscription', $mock->session['mode']);
        $this->assertSame($starter->stripe_price_id, $mock->session['line_items'][0]['price']);
        $metadata = $mock->session['subscription_data']['metadata'];
        $this->assertSame((string) $business->id, $metadata['starter_customer_id']);
        $payload = json_encode(['id' => 'evt_starter_fixture', 'type' => 'customer.subscription.created', 'livemode' => false,
            'data' => ['object' => ['id' => 'sub_starter_checkout', 'customer' => $user->stripe_id, 'status' => 'active',
                'metadata' => $metadata, 'items' => ['data' => [['id' => 'si_starter', 'quantity' => 1,
                    'price' => ['id' => $starter->stripe_price_id, 'product' => 'prod_starter', 'type' => 'recurring']]]]]]], JSON_THROW_ON_ERROR);
        $time = time();
        $sig = hash_hmac('sha256', $time.'.'.$payload, 'whsec_starter_fixture');
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$sig}"], $payload)->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_starter_checkout', 'starter_customer_id' => $business->id]);
        $this->assertSame('starter', $business->fresh()->resolvePlan()->slug);
        $this->assertNull($other->fresh()->subscribedPlan());
    }

    public function test_quick_start_reuses_its_workspace_and_refuses_a_second_starter_workspace(): void
    {
        $user = User::factory()->create(['assigned_plan_id' => Plan::where('slug', 'starter')->value('id')]);
        $service = app(CreateBusinessWorkspace::class);
        $data = ['name' => 'First business', 'website' => 'https://first.example'];
        $first = $service->create($user, $data, quickStart: true);
        $this->assertSame($first->id, $service->create($user, $data, quickStart: true)->id);
        try {
            $service->create($user, ['name' => 'Second', 'website' => 'https://second.example'], quickStart: true);
            $this->fail('Quick start exceeded the Starter workspace entitlement.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->errors());
        }
        $this->assertSame(1, $user->customers()->count());
    }
}
