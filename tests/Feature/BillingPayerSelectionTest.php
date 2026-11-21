<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\Billing\AdSpendPaymentGateway;
use App\Services\Campaigns\CampaignMarketResearch;
use App\Services\Creative\CreativeRelease;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
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
        $this->withoutVite();
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
        $plan = Plan::firstOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 10000, 'billing_interval' => 'month']);
        $customer->update(['plan_id' => $plan->id]);
        BrandGuideline::create(['customer_id' => $customer->id, 'brand_voice' => [], 'tone_attributes' => [], 'writing_patterns' => [], 'color_palette' => [], 'typography' => [], 'visual_style' => [], 'messaging_themes' => [], 'unique_selling_propositions' => [], 'target_audience' => [], 'competitor_differentiation' => [], 'brand_personality' => [], 'do_not_use' => [], 'user_verified' => true, 'extracted_at' => now()]);
        $campaign = Campaign::factory()->for($customer)->create(['daily_budget' => 20, 'approved_daily_budget' => 20, 'budget_confirmed_at' => now()]);
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display',
            'signed_off_at' => now(), 'daily_budget' => 20]);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Reviewed ad'],
            'descriptions' => ['Reviewed description'], 'should_deploy' => true]);
        $fingerprint = app(CreativeRelease::class)->selection($strategy)['fingerprint'];
        $strategy->forceFill(['creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint, 'issues' => []]])->save();
        $campaign->update(['search_delivery_state' => ['market_research' => [
            'status' => 'ready', 'request_id' => 'payer-selection-research',
            'approved_request_id' => 'payer-selection-research', 'result' => ['status' => 'ready'],
            'approved_fingerprint' => app(CampaignMarketResearch::class)->approvalFingerprint($campaign),
        ]]]);

        return [$campaign, $strategy, $fingerprint];
    }

    public function test_funding_and_both_displays_use_the_other_owners_saved_card(): void
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $firstOwner = $this->attach($customer, 'first@example.com', 'owner', null);
        $payer = $this->attach($customer, 'payer@example.com', 'owner', 'amex');
        $payer->forceFill(['stripe_id' => 'cus_payer', 'pm_last_four' => '0005'])->save();
        $this->stripe->defaults['cus_payer'] = 'pm_saved';
        $this->actingAs($firstOwner)->withSession(['active_customer_id' => $customer->id]);
        [$campaign, $strategy, $fingerprint] = $this->campaignFor($customer);

        $this->get(route('billing.ad-spend'))->assertInertia(fn (Assert $page) => $page->where('paymentMethod.payer_name', $payer->name)->where('paymentMethod.last4', '0005'));
        $this->get(route('campaigns.collateral.show', [$campaign, $strategy]))->assertInertia(fn (Assert $page) => $page->where('hasPaymentMethod', true));
        $this->mock(AdSpendPaymentGateway::class)->shouldNotReceive('collect');
        $this->postJson(route('billing.ad-spend.setup-for-deployment'), [
            'campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'daily_budget' => 20,
            'creative_fingerprints' => [$strategy->id => $fingerprint],
        ])
            ->assertOk()->assertJsonPath('credit_amount', 0)->assertJsonPath('deferred', true);

        $this->assertNull($firstOwner->fresh()->stripe_id);
        $this->assertDatabaseMissing('ad_spend_credits', ['customer_id' => $customer->id]);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_members_replacement_card_updates_the_business_payer_without_collecting_before_approval(): void
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $actor = $this->attach($customer, 'member@example.com', 'admin', null);
        $payer = $this->attach($customer, 'owner@example.com', 'owner', 'visa');
        $payer->forceFill(['stripe_id' => 'cus_payer', 'pm_last_four' => '4242'])->save();
        $this->stripe->defaults['cus_payer'] = 'pm_saved';
        $this->actingAs($actor)->withSession(['active_customer_id' => $customer->id]);
        [$campaign, $strategy, $fingerprint] = $this->campaignFor($customer);

        $this->postJson(route('billing.ad-spend.update-payment-method'), ['payment_method_id' => 'pm_replacement'])->assertOk();
        $this->assertNull($actor->fresh()->pm_type);
        $this->assertSame('0005', $payer->fresh()->pm_last_four);
        $this->get(route('billing.ad-spend'))->assertInertia(fn (Assert $page) => $page->where('paymentMethod.payer_name', $payer->name)->where('paymentMethod.last4', '0005'));
        $this->mock(AdSpendPaymentGateway::class)->shouldNotReceive('collect');
        $this->postJson(route('billing.ad-spend.setup-for-deployment'), [
            'campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'daily_budget' => 20,
            'creative_fingerprints' => [$strategy->id => $fingerprint],
        ])->assertOk();
        $this->assertDatabaseMissing('ad_spend_credits', ['customer_id' => $customer->id]);
        $this->assertNotContains('/v1/customers/'.$actor->stripe_id, array_column($this->stripe->requests, 'path'));
    }

    public function test_a_second_member_cannot_open_hosted_checkout_against_the_wrong_stripe_customer(): void
    {
        $customer = Customer::factory()->create();
        $this->attach($customer, 'billing@example.com', 'owner', 'visa')->forceFill(['stripe_id' => 'cus_payer'])->save();
        $member = $this->attach($customer, 'member@example.com', 'member', null);
        $plan = Plan::updateOrCreate(['slug' => 'starter'], ['name' => 'Starter', 'price_cents' => 9900, 'stripe_price_id' => 'price_workspace_only', 'billing_interval' => 'month', 'is_active' => true]);
        Setting::set('deployment_enabled', true);
        $this->actingAs($member)->withSession(['active_customer_id' => $customer->id])->from(route('subscription.pricing'))
            ->post(route('subscription.checkout'), ['price_id' => $plan->stripe_price_id, 'platforms' => ['google']])
            ->assertRedirect(route('subscription.pricing'))->assertSessionHas('flash.message', 'The workspace billing contact must start its monthly subscription.');
        $this->assertNull($member->fresh()->stripe_id);
        $this->assertSame([], $this->stripe->requests);
    }

    public static function unresolvedPaymentStates(): array
    {
        return [['requires_action'], ['requires_payment_method']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedPaymentStates')]
    public function test_the_exact_payer_can_resolve_a_bank_challenge_or_decline_and_the_one_shortfall_is_collected(string $initialStatus): void
    {
        $customer = Customer::factory()->create(['currency_code' => 'AUD', 'facebook_ads_account_id' => 'act_47813', 'facebook_page_id' => '7766']);
        $approver = $this->attach($customer, 'manager@example.com', 'owner', null);
        $payer = $this->attach($customer, 'billing@example.com', 'owner', 'visa');
        $payer->forceFill(['stripe_id' => 'cus_payer', 'subscription_status' => 'active'])->save();
        $approver->forceFill(['subscription_status' => 'active'])->save();
        $this->stripe->defaults['cus_payer'] = 'pm_saved';
        $this->stripe->intentStatus = $initialStatus;
        [$campaign, $strategy, $fingerprint] = $this->campaignFor($customer);
        $wallet = \App\Models\AdSpendCredit::factory()->for($customer)->create(['current_balance' => 20, 'currency' => 'AUD']);
        $funding = $this->createMock(\App\Services\Billing\PlatformFunding::class);
        $funding->method('assess')->willReturn(['ready' => true, 'platform' => 'facebook', 'account' => '47813', 'currency' => 'AUD', 'payer' => 'agency', 'code' => 'ready', 'message' => 'Verified']);
        $this->app->instance(\App\Services\Billing\PlatformFunding::class, $funding);
        $events = $this->createMock(\App\Services\Conversions\CampaignOutcomeEvidence::class);
        $events->method('forStrategy')->willReturn(['requires_verification' => true, 'verified_for_launch' => true]);
        $this->app->instance(\App\Services\Conversions\CampaignOutcomeEvidence::class, $events);
        $launches = app(\App\Services\Deployment\CampaignLaunchIntent::class);
        $id = $launches->request($campaign, $approver, $strategy->id, [$strategy->id => $fingerprint]);
        $this->assertNotNull($id);
        $this->assertFalse($launches->resume($campaign->id, $id));
        $hold = \Illuminate\Support\Facades\DB::table('launch_funding_reservations')->where('strategy_id', $strategy->id)->sole();
        $this->assertSame(12000, $hold->charge_cents);
        $this->assertSame(2000, $hold->held_cents);
        $this->assertSame('20.00', $wallet->fresh()->current_balance);
        $this->assertSame('requires_action', $strategy->fresh()->launch_intent['funding']['code']);
        $url = $strategy->fresh()->launch_intent['funding']['setup_url'];
        $this->actingAs($approver)->withSession(['active_customer_id' => $customer->id])->get($url)->assertNotFound();
        $this->actingAs($payer)->withSession(['active_customer_id' => $customer->id])->get($url)
            ->assertRedirect(route('cashier.payment', ['id' => 'pi_launch_fixture', 'redirect' => route('billing.ad-spend')]));
        $this->stripe->intentStatus = 'succeeded';
        $this->assertTrue($launches->resume($campaign->id, $id));
        $this->assertFalse($launches->resume($campaign->id, $id));
        $this->assertSame('140.00', $wallet->fresh()->current_balance);
        $this->assertSame(1, $wallet->transactions()->where('stripe_charge_id', 'pi_launch_fixture')->count());
        $this->assertSame(1, collect($this->stripe->requests)->where('path', '/v1/payment_intents')->where('method', 'post')->count());
        $this->assertSame('cus_payer', $this->stripe->intentParams['customer']);
        $this->assertSame(12000, (int) $this->stripe->intentParams['amount']);
        $this->assertDatabaseHas('ad_spend_charge_attempts', ['idempotency_key' => 'launch-reservation:'.$id.':12000', 'payment_intent_id' => 'pi_launch_fixture']);
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

    public ?string $intentStatus = null;

    public array $intentParams = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path];
        if ($this->intentStatus && ($method === 'post' && $path === '/v1/payment_intents' || $method === 'get' && $path === '/v1/payment_intents/pi_launch_fixture')) {
            if ($method === 'post') {
                $this->intentParams = $params;
            }
            $fields = $this->intentParams;
            $fields['amount'] = (int) $fields['amount'];
            $fields['amount_received'] = $this->intentStatus === 'succeeded' ? $fields['amount'] : 0;

            return [json_encode([...$fields, 'id' => 'pi_launch_fixture', 'object' => 'payment_intent', 'status' => $this->intentStatus]), 200, []];
        }
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
