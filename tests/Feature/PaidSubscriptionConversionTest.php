<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\MccAccount;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use App\Services\PaidSubscriptionConversionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaidSubscriptionConversionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['cashier.webhook.secret' => 'whsec_local_fixture']);
        Plan::create([
            'name' => 'Subscription fixture', 'slug' => 'conversion-fixture',
            'price_cents' => 25000, 'stripe_price_id' => 'price_fixture',
            'is_free' => false, 'billing_interval' => 'month',
        ]);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/123/conversionActions/789');
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
    }

    private function invoice(array $overrides = [], string $type = 'invoice.paid'): array
    {
        return [
            'id' => 'evt_fixture', 'type' => $type, 'livemode' => false,
            'data' => ['object' => array_replace([
                'id' => 'in_fixture', 'customer' => 'cus_fixture',
                'subscription' => 'sub_fixture', 'billing_reason' => 'subscription_create',
                'status' => 'paid', 'amount_paid' => 25037, 'currency' => 'aud',
                'number' => 'FIXTURE-1',
                'status_transitions' => ['paid_at' => now()->subMinute()->timestamp],
                'lines' => ['data' => [['price' => ['id' => 'price_fixture']]]],
            ], $overrides)],
        ];
    }

    private function postSigned(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_local_fixture');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    public function test_signup_to_signed_payment_upload_preserves_click_value_time_and_deduplicates(): void
    {
        $this->withSession(['click_ids' => ['wbraid' => 'fixture-braid']])
            ->post('/register', [
                'name' => 'Conversion fixture', 'email' => 'conversion-fixture@example.com',
                'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
            ])->assertRedirect();
        $user = User::where('email', 'conversion-fixture@example.com')->firstOrFail();
        $user->forceFill(['stripe_id' => 'cus_fixture'])->save();
        Queue::assertPushed(RecordSiteGoogleConversion::class);

        $payload = $this->invoice();
        $this->postSigned($payload)->assertSuccessful();
        $this->postSigned($payload)->assertSuccessful();
        $payload['type'] = 'invoice.payment_succeeded';
        $this->postSigned($payload)->assertSuccessful();

        $row = SpectraConversionEvent::where('event', 'paid_subscription')->sole();
        $this->assertSame('250.37', $row->value);
        $this->assertSame('AUD', $row->currency);
        $this->assertSame(['wbraid' => 'fixture-braid'], $row->ad_identifiers);

        $dm = $this->dataManager();
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'request-fixture'])]);
        $job = new RecordSiteGoogleConversion($user, 'paid_subscription', $row->occurred_at, $row->id);
        $job->handle($dm);
        $job->handle($dm);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($payload) {
            $event = $request['events'][0];
            $this->assertSame('789', $request['destinations'][0]['productDestinationId']);
            $this->assertSame(['wbraid' => 'fixture-braid'], $event['adIdentifiers']);
            $this->assertSame(250.37, $event['conversionValue']);
            $this->assertSame('AUD', $event['currency']);
            $this->assertSame($payload['data']['object']['status_transitions']['paid_at'], strtotime($event['eventTimestamp']));
            $this->assertSame('paid_subscription:sub_fixture', $event['transactionId']);

            return true;
        });

        $this->assertTrue($row->fresh()->uploaded_to_google);
        $this->assertSame('request-fixture', $row->fresh()->google_request_id);
    }

    public function test_modern_invoice_subscription_and_price_shape_is_supported(): void
    {
        User::factory()->create(['stripe_id' => 'cus_fixture', 'gclid' => 'fixture-click']);
        $this->postSigned($this->invoice([
            'subscription' => null,
            'parent' => ['subscription_details' => ['subscription' => 'sub_modern']],
            'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_fixture']]]]],
        ]))->assertSuccessful();
        $this->assertDatabaseHas('spectra_conversion_events', ['deduplication_key' => 'paid_subscription:sub_modern']);
        Queue::assertPushed(RecordSiteGoogleConversion::class);
    }

    public function test_organic_payment_is_recorded_without_inventing_ad_attribution(): void
    {
        User::factory()->create(['stripe_id' => 'cus_fixture']);
        $this->postSigned($this->invoice())->assertSuccessful();
        $this->assertDatabaseHas('spectra_conversion_events', [
            'event' => 'paid_subscription', 'gclid' => null, 'uploaded_to_google' => false,
        ]);
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
    }

    public function test_renewals_free_invoices_setup_fees_and_unrelated_products_do_not_count(): void
    {
        User::factory()->create(['stripe_id' => 'cus_fixture', 'gclid' => 'fixture-click']);
        foreach ([
            ['billing_reason' => 'subscription_cycle'],
            ['billing_reason' => 'subscription_update'],
            ['amount_paid' => 0],
            ['status' => 'open'],
            ['subscription' => null],
            ['lines' => ['data' => [['price' => ['id' => 'price_ad_spend']]]]],
        ] as $overrides) {
            $this->postSigned($this->invoice($overrides))->assertSuccessful();
        }
        $this->assertDatabaseCount('spectra_conversion_events', 0);
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
    }

    public function test_unsigned_webhook_and_browser_forged_purchase_are_rejected(): void
    {
        $this->postJson('/api/stripe/webhook', $this->invoice())->assertForbidden();
        $this->postJson('/spectra/conversion', ['event' => 'paid_subscription'])->assertStatus(422);
        $this->postJson('/spectra/conversion', ['event' => 'signup'])->assertStatus(422);
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_test_mode_payments_cannot_enter_production_tracking(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        app(PaidSubscriptionConversionService::class)->record($this->invoice());
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_failed_upload_retries_the_same_record_timestamp_and_transaction_id(): void
    {
        $user = User::factory()->create(['gclid' => 'fixture-click']);
        $dm = $this->dataManager();
        Http::fake(['datamanager.googleapis.com/*' => Http::sequence()
            ->push(['error' => 'Temporary provider failure'], 503)
            ->push(['requestId' => 'retry-request'])]);
        $job = new RecordSiteGoogleConversion($user, 'signup');
        try {
            $job->handle($dm);
            $this->fail('A rejected upload must fail the job so the queue retries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Temporary provider failure', $e->getMessage());
        }
        $record = SpectraConversionEvent::sole();
        $this->assertFalse($record->uploaded_to_google);
        $this->assertNotNull($record->upload_error);

        $job->handle($dm);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($record->deduplication_key, $request['events'][0]['transactionId']);
            $this->assertSame($record->occurred_at->timestamp, strtotime($request['events'][0]['eventTimestamp']));
        }
        $this->assertDatabaseCount('spectra_conversion_events', 1);
        $this->assertTrue($record->fresh()->uploaded_to_google);
        $this->assertNull($record->fresh()->upload_error);
    }

    public function test_missing_action_is_retryable_and_saved_for_diagnosis(): void
    {
        Setting::where('key', 'conversion_resource_name.signup_import')->delete();
        Cache::flush();
        $user = User::factory()->create(['gclid' => 'fixture-click']);
        $dm = $this->dataManager();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not provisioned');
        (new RecordSiteGoogleConversion($user, 'signup'))->handle($dm);
    }

    public function test_data_manager_sends_transaction_id_and_keeps_validation_a_dry_run(): void
    {
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'request-fixture'])]);
        $service = $this->dataManager();
        $result = $service->ingestConversion(
            '123', '789', ['gclid' => 'fixture-click'], 250.37, 'AUD', now(),
            validateOnly: true, transactionId: 'paid_subscription:sub_fixture',
        );
        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request['validateOnly'] === true
            && $request['events'][0]['transactionId'] === 'paid_subscription:sub_fixture'
            && $request['events'][0]['conversionValue'] === 250.37
            && $request['destinations'][0]['operatingAccount']['accountId'] === '123');
    }

    private function dataManager(): DataManagerService
    {
        $service = new DataManagerService(new MccAccount(['google_customer_id' => '987']));
        (new \ReflectionProperty($service, 'cachedToken'))->setValue($service, 'test-token');

        return $service;
    }
}
