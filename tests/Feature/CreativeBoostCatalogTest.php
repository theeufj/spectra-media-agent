<?php

namespace Tests\Feature;

use App\Models\CreativeBoostPurchase;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class CreativeBoostCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public static function restrictedCatalogs(): array
    {
        return [
            'flagship user on the property host' => ['sitetospend', 'realpropertyads'],
            'flagship workspace on the property host' => ['realpropertyads', 'sitetospend'],
        ];
    }

    #[DataProvider('restrictedCatalogs')]
    public function test_flagship_buyers_cannot_open_a_checkout(string $userKey, string $workspaceKey): void
    {
        $user = User::factory()->create(['tenant_key' => $userKey]);
        $workspace = Customer::factory()->create(['tenant_key' => $workspaceKey]);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $stripe = $this->createMock(ClientInterface::class);
        $stripe->expects($this->never())->method('request');
        $original = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($stripe);
        try {
            $this->actingAs($user)->withSession(['active_customer_id' => $workspace->id]);
            $this->get('https://realpropertyads.com/creative-usage')->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CreativeUsage')->where('boostConfig', null)->has('creativeUsage'));
            $this->post('https://realpropertyads.com/creative-boost/checkout')->assertNotFound();
        } finally {
            ApiRequestor::setHttpClient($original);
        }
        $this->assertNull($user->refresh()->stripe_id);
        $this->assertDatabaseMissing('creative_boost_purchases', ['user_id' => $user->id]);
    }

    public function test_property_buyer_can_still_purchase_the_boost(): void
    {
        $user = User::factory()->create(['tenant_key' => 'realpropertyads', 'stripe_id' => 'cus_property_boost']);
        $workspace = Customer::factory()->create(['tenant_key' => 'realpropertyads']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        config(['services.stripe.secret' => 'sk_test_boost']);
        $stripe = $this->createMock(ClientInterface::class);
        $stripe->expects($this->once())->method('request')->willReturn([json_encode([
            'id' => 'cs_test_property_boost', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_property_boost',
        ]), 200, []]);
        $original = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($stripe);
        try {
            $this->actingAs($user)->withSession(['active_customer_id' => $workspace->id]);
            $this->get('https://realpropertyads.com/creative-usage')->assertInertia(fn (AssertableInertia $page) => $page
                ->has('boostConfig.price_cents'));
            $this->post('https://realpropertyads.com/creative-boost/checkout')->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_property_boost');
        } finally {
            ApiRequestor::setHttpClient($original);
        }
        $this->assertDatabaseHas('creative_boost_purchases', ['user_id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_property_boost', 'status' => 'pending']);
    }

    public function test_pending_flagship_boost_settles_once_and_remains_visible_in_usage_history(): void
    {
        $user = User::factory()->create(['tenant_key' => 'sitetospend', 'stripe_id' => 'cus_legacy_boost']);
        $workspace = Customer::factory()->create(['tenant_key' => 'sitetospend']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $purchase = CreativeBoostPurchase::create([
            'user_id' => $user->id, 'period' => now()->format('Y-m'), 'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_legacy_boost', 'image_generations' => 25, 'amount_cents' => 2900,
        ]);

        $secret = 'whsec_legacy_boost';
        config(['cashier.webhook.secret' => $secret, 'services.stripe.webhook.secret' => $secret]);
        $payload = json_encode(['id' => 'evt_legacy_boost', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $purchase->stripe_checkout_session_id, 'customer' => $user->stripe_id, 'mode' => 'payment', 'status' => 'complete',
            'payment_status' => 'paid', 'amount_total' => 2900, 'metadata' => ['type' => 'creative_boost', 'purchase_id' => $purchase->id],
        ]]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, $secret);
        foreach ([1, 2] as $delivery) {
            $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}"], $payload)
                ->assertSuccessful();
        }
        $this->actingAs($user)->withSession(['active_customer_id' => $workspace->id])->get('/creative-usage')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('boostConfig', null)
                ->where('creativeUsage.image_generations.bonus', 25)->has('purchases', 1)->where('purchases.0.id', $purchase->id));
    }
}
