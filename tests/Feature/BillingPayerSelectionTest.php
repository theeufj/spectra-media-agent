<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\Billing\AdSpendPaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\PaymentIntent;
use Tests\TestCase;

/**
 * The charge must find the card that exists.
 *
 * Selection took the first owner and only fell back if there was no owner at
 * all, so an owner without a payment method blocked the charge while a
 * teammate's card sat unused. sitetospend has two owners: the one listed first
 * has no card, the other has an Amex — so every charge failed with "No payment
 * method on file" against an account that plainly had one, and the account
 * walked up the payment-failure ladder towards having its campaigns paused.
 */
class BillingPayerSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private ClientInterface $previousStripeClient;

    private PayerSelectionStripeTransport $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier.secret' => 'sk_test_payer_selection', 'services.stripe.secret' => 'sk_test_payer_selection']);
        $this->previousStripeClient = ApiRequestor::httpClient();
        $this->stripe = new PayerSelectionStripeTransport;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousStripeClient);
        parent::tearDown();
    }

    /**
     * Exercise the actual resolver rather than a copy of its selection logic.
     */
    private function pick(Customer $customer): ?string
    {
        return $customer->adSpendPayer()?->email;
    }

    private function attach(Customer $customer, string $email, string $role, ?string $pmType): User
    {
        $user = User::factory()->create(['email' => $email, 'pm_type' => $pmType]);
        $customer->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_an_owner_without_a_card_does_not_block_the_one_with_it(): void
    {
        // The exact shape of the live account.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'first-owner@example.com', 'owner', null);
        $this->attach($customer, 'paying-owner@example.com', 'owner', 'amex');

        $this->assertSame('paying-owner@example.com', $this->pick($customer));
    }

    public function test_an_owner_with_a_card_is_preferred_over_a_member_with_one(): void
    {
        // Whose card gets charged is not arbitrary — the owner's comes first.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'member@example.com', 'admin', 'visa');
        $this->attach($customer, 'owner@example.com', 'owner', 'amex');

        $this->assertSame('owner@example.com', $this->pick($customer));
    }

    public function test_a_member_pays_when_no_owner_can(): void
    {
        $customer = Customer::factory()->create();

        $this->attach($customer, 'owner@example.com', 'owner', null);
        $this->attach($customer, 'member@example.com', 'admin', 'visa');

        $this->assertSame('member@example.com', $this->pick($customer));
    }

    public function test_nobody_with_a_card_means_nobody_is_charged(): void
    {
        // The genuine version of the error that was being reported wrongly.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'owner@example.com', 'owner', null);
        $this->attach($customer, 'member@example.com', 'admin', null);

        $this->assertNull($this->pick($customer));
        $this->assertSame('owner@example.com', $customer->adSpendPayer(allowWithoutPaymentMethod: true)?->email);
    }

    public function test_the_service_still_reports_a_genuine_absence(): void
    {
        $customer = Customer::factory()->create();
        $this->attach($customer, 'nobody@example.com', 'owner', null);

        $method = new \ReflectionMethod(AdSpendBillingService::class, 'chargeCustomer');
        $method->setAccessible(true);

        $result = $method->invoke(app(AdSpendBillingService::class), $customer, 10.0, 'test', 'test-key');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No payment method', $result['error']);
        $this->assertSame([], $this->stripe->requests);
    }

    private function campaignFor(Customer $customer): array
    {
        Setting::set('deployment_enabled', true, 'boolean');
        BrandGuideline::create(['customer_id' => $customer->id, 'brand_voice' => [], 'tone_attributes' => [], 'writing_patterns' => [], 'color_palette' => [], 'typography' => [], 'visual_style' => [], 'messaging_themes' => [], 'unique_selling_propositions' => [], 'target_audience' => [], 'competitor_differentiation' => [], 'brand_personality' => [], 'do_not_use' => [], 'user_verified' => true, 'extracted_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'daily_budget' => 20]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'signed_off_at' => now()]);

        return [$campaign, $strategy];
    }

    private function expectCharge(Customer $customer, User $payer, string $paymentMethod): void
    {
        $this->mock(AdSpendPaymentGateway::class, function ($mock) use ($customer, $payer, $paymentMethod) {
            $mock->shouldReceive('collect')->once()->withArgs(fn ($account, $params, $key) => $account->id === $customer->id
                && $params['customer'] === $payer->stripe_id && $params['payment_method'] === $paymentMethod
                && $params['amount'] === 14000 && $params['currency'] === 'aud')
                ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_payer_test', 'object' => 'payment_intent', 'amount' => 14000, 'currency' => 'aud', 'status' => 'succeeded']));
        });
    }

    public function test_funding_and_both_displays_use_the_other_owners_saved_card(): void
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $firstOwner = $this->attach($customer, 'first@example.com', 'owner', null);
        $payer = $this->attach($customer, 'payer@example.com', 'owner', 'amex');
        $payer->forceFill(['stripe_id' => 'cus_payer', 'pm_last_four' => '0005'])->save();
        $this->stripe->defaults['cus_payer'] = 'pm_saved';
        $this->actingAs($firstOwner)->withSession(['active_customer_id' => $customer->id]);
        [$campaign, $strategy] = $this->campaignFor($customer);

        $this->get(route('billing.ad-spend'))->assertInertia(fn (Assert $page) => $page->where('paymentMethod.payer_name', $payer->name)->where('paymentMethod.last4', '0005'));
        $this->get(route('campaigns.collateral.show', [$campaign, $strategy]))->assertInertia(fn (Assert $page) => $page->where('hasPaymentMethod', true));
        $this->expectCharge($customer, $payer, 'pm_saved');
        $this->postJson(route('billing.ad-spend.setup-for-deployment'), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'daily_budget' => 20])
            ->assertOk()->assertJsonPath('credit_amount', '140.00');

        $this->assertNull($firstOwner->fresh()->stripe_id);
        $this->assertDatabaseHas('ad_spend_credits', ['customer_id' => $customer->id, 'stripe_payment_method_id' => 'pm_saved']);
        $this->assertSame([['method' => 'get', 'path' => '/v1/customers/cus_payer']], $this->stripe->requests);
    }

    public function test_a_members_replacement_card_updates_the_selected_business_payer_then_funds_it(): void
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $actor = $this->attach($customer, 'member@example.com', 'admin', null);
        $payer = $this->attach($customer, 'owner@example.com', 'owner', 'visa');
        $payer->forceFill(['stripe_id' => 'cus_payer', 'pm_last_four' => '4242'])->save();
        $this->stripe->defaults['cus_payer'] = 'pm_saved';
        $this->actingAs($actor)->withSession(['active_customer_id' => $customer->id]);
        [$campaign, $strategy] = $this->campaignFor($customer);

        $this->postJson(route('billing.ad-spend.update-payment-method'), ['payment_method_id' => 'pm_replacement'])->assertOk();
        $this->assertNull($actor->fresh()->pm_type);
        $this->assertSame('0005', $payer->fresh()->pm_last_four);
        $this->get(route('billing.ad-spend'))->assertInertia(fn (Assert $page) => $page->where('paymentMethod.payer_name', $payer->name)->where('paymentMethod.last4', '0005'));
        $this->expectCharge($customer, $payer, 'pm_replacement');
        $this->postJson(route('billing.ad-spend.setup-for-deployment'), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'daily_budget' => 20])->assertOk();
        $this->assertDatabaseHas('ad_spend_credits', ['customer_id' => $customer->id, 'stripe_payment_method_id' => 'pm_replacement']);
        $this->assertNotContains('/v1/customers/'.$actor->stripe_id, array_column($this->stripe->requests, 'path'));
    }

    public function test_membership_is_required_to_replace_a_business_payment_method(): void
    {
        $customer = Customer::factory()->create();
        $payer = $this->attach($customer, 'owner@example.com', 'owner', 'visa');
        $outsider = User::factory()->create(['email_verified_at' => now(), 'subscription_status' => 'active']);
        $this->actingAs($outsider)->withSession(['active_customer_id' => $customer->id]);
        $this->postJson(route('billing.ad-spend.update-payment-method'), ['payment_method_id' => 'pm_replacement'])->assertNotFound();
        $this->assertSame('visa', $payer->fresh()->pm_type);
        $this->assertSame([], $this->stripe->requests);
    }
}

/** A closed Stripe transport: every unexpected request fails without networking. */
class PayerSelectionStripeTransport implements ClientInterface
{
    public array $requests = [];

    public array $defaults = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path];
        if ($path === '/v1/customers/cus_payer' && in_array($method, ['get', 'post'], true)) {
            if ($method === 'post') {
                $this->defaults['cus_payer'] = $params['invoice_settings']['default_payment_method'];
            }
            $payment = ['id' => $this->defaults['cus_payer'], 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_payer', 'card' => ['brand' => 'amex', 'last4' => '0005']];
            $expanded = in_array('invoice_settings.default_payment_method', $params['expand'] ?? [], true);

            return [json_encode(['id' => 'cus_payer', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $expanded ? $payment : $payment['id']]]), 200, []];
        }
        if ($path === '/v1/payment_methods/pm_replacement' && $method === 'get') {
            return [json_encode(['id' => 'pm_replacement', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_payer', 'card' => ['brand' => 'amex', 'last4' => '0005']]), 200, []];
        }
        throw new \RuntimeException('Unexpected Stripe test request: '.$method.' '.$path);
    }
}
