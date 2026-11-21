<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class SubscriptionCheckoutCardTest extends TestCase
{
    use DatabaseTransactions;

    public static function cards(): array
    {
        return [
            'new checkout' => [null, 'cus_payer', true, 'visa', 1],
            'existing default' => ['pm_explicit', 'cus_payer', true, 'amex', 0],
            'foreign payment method' => [null, 'cus_other', true, null, 0],
            'different signed payer' => [null, 'cus_payer', false, null, 0],
        ];
    }

    #[DataProvider('cards')]
    public function test_signed_checkout_uses_only_this_payers_first_owned_card(?string $default, string $owner, bool $matches, ?string $brand, int $writes): void
    {
        $this->withoutExceptionHandling();
        config(['cashier.secret' => 'sk_test_card_fixture', 'services.stripe.secret' => 'sk_test_card_fixture', 'cashier.webhook.secret' => 'whsec_card_fixture', 'services.stripe.webhook.secret' => 'whsec_card_fixture']);
        $plan = Plan::updateOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 24900, 'stripe_price_id' => 'price_growth_fixture', 'billing_interval' => 'month']);
        $payer = User::factory()->create(['stripe_id' => 'cus_payer', 'pm_type' => null]);
        $workspace = Customer::factory()->create();
        $workspace->users()->attach($payer, ['role' => 'owner']);
        $stripe = new CheckoutCardTestTransport($default, $owner);
        $original = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($stripe);
        $payload = json_encode(['id' => 'evt_fixture', 'type' => 'customer.subscription.created', 'livemode' => false, 'data' => ['object' => [
            'id' => 'sub_fixture', 'customer' => $payer->stripe_id, 'status' => 'active', 'default_payment_method' => 'pm_subscription',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'billing_user_id' => $matches ? (string) $payer->id : '0'],
            'items' => ['data' => [['id' => 'si_fixture', 'quantity' => 1, 'price' => ['id' => $plan->stripe_price_id, 'product' => 'prod_growth', 'type' => 'recurring']]]],
        ]]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_card_fixture');
        try {
            foreach ([1, 2] as $attempt) {
                $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}"], $payload)->assertSuccessful();
            }
        } finally {
            ApiRequestor::setHttpClient($original);
        }
        $this->assertSame($brand, $payer->fresh()->pm_type);
        $this->assertSame($writes, $stripe->writes);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_fixture', 'stripe_status' => 'active']);
    }
}

class CheckoutCardTestTransport implements ClientInterface
{
    public int $writes = 0;

    public function __construct(private ?string $default, private string $owner) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $payment = fn ($id) => ['id' => $id, 'object' => 'payment_method', 'customer' => $this->owner, 'type' => 'card', 'card' => ['brand' => $id === 'pm_explicit' ? 'amex' : 'visa', 'last4' => '4242']];
        if ($method === 'get' && $path === '/v1/subscriptions/sub_fixture') {
            $body = ['id' => 'sub_fixture', 'object' => 'subscription', 'customer' => 'cus_payer', 'status' => 'active', 'default_payment_method' => 'pm_subscription'];
        } elseif ($method === 'get' && $path === '/v1/payment_methods/pm_subscription') {
            $body = $payment('pm_subscription');
        } elseif ($path === '/v1/customers/cus_payer' && in_array($method, ['get', 'post'], true)) {
            if ($method === 'post') {
                $this->writes++;
                $this->default = $params['invoice_settings']['default_payment_method'];
            }
            $expanded = in_array('invoice_settings.default_payment_method', $params['expand'] ?? [], true);
            $body = ['id' => 'cus_payer', 'object' => 'customer', 'default_source' => null, 'invoice_settings' => ['default_payment_method' => $this->default && $expanded ? $payment($this->default) : $this->default]];
        } else {
            throw new \RuntimeException('Unexpected Stripe test request.');
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
    }
}
