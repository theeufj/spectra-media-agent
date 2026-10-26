<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Models\User;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\WebsiteConversionActions;
use App\Services\Onboarding\SetupConversionGoal;
use App\Services\Onboarding\SetupJourney;
use App\Services\OwnSitePurchaseTracking;
use App\Services\PaidSubscriptionConversionService;
use App\Services\SetupFeeService;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OwnSitePurchaseTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private Customer $customer;

    private Strategy $strategy;

    private const ACTION = 'customers/1234567890/conversionActions/789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Cache::flush();
        Mail::fake();
        config(['app.url' => 'https://sitetospend.test', 'cashier.webhook.secret' => 'whsec_fixture']);
        $this->owner = User::factory()->create(['stripe_id' => 'cus_fixture']);
        $this->customer = Customer::factory()->create(['website' => 'https://sitetospend.test',
            'google_ads_customer_id' => '1234567890', 'service_type' => 'setup_only', 'setup_fee_paid_at' => now(), 'gtm_installed' => false]);
        $this->customer->users()->attach($this->owner, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'status' => 'paused',
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()]);
        $intent = ['action_resource' => self::ACTION, 'category' => 'PURCHASE', 'origin' => 'WEBSITE'];
        $this->strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/456', 'deployment_status' => 'verified', 'signed_off_at' => now(),
            'conversion_goals' => ['primary_goal' => 'PURCHASE', 'action_resource' => self::ACTION],
            'execution_result' => ['metadata' => ['setup_conversion_goal' => ['status' => 'configured', 'request_id' => 'reviewed-request',
                'requested_at' => now()->subMinute()->toIso8601String(), 'action_resource' => self::ACTION,
                'goal' => ['objective' => 'purchase', 'trigger' => 'prepare_only']],
                'conversion_goal_readiness' => ['ready' => true, 'configuration_verified' => true,
                    'campaign_resource' => 'customers/1234567890/campaigns/456', 'intent' => $intent, 'checked_at' => now()->toIso8601String()],
                'google_search_baseline' => ['conversion_goal' => $intent]]]]);
        $actions = $this->createMock(WebsiteConversionActions::class);
        $actions->method('all')->willReturn([['resource' => self::ACTION, 'objective' => 'purchase', 'always_use_default_value' => false]]);
        $this->app->bind(WebsiteConversionActions::class, fn () => $actions);
        $details = $this->createMock(GetConversionActionDetails::class);
        $details->method('__invoke')->willReturn(['conversion_id' => 'AW-18532358762', 'conversion_label' => 'exact-label']);
        $this->app->bind(GetConversionActionDetails::class, fn () => $details);
        $status = $this->createMock(GetCampaignStatus::class);
        $status->method('__invoke')->willReturn(['status' => CampaignStatus::PAUSED]);
        $this->app->bind(GetCampaignStatus::class, fn () => $status);
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('inspect')->willReturn(['ready' => true, 'configuration_verified' => true,
            'intent' => ['action_resource' => self::ACTION], 'campaign_resource' => 'customers/1234567890/campaigns/456']);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
    }

    private function service(): OwnSitePurchaseTracking
    {
        return app(OwnSitePurchaseTracking::class);
    }

    private function checkoutSession(array $overrides = []): array
    {
        return array_replace(['id' => 'cs_fixture', 'customer' => $this->owner->stripe_id, 'mode' => 'payment',
            'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 99900, 'currency' => 'usd', 'livemode' => true,
            'created' => now()->subHours(2)->timestamp, 'client_reference_id' => 'setup-fee-'.$this->customer->id,
            'metadata' => ['purpose' => 'setup_fee', 'customer_id' => (string) $this->customer->id, 'user_id' => (string) $this->owner->id],
            'payment_intent' => ['latest_charge' => ['created' => now()->timestamp]]], $overrides);
    }

    private function trackingRequest(string $origin = 'https://sitetospend.test'): Request
    {
        return Request::create($origin.'/purchase-tracking/pending');
    }

    private function install(): array
    {
        return $this->service()->bindReviewedGoal($this->strategy);
    }

    private function signedCheckout(array $session, ?int $completedAt = null): \Illuminate\Testing\TestResponse
    {
        $payload = ['id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'livemode' => true,
            'created' => $completedAt ?? now()->timestamp, 'data' => ['object' => $session]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $body);
    }

    public function test_signed_completed_checkout_records_a_real_payment_once_even_when_setup_was_already_paid(): void
    {
        $this->install();
        $session = $this->checkoutSession();
        unset($session['payment_intent']);
        $this->signedCheckout($session)->assertSuccessful();
        $this->signedCheckout($session)->assertSuccessful();
        $row = SpectraConversionEvent::where('event', 'paid_setup')->sole();
        $this->assertSame('999.00', $row->value);
        $this->assertSame('USD', $row->currency);
        $this->assertSame(now()->timestamp, $row->occurred_at->timestamp);
        $this->assertSame('paid_setup:cs_fixture', $row->deduplication_key);
        $this->assertFalse($row->uploaded_to_google);
        $pending = $this->service()->forBrowser($this->owner, $this->trackingRequest());
        $this->assertTrue($pending['installed']);
        $this->assertSame(99900, $pending['pending'][0]['value_cents']);
        $this->assertSame('AW-18532358762/exact-label', $pending['pending'][0]['send_to']);
        $this->assertNull($row->browser_invoked_at);
    }

    public function test_verified_return_and_webhook_share_one_receipt_without_using_checkout_start_as_payment_time(): void
    {
        $this->customer->update(['setup_fee_paid_at' => null]);
        $this->install();
        $session = $this->checkoutSession();
        $service = new class($session) extends SetupFeeService
        {
            public function __construct(private array $session) {}

            protected function retrieveSession(string $sessionId): object
            {
                return \Stripe\Checkout\Session::constructFrom($this->session);
            }
        };
        $this->assertTrue($service->confirm($this->owner, $this->customer, 'cs_fixture'));
        $this->signedCheckout($session)->assertSuccessful();
        $this->assertSame(1, SpectraConversionEvent::where('event', 'paid_setup')->count());
        $this->assertCount(1, $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
    }

    public function test_new_google_attributed_setup_purchase_uses_one_pinned_canonical_server_destination_and_preserves_native_binding(): void
    {
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/789');
        $binding = $this->install();
        $this->owner->update(['wbraid' => 'fixture-braid']);
        $this->signedCheckout($this->checkoutSession())->assertSuccessful();
        $this->signedCheckout($this->checkoutSession())->assertSuccessful();
        $receipt = SpectraConversionEvent::where('event', 'paid_setup')->sole();
        $this->assertSame('server_google', $receipt->mode);
        $this->assertSame('customers/999/conversionActions/789', $receipt->google_conversion_resource);
        $this->assertSame('999.00', $receipt->value);
        $this->assertSame('USD', $receipt->currency);
        $this->assertSame('paid_setup:cs_fixture', $receipt->deduplication_key);
        $this->assertSame(['wbraid' => 'fixture-braid'], $receipt->ad_identifiers);
        $this->assertSame($binding, Setting::get(OwnSitePurchaseTracking::SETTING));
        $this->assertSame([], $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
        Queue::assertPushed(RecordSiteGoogleConversion::class);

        // A later mapping change cannot retarget the recorded purchase.
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/111');
        $dataManager = $this->createMock(DataManagerService::class);
        $dataManager->expects($this->once())->method('ingestConversion')->willReturnCallback(function (...$arguments) use ($receipt): array {
            $this->assertSame('999', $arguments[0]);
            $this->assertSame('789', $arguments[1]);
            $this->assertSame(['wbraid' => 'fixture-braid'], $arguments[2]);
            $this->assertSame(999.0, $arguments[3]);
            $this->assertSame('USD', $arguments[4]);
            $this->assertSame($receipt->occurred_at->timestamp, $arguments[5]->getTimestamp());
            $this->assertContains('paid_setup:cs_fixture', $arguments);

            return ['success' => true, 'requestId' => 'paid-setup-receipt'];
        });
        $job = new RecordSiteGoogleConversion($this->owner, 'paid_setup', $receipt->occurred_at, $receipt->id);
        $job->handle($dataManager);
        $job->handle($dataManager);
        $this->assertTrue($receipt->fresh()->uploaded_to_google);
        $this->assertSame([], $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
    }

    public function test_server_selected_setup_receipts_never_fall_back_to_browser_after_failure_or_wrong_account_mapping(): void
    {
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/888/conversionActions/789');
        $binding = $this->install();
        $this->owner->update(['gclid' => 'fixture-click']);
        $receipt = $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $this->assertSame('server_google', $receipt->mode);
        $this->assertNull($receipt->google_conversion_resource);
        $this->assertNotEmpty($receipt->upload_error);
        $dataManager = $this->createMock(DataManagerService::class);
        $dataManager->expects($this->never())->method('ingestConversion');
        try {
            (new RecordSiteGoogleConversion($this->owner, 'paid_setup', $receipt->occurred_at, $receipt->id))->handle($dataManager);
            $this->fail('An unverified canonical destination cannot receive a paid setup conversion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('canonical', $e->getMessage());
        }
        $this->assertSame([], $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
        $this->assertSame($binding, Setting::get(OwnSitePurchaseTracking::SETTING));
        $this->assertFalse($receipt->fresh()->uploaded_to_google);
    }

    public function test_dedicated_setup_mapping_routes_only_new_checkout_receipts_and_never_reroutes_history(): void
    {
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/789');
        $this->owner->update(['gclid' => 'fixture-click']);
        $old = $this->service()->recordSetupPurchase($this->owner, $this->customer,
            $this->checkoutSession(['id' => 'cs_before_split']), now()->timestamp);
        Setting::set('conversion_resource_name.paid_setup', 'customers/999/conversionActions/909');
        $new = $this->service()->recordSetupPurchase($this->owner, $this->customer,
            $this->checkoutSession(['id' => 'cs_after_split', 'amount_total' => 89900]), now()->timestamp);
        $this->assertSame('customers/999/conversionActions/789', $old->google_conversion_resource);
        $this->assertSame('customers/999/conversionActions/909', $new->google_conversion_resource);
        $this->assertSame('899.00', $new->value, 'The event carries the verified payment, not an invented package value.');
        $this->assertSame('paid_setup', RecordSiteGoogleConversion::actionKey('paid_setup'));
        Setting::set('conversion_resource_name.paid_setup', 'customers/999/conversionActions/111');
        $replayed = $this->service()->recordSetupPurchase($this->owner, $this->customer,
            $this->checkoutSession(['id' => 'cs_after_split']), now()->timestamp);
        $this->assertSame($new->id, $replayed->id);
        $this->assertSame('customers/999/conversionActions/909', $replayed->google_conversion_resource);
        $destinations = [];
        $delivery = $this->createMock(DataManagerService::class);
        $delivery->expects($this->exactly(2))->method('ingestConversion')->willReturnCallback(function (...$arguments) use (&$destinations): array {
            $destinations[] = $arguments[1];

            return ['success' => true, 'requestId' => 'purchase-'.$arguments[1]];
        });
        foreach ([$old, $new] as $receipt) {
            (new RecordSiteGoogleConversion($this->owner, 'paid_setup', $receipt->occurred_at, $receipt->id))->handle($delivery);
        }
        $this->assertSame(['789', '909'], $destinations);
        $this->assertSame(2, SpectraConversionEvent::where('event', 'paid_setup')->count());
    }

    public function test_invalid_dedicated_setup_mapping_does_not_silently_fall_back_to_subscription_goal(): void
    {
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/789');
        $this->owner->update(['gclid' => 'fixture-click']);
        foreach (['customers/888/conversionActions/909', '', 'customers/999/conversionActions/789'] as $index => $destination) {
            Setting::set('conversion_resource_name.paid_setup', $destination);
            $receipt = $this->service()->recordSetupPurchase($this->owner, $this->customer,
                $this->checkoutSession(['id' => 'cs_invalid_'.$index]), now()->timestamp);
            $this->assertSame('server_google', $receipt->mode);
            $this->assertNull($receipt->google_conversion_resource);
            $this->assertNotEmpty($receipt->upload_error);
        }
    }

    public function test_replayed_legacy_native_receipt_is_not_rerouted_after_click_identifiers_become_available(): void
    {
        $this->install();
        $receipt = $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/789');
        $this->owner->update(['gclid' => 'new-click']);
        $replayed = $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $this->assertSame($receipt->id, $replayed->id);
        $this->assertSame('verified_purchase', $replayed->mode);
        $this->assertNull($replayed->google_conversion_resource);
        $this->assertCount(1, $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
    }

    public function test_failed_canonical_setup_upload_retries_its_pinned_receipt_without_browser_fallback(): void
    {
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/999/conversionActions/789');
        $binding = $this->install();
        $this->owner->update(['gclid' => 'fixture-click']);
        $receipt = $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $calls = 0;
        $dataManager = $this->createMock(DataManagerService::class);
        $dataManager->expects($this->exactly(2))->method('ingestConversion')->willReturnCallback(function (...$arguments) use (&$calls): array {
            $this->assertSame('999', $arguments[0]);
            $this->assertSame('789', $arguments[1]);
            $this->assertContains('paid_setup:cs_fixture', $arguments);

            return ++$calls === 1 ? ['success' => false, 'error' => 'Temporary provider failure']
                : ['success' => true, 'requestId' => 'retry-setup-receipt'];
        });
        $job = new RecordSiteGoogleConversion($this->owner, 'paid_setup', $receipt->occurred_at, $receipt->id);
        try {
            $job->handle($dataManager);
            $this->fail('A rejected upload must remain retryable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Temporary provider failure', $e->getMessage());
        }
        $this->assertFalse($receipt->fresh()->uploaded_to_google);
        $this->assertNotEmpty($receipt->fresh()->upload_error);
        $this->assertSame([], $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/888/conversionActions/111');
        $job->handle($dataManager);
        $this->assertTrue($receipt->fresh()->uploaded_to_google);
        $this->assertSame($binding, Setting::get(OwnSitePurchaseTracking::SETTING));
    }

    public function test_a_paid_setup_upload_cannot_invent_a_receipt_or_import_a_legacy_browser_purchase(): void
    {
        $dataManager = $this->createMock(DataManagerService::class);
        $dataManager->expects($this->never())->method('ingestConversion');
        foreach ([null, $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp)] as $receipt) {
            try {
                (new RecordSiteGoogleConversion($this->owner, 'paid_setup', $receipt?->occurred_at, $receipt?->id))->handle($dataManager);
                $this->fail('A paid setup requires an existing server-selected Stripe receipt.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('receipt', $e->getMessage());
            }
        }
    }

    public function test_free_failed_setup_only_unrelated_or_mismatched_checkouts_never_become_purchases(): void
    {
        foreach ([['amount_total' => 0, 'payment_status' => 'no_payment_required'], ['payment_status' => 'unpaid'],
            ['mode' => 'setup'], ['status' => 'open'], ['amount_total' => 100000], ['currency' => 'eur'],
            ['metadata' => ['purpose' => 'creative_boost']], ['customer' => 'cus_someone_else'],
            ['client_reference_id' => 'another-order'], ['created' => now()->addMinute()->timestamp]] as $override) {
            $this->assertNull($this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession($override), now()->timestamp));
        }
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_test_mode_payment_cannot_enter_production_purchase_receipts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertNull($this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(['livemode' => false]), now()->timestamp));
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_sitewide_destination_serves_only_each_authenticated_buyers_own_receipt_and_invocation_is_not_google_proof(): void
    {
        $this->install();
        $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $buyer = User::factory()->create(['stripe_id' => 'cus_other']);
        $business = Customer::factory()->create();
        $business->users()->attach($buyer, ['role' => 'owner']);
        $receipt = $this->service()->recordSetupPurchase($buyer, $business, $this->checkoutSession([
            'id' => 'cs_other', 'customer' => 'cus_other', 'client_reference_id' => 'setup-fee-'.$business->id,
            'metadata' => ['purpose' => 'setup_fee', 'customer_id' => (string) $business->id, 'user_id' => (string) $buyer->id],
        ]), now()->timestamp);
        $this->actingAs($buyer);
        $this->getJson('https://sitetospend.test/purchase-tracking/pending')->assertOk()->assertJsonCount(1, 'pending')->assertJsonPath('pending.0.receipt_id', $receipt->id);
        $payload = $this->service()->forBrowser($buyer, $this->trackingRequest())['pending'][0];
        $this->postJson('https://sitetospend.test/purchase-tracking/'.SpectraConversionEvent::where('user_id', $this->owner->id)->sole()->id.'/invoked', ['binding_id' => $payload['binding_id']])->assertNotFound();
        $this->postJson($payload['ack_url'], ['binding_id' => $payload['binding_id']])->assertOk()->assertJsonPath('google_conversion_verified', false);
        $this->postJson($payload['ack_url'], ['binding_id' => $payload['binding_id']])->assertOk();
        $receipt->refresh();
        $this->assertNotNull($receipt->browser_invoked_at);
        $this->assertSame(now()->timestamp, $receipt->browser_invoked_at->timestamp);
        $this->assertSame(self::ACTION, $receipt->browser_target_resource);
        $this->assertSame('browser_invoked', $receipt->googleDeliveryStatus());
        $this->assertFalse($receipt->uploaded_to_google);
        $this->assertNull($receipt->google_request_id);
        $this->assertSame([], $this->service()->forBrowser($buyer, $this->trackingRequest())['pending']);
    }

    public function test_binding_rejects_customer_installation_and_goes_inactive_when_reviewed_goal_changes(): void
    {
        $this->actingAs($this->owner);
        try {
            $this->install();
            $this->fail('A customer cannot install a site-wide destination.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('operator', $e->getMessage());
        }
        auth()->forgetGuards();
        $this->install();
        $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $before = $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending'][0];
        $metadata = $this->strategy->execution_result;
        $metadata['metadata']['setup_conversion_goal']['request_id'] = 'changed-choice';
        $this->strategy->update(['execution_result' => $metadata]);
        $this->assertFalse($this->service()->forBrowser($this->owner, $this->trackingRequest())['installed']);
        $this->actingAs($this->owner)->postJson($before['ack_url'], ['binding_id' => $before['binding_id']])->assertStatus(409);
    }

    public function test_binding_is_not_authorized_by_domain_claim_or_another_account_and_does_not_replay_old_receipts(): void
    {
        $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->subMinute()->timestamp);
        $this->install();
        $this->assertSame([], $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending']);
        $this->assertFalse($this->service()->forBrowser($this->owner, $this->trackingRequest('https://another.example'))['installed']);
        $this->customer->update(['google_ads_customer_id' => '9876543210']);
        $this->assertFalse($this->service()->statusForStrategy($this->strategy)['connected']);
    }

    public function test_only_known_initial_paid_invoices_use_the_browser_bridge_while_server_import_is_independent(): void
    {
        $this->install();
        Plan::create(['name' => 'Paid fixture', 'slug' => 'native-paid-fixture', 'price_cents' => 25000,
            'stripe_price_id' => 'price_native_fixture', 'is_free' => false, 'billing_interval' => 'month']);
        $invoice = ['id' => 'in_fixture', 'customer' => $this->owner->stripe_id, 'subscription' => 'sub_fixture',
            'status' => 'paid', 'billing_reason' => 'subscription_create', 'amount_paid' => 25037, 'currency' => 'aud',
            'status_transitions' => ['paid_at' => now()->timestamp], 'lines' => ['data' => [['price' => ['id' => 'price_native_fixture']]]]];
        foreach ([['billing_reason' => 'subscription_cycle'], ['amount_paid' => 0], ['lines' => ['data' => [['price' => ['id' => 'price_ad_credit']]]]]] as $override) {
            app(PaidSubscriptionConversionService::class)->record(['livemode' => true, 'data' => ['object' => array_replace($invoice, $override)]]);
        }
        app(PaidSubscriptionConversionService::class)->record(['livemode' => true, 'data' => ['object' => $invoice]]);
        app(PaidSubscriptionConversionService::class)->record(['livemode' => true, 'data' => ['object' => $invoice]]);
        $this->assertSame(1, SpectraConversionEvent::where('event', 'paid_subscription')->count());
        $purchase = $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending'][0];
        $this->assertSame('paid_subscription:sub_fixture', $purchase['transaction_id']);
        $this->assertSame(25037, $purchase['value_cents']);
        $this->assertSame('AUD', $purchase['currency']);
        $this->assertNull(SpectraConversionEvent::sole()->google_conversion_resource);
    }

    public function test_native_installation_is_visible_without_falsifying_gtm_or_real_conversion_verification(): void
    {
        $this->install();
        $status = app(SetupConversionGoal::class)->status($this->strategy->campaign, $this->strategy->uuid);
        $this->assertTrue($status['nativeTracking']['connected']);
        $this->assertSame('native_verified_checkout', $status['nativeTracking']['method']);
        $this->assertFalse($status['installation']['tag_detected']);
        $this->assertFalse($status['conversionVerification']['verified']);
        $journey = app(SetupJourney::class)->forCustomer($this->customer);
        $tracking = collect($journey['checklist'])->firstWhere('title', 'Conversion tracking');
        $this->assertFalse($tracking['done']);
        $this->assertSame('Review purchase tracking', $tracking['action_text']);
        $this->assertStringContainsString('Paid checkout tracking is connected', $tracking['detail']);
        $this->assertSame('paused', $this->strategy->campaign->fresh()->status->value);
        $this->assertSame('5.00', $this->strategy->campaign->fresh()->approved_daily_budget);
    }

    public function test_reinstalling_the_same_verified_destination_preserves_pending_receipts_and_its_cutoff(): void
    {
        $original = $this->install();
        $this->service()->recordSetupPurchase($this->owner, $this->customer, $this->checkoutSession(), now()->timestamp);
        $pending = $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending'][0];
        $this->travel(2)->minutes();
        $this->assertSame($original, $this->install());
        $this->assertSame($pending, $this->service()->forBrowser($this->owner, $this->trackingRequest())['pending'][0]);
    }

    public function test_only_fresh_exact_google_action_reporting_can_verify_a_conversion(): void
    {
        $this->install();
        $metadata = $this->strategy->execution_result;
        $health = ['status' => 'conversions_observed', 'campaign_resource' => 'customers/1234567890/campaigns/456',
            'intent' => ['action_resource' => self::ACTION], 'selected_action_activity' => ['all_conversions' => 1],
            'checked_at' => now()->toIso8601String()];
        $metadata['metadata']['conversion_tracking_health'] = $health;
        $this->strategy->update(['execution_result' => $metadata]);
        $this->assertTrue(SetupConversionGoal::conversionVerificationFor($this->strategy)['verified']);
        foreach ([['intent' => ['action_resource' => 'customers/1234567890/conversionActions/old']],
            ['campaign_resource' => 'customers/1234567890/campaigns/old'], ['checked_at' => now()->subDays(2)->toIso8601String()],
            ['selected_action_activity' => ['all_conversions' => 0]], ['status' => 'browser_invoked']] as $override) {
            $metadata['metadata']['conversion_tracking_health'] = array_replace($health, $override);
            $this->strategy->update(['execution_result' => $metadata]);
            $this->assertFalse(SetupConversionGoal::conversionVerificationFor($this->strategy)['verified']);
        }
    }

    public function test_local_goal_proof_cannot_authorize_installation_when_google_goal_or_revenue_readback_differs(): void
    {
        foreach ([['ready' => false], ['intent' => ['action_resource' => 'customers/1234567890/conversionActions/another']]] as $override) {
            $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
            $goals->method('inspect')->willReturn(array_replace(['ready' => true, 'configuration_verified' => true,
                'intent' => ['action_resource' => self::ACTION], 'campaign_resource' => 'customers/1234567890/campaigns/456'], $override));
            $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
            try {
                $this->install();
                $this->fail('Stale local configuration cannot authorize another Google goal.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('could not be verified', $e->getMessage());
            }
            $this->assertNull(Setting::get(OwnSitePurchaseTracking::SETTING));
        }
        $actions = $this->createMock(WebsiteConversionActions::class);
        $actions->method('all')->willReturn([['resource' => self::ACTION, 'objective' => 'purchase', 'always_use_default_value' => true]]);
        $this->app->bind(WebsiteConversionActions::class, fn () => $actions);
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('inspect')->willReturn(['ready' => true, 'configuration_verified' => true,
            'intent' => ['action_resource' => self::ACTION], 'campaign_resource' => 'customers/1234567890/campaigns/456']);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        try {
            $this->install();
            $this->fail('A default-only action cannot report actual receipt revenue.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be verified', $e->getMessage());
        }
        $this->assertNull(Setting::get(OwnSitePurchaseTracking::SETTING));
    }
}
