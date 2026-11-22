<?php

namespace Tests\Feature;

use App\Jobs\DeliverCampaignOutcome;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\FacebookAds\ConversionsApiService;
use App\Services\FacebookAds\PixelService;
use App\Services\GoogleAds\ConversionTrackingService;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignOutcomeEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(string $platform, bool $verifiedSite = true): Strategy
    {
        $customer = Customer::factory()->create(['website' => 'https://example.org', 'facebook_ads_account_id' => 'act_131', 'facebook_pixel_id' => '222', 'google_ads_customer_id' => '131']);
        if ($verifiedSite) {
            $file = app(\App\Services\Conversions\AdvertiserOutcomeOrigin::class)->file($customer, 'https://example.org');
            $customer->forceFill(['verified_outcome_origin' => hash('sha256', $file['contents']), 'outcome_origin_verified_at' => now()])->save();
        }
        $user = User::factory()->create();
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => $platform,
            'conversion_goals' => ['primary_goal' => 'Signup', 'action_resource' => 'customers/131/conversionActions/42']]);
        $strategy->forceFill(['conversion_webhook_secret' => 'qa-backend-secret'])->save();

        return $strategy;
    }

    private function postEvent(Strategy $strategy, array $values, string $secret = 'qa-backend-secret')
    {
        $body = json_encode(array_merge(['source_id' => 'registration-123', 'mode' => 'test', 'occurred_at' => now()->toIso8601String(),
            'source_url' => 'https://example.org/confirmed', 'goal' => 'signup', 'ad_user_data' => 'granted'], $values), JSON_THROW_ON_ERROR);
        $stamp = (string) now()->timestamp;

        return $this->call('POST', route('outcomes.record', $strategy->uuid), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_OUTCOME_TIMESTAMP' => $stamp, 'HTTP_X_OUTCOME_SIGNATURE' => hash_hmac('sha256', $stamp.'.'.$body, $secret)], $body);
    }

    private function wire(Strategy $strategy, int $id): void
    {
        (new DeliverCampaignOutcome($id))->handle(app(CampaignOutcomeEvidence::class), app(DataManagerService::class));
    }

    public function test_meta_exact_event_receipt_isolated_from_live_counters_and_invalidated_by_a_new_dataset(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook');
        $service = app(CampaignOutcomeEvidence::class);
        $this->assertFalse($service->forStrategy($strategy)['verified_for_launch']);
        $nonce = $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertOk()->json('challenge');
        $values = ['challenge' => $nonce, 'fbc' => 'fb.1.1780000000000.valid', 'test_code' => 'TEST123', 'client_user_agent' => 'Mozilla/5.0 (Website QA browser)'];
        $this->postEvent($strategy, $values, 'wrong')->assertForbidden();
        $this->postEvent($strategy, $values + ['source_url' => 'https://foreign.example/signup'])->assertUnprocessable();
        $id = $this->postEvent($strategy, $values)->assertAccepted()->json('event_id');
        $this->assertSame($id, $this->postEvent($strategy, $values)->assertAccepted()->json('event_id'));
        $pixelService = $this->createMock(PixelService::class);
        $this->app->bind(PixelService::class, fn () => $pixelService);
        $pixelService->expects($this->once())->method('isLinked')->with('222', '131')->willReturn(true);
        $capi = $this->createMock(ConversionsApiService::class);
        $this->app->bind(ConversionsApiService::class, fn () => $capi);
        $capi->expects($this->once())->method('sendEvents')->with('222', $this->callback(fn ($events) => $events[0]['event_id'] === 'registration-123' && $events[0]['event_name'] === 'CompleteRegistration'), 'TEST123')->willReturn(['events_received' => 1, 'fbtrace_id' => 'trace']);
        $this->wire($strategy, $id);
        $this->assertTrue($service->forStrategy($strategy)['verified_for_launch']);
        $test = DB::table('campaign_outcome_events')->where('id', $id)->first();
        $this->assertSame('granted', $test->ad_user_data);
        $this->assertNull($test->ad_personalization);
        $this->assertNull($test->payload);
        $this->assertSame('registration-123', Crypt::decryptString($test->provider_event_id));
        $this->assertSame(0, $service->forStrategy($strategy)['verified_events']);
        $strategy->campaign->customer->update(['facebook_pixel_id' => '223']);
        $this->assertFalse($service->forStrategy($strategy->fresh())['verified_for_launch']);
    }

    public function test_google_requires_a_new_exact_tag_diagnostic_tied_to_the_signed_site_action(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Google');
        $service = $this->createMock(ConversionTrackingService::class);
        $this->app->bind(ConversionTrackingService::class, fn () => $service);
        $diagnostic = ['resource' => 'customers/131/conversionActions/42', 'type' => 'WEBPAGE', 'category' => 'SIGNUP', 'send_to' => 'AW-131/abc', 'time_zone' => 'UTC'];
        $service->expects($this->exactly(3))->method('outcomeDestination')->with('131', 'customers/131/conversionActions/42')->willReturn(
            $diagnostic + ['last_tag_at' => now()->subDay()->utc()->toDateTimeString()],
            $diagnostic + ['last_tag_at' => now()->subDay()->utc()->toDateTimeString()],
            $diagnostic + ['last_tag_at' => now()->addSeconds(7)->utc()->toDateTimeString()]);
        $nonce = $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertOk()->json('challenge');
        $id = $this->postEvent($strategy, ['challenge' => $nonce, 'tag_send_to' => 'AW-131/abc',
            'tag_seen_at' => now()->toIso8601String(), 'tag_event_id' => 'registration-123'])->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($strategy)['verified_for_launch']);
        $capture = ['website_origin' => 'https://example.org', 'challenge' => $nonce, 'send_to' => 'AW-131/abc', 'goal' => 'signup',
            'event_id' => 'registration-123', 'observed_at' => now()->toIso8601String()];
        $endpoint = route('campaigns.outcome.google-tag', [$strategy->campaign, $strategy->uuid]);
        $this->postJson($endpoint, array_merge($capture, ['website_origin' => 'https://wrong.example']))->assertForbidden();
        $this->postJson($endpoint, array_merge($capture, ['send_to' => 'AW-131/other']))->assertConflict();
        $this->postJson($endpoint, $capture)->assertNoContent();
        $this->postJson($endpoint, array_merge($capture, ['event_id' => 'other-registration']))->assertConflict();
        $this->postJson($endpoint, $capture)->assertNoContent();
        $this->travel(10)->seconds();
        $this->wire($strategy, $id);
        $this->assertStringStartsWith('google-tag-activity:', DB::table('campaign_outcome_events')->where('id', $id)->value('provider_receipt'));
        $evidence = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);
        $this->assertTrue($evidence['verified_for_launch']);
        $this->assertSame('unverified', $evidence['provider_attribution']);
        $this->assertSame(0, $evidence['verified_events']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['78.25', 'AUD', 10, true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['78.24', 'AUD', 10, false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['78.25', 'USD', 10, false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['78.25', 'AUD', 95, false])]
    public function test_google_purchase_challenge_matches_paid_order_and_bounded_provider_activity(string $amount, string $currency, int $lag, bool $ready): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Google');
        $strategy->update(['conversion_goals' => ['primary_goal' => 'Purchase', 'action_resource' => 'customers/131/conversionActions/42']]);
        $reader = $this->createMock(ConversionTrackingService::class);
        $this->app->bind(ConversionTrackingService::class, fn () => $reader);
        $diagnostic = ['resource' => 'customers/131/conversionActions/42', 'type' => 'WEBPAGE', 'category' => 'PURCHASE', 'send_to' => 'AW-131/order', 'time_zone' => 'UTC'];
        $reader->method('outcomeDestination')->willReturn($diagnostic + ['last_tag_at' => null], $diagnostic + ['last_tag_at' => now()->addSeconds($lag)->utc()->toDateTimeString()]);
        $test = $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertOk()->json();
        $this->postJson($test['capture_url'], ['website_origin' => 'https://example.org', 'challenge' => $test['challenge'], 'send_to' => 'AW-131/order',
            'goal' => 'purchase', 'event_id' => 'order-123', 'amount' => $amount, 'currency' => $currency, 'observed_at' => now()->toIso8601String()])->assertNoContent();
        $id = $this->postEvent($strategy, ['goal' => 'purchase', 'challenge' => $test['challenge'], 'order_id' => 'order-123', 'source_id' => 'webhook-456',
            'amount' => '78.25', 'currency' => 'AUD'])->assertAccepted()->json('event_id');
        $this->travel($lag)->seconds();
        $this->wire($strategy, $id);
        $evidence = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);
        $this->assertSame($ready, $evidence['verified_for_launch']);
        $this->assertSame('unverified', $evidence['provider_attribution']);
    }

    public function test_a_paid_order_requires_positive_value_and_denied_consent_never_sends(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook');
        $strategy->update(['conversion_goals' => ['primary_goal' => 'Purchase']]);
        $this->postEvent($strategy, ['goal' => 'purchase', 'mode' => 'live', 'amount' => '0.00', 'currency' => 'USD'])->assertUnprocessable();
        $id = $this->postEvent($strategy, ['goal' => 'purchase', 'mode' => 'live', 'amount' => '24.90', 'currency' => 'AUD', 'ad_user_data' => 'denied'])->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);
        $this->assertSame('consent_unavailable', DB::table('campaign_outcome_events')->where('id', $id)->value('delivery_status'));
        $this->assertEquals(['AUD' => '24.90'], app(CampaignOutcomeEvidence::class)->forStrategy($strategy)['verified_value_by_currency']);
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($strategy)['verified_for_launch']);
    }

    public function test_the_signed_source_url_cannot_prove_site_control_and_redirects_cannot_delegate_it(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook', verifiedSite: false);
        $setup = $this->postJson(route('campaigns.outcome.setup', [$strategy->campaign, $strategy->uuid]))->assertOk()->json('website_file');
        $this->assertStringStartsWith('https://example.org/.well-known/', $setup['url']);
        $this->app->instance(\App\Services\Crawling\PublicWebsiteFetcher::class, new class extends \App\Services\Crawling\PublicWebsiteFetcher
        {
            protected function addresses(string $url): array
            {
                return ['93.184.216.34'];
            }
        });
        \Illuminate\Support\Facades\Http::fake([$setup['url'] => \Illuminate\Support\Facades\Http::sequence()
            ->push('missing', 404)->push('', 302, ['Location' => 'https://elsewhere.example/proof'])->push($setup['contents'])]);
        $this->postEvent($strategy, ['mode' => 'live'])->assertUnprocessable();
        $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertUnprocessable();
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($strategy->fresh())['website_origin_verified']);
        $this->assertDatabaseCount('campaign_outcome_events', 0);
        $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertOk();
        $this->assertTrue(app(CampaignOutcomeEvidence::class)->forStrategy($strategy->fresh())['website_origin_verified']);
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($strategy->fresh())['verified_for_launch']);
        \Illuminate\Support\Facades\Http::assertSentCount(3);
        $strategy->campaign->customer->update(['website' => 'https://other.example']);
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($strategy->fresh())['website_origin_verified']);
    }

    public function test_owned_site_replay_requires_consent_at_the_original_registration_and_at_verification(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook', false);
        $customer = $strategy->campaign->customer;
        $customer->update(['website' => 'https://sitetospend.com']);
        config(['conversions.advertiser_customer_uuid' => $customer->uuid, 'services.facebook.spectra_pixel_id' => '222']);
        $user = User::factory()->create(['tenant_key' => 'sitetospend', 'ad_measurement_consent' => true, 'created_at' => now()->subHour()]);
        $receipt = app(\App\Services\SiteSignupConversionService::class)->record($user);
        $source = $receipt->verified_source;
        $this->assertSame(true, $source['consent']);
        $receipt->update(['verified_source' => null, 'meta_delivery' => ['status' => 'accepted', 'dataset' => '222',
            'event' => 'CompleteRegistration', 'events_received' => 1, 'trace' => 'signup-trace', 'event_id' => hash('sha256', $receipt->deduplication_key)]]);
        $native = app(\App\Services\Conversions\NativeOutcomeVerification::class);
        $evidence = app(CampaignOutcomeEvidence::class);
        $binding = $evidence->binding($strategy);
        $this->assertFalse($native->replay($strategy, $binding));
        $receipt->update(['verified_source' => array_replace($source, ['consent' => false])]);
        $this->assertFalse($native->replay($strategy, $binding));
        $receipt->update(['verified_source' => $source]);
        $user->update(['ad_measurement_consent' => false]);
        $this->assertFalse($native->replay($strategy, $binding));
        $user->update(['ad_measurement_consent' => true]);
        $pixel = $this->createMock(PixelService::class);
        $pixel->expects($this->once())->method('isLinked')->with('222', '131')->willReturn(true);
        $this->app->bind(PixelService::class, fn () => $pixel);
        $this->assertTrue($native->owns($strategy, $binding), 'Tenant, destination and domain match');
        $this->assertTrue($native->replay($strategy, $binding));
        $audit = DB::table('campaign_outcome_events')->where('strategy_id', $strategy->id)->where('source', 'native_receipt_replay')->sole();
        $this->assertSame('granted', $audit->ad_user_data);
        $this->assertNull($audit->ad_personalization);
        $this->assertSame($receipt->meta_delivery['event_id'], Crypt::decryptString($audit->provider_event_id));
        $this->assertSame($receipt->created_at->timestamp, \Illuminate\Support\Carbon::parse($audit->consent_observed_at)->timestamp);
        $this->assertTrue($evidence->forStrategy($strategy)['verified_for_launch']);
        $this->assertSame(0, $evidence->forStrategy($strategy)['verified_events']);
    }

    public function test_owned_site_purchase_replay_requires_live_stripe_identity_and_matching_paid_amount_and_currency(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook', false);
        $strategy->update(['conversion_goals' => ['primary_goal' => 'Purchase']]);
        $customer = $strategy->campaign->customer;
        $customer->update(['website' => 'https://sitetospend.com']);
        config(['conversions.advertiser_customer_uuid' => $customer->uuid, 'services.facebook.spectra_pixel_id' => '222']);
        $user = $customer->users()->firstOrFail();
        $user->forceFill(['stripe_id' => 'cus_QA123', 'tenant_key' => 'sitetospend', 'ad_measurement_consent' => true])->save();
        $paidAt = now()->subMinutes(10)->timestamp;
        $session = ['id' => 'cs_live_QA123', 'livemode' => true, 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => 2400, 'currency' => 'usd', 'created' => $paidAt - 10, 'customer' => $user->stripe_id,
            'client_reference_id' => 'setup-fee-'.$customer->id, 'metadata' => ['purpose' => 'setup_fee', 'customer_id' => (string) $customer->id, 'user_id' => (string) $user->id]];
        $receipt = app(\App\Services\OwnSitePurchaseTracking::class)->recordSetupPurchase($user, $customer, $session, $paidAt);
        $this->assertNotNull($receipt);
        $source = $receipt->verified_source;
        $this->assertSame(2400, $source['paid_cents']);
        $receipt->update(['meta_delivery' => ['status' => 'accepted', 'dataset' => '222', 'event' => 'Purchase', 'events_received' => 1,
            'trace' => 'purchase-trace', 'event_id' => hash('sha256', $receipt->deduplication_key)]]);
        $native = app(\App\Services\Conversions\NativeOutcomeVerification::class);
        $evidence = app(CampaignOutcomeEvidence::class);
        $binding = $evidence->binding($strategy);
        foreach ([['consent' => false], ['id' => 'configured-value'], ['transaction' => 'cs_live_wrong'], ['currency' => null], ['paid_cents' => 25], ['livemode' => false]] as $invalid) {
            $receipt->update(['verified_source' => array_replace($source, $invalid)]);
            $this->assertFalse($native->replay($strategy, $binding));
        }
        $receipt->update(['verified_source' => $source]);
        $pixel = $this->createMock(PixelService::class);
        $pixel->expects($this->once())->method('isLinked')->with('222', '131')->willReturn(true);
        $this->app->bind(PixelService::class, fn () => $pixel);
        $this->assertTrue($native->owns($strategy, $binding), 'Tenant, destination and domain match');
        $this->assertTrue($native->replay($strategy, $binding));
        $this->assertSame(0, $evidence->forStrategy($strategy)['verified_events']);
    }

    public function test_original_consent_survives_a_terminal_purge_and_a_changed_duplicate(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Facebook');
        $values = ['mode' => 'live', 'source_id' => 'checkout-confirmed', 'occurred_at' => now()->subMinutes(3)->toIso8601String(),
            'ad_user_data' => 'denied', 'ad_personalization' => 'unknown'];
        $id = $this->postEvent($strategy, $values)->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);
        $rows = DB::table('campaign_outcome_events')->where('id', $id);
        $first = $rows->first();
        $this->assertSame('consent_unavailable', $first->delivery_status);
        $this->assertNull($first->payload);
        $this->assertSame('denied', $first->ad_user_data);
        $this->assertSame('unknown', $first->ad_personalization);
        $this->assertSame(now()->timestamp, \Illuminate\Support\Carbon::parse($first->consent_observed_at)->timestamp);
        $this->assertSame('checkout-confirmed', Crypt::decryptString($first->provider_event_id));
        $this->travel(1)->minutes();
        $this->assertSame($id, $this->postEvent($strategy, array_replace($values, ['ad_user_data' => 'granted', 'ad_personalization' => 'granted']))->assertAccepted()->json('event_id'));
        $this->assertEquals($first, $rows->first());
    }

    public function test_signed_customer_signup_with_a_google_click_id_and_both_braids_sends_only_the_click_id(): void
    {
        $strategy = $this->fixture('Google');
        $tracking = $this->createMock(ConversionTrackingService::class);
        $tracking->expects($this->once())->method('outcomeDestination')->with('131', 'customers/131/conversionActions/42')
            ->willReturn(['category' => 'SIGNUP', 'type' => 'UPLOAD_CLICKS']);
        $this->app->bind(ConversionTrackingService::class, fn () => $tracking);
        $google = $this->createMock(DataManagerService::class);
        $google->expects($this->once())->method('ingestConversion')->with('131', '42', ['gclid' => 'fixture-google-click'])
            ->willReturn(['success' => true, 'requestId' => 'fixture-upload']);
        $this->app->instance(DataManagerService::class, $google);

        $id = $this->postEvent($strategy, ['mode' => 'live', 'gclid' => 'fixture-google-click', 'gbraid' => 'fixture-app-braid', 'wbraid' => 'fixture-web-braid'])
            ->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);

        $this->assertDatabaseHas('campaign_outcome_events', ['id' => $id, 'delivery_status' => 'accepted', 'provider_receipt' => 'fixture-upload']);
    }

    public function test_failed_google_poll_retries_the_original_receipt_without_raw_identifiers_or_another_upload(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Google');
        $service = $this->createMock(ConversionTrackingService::class);
        $this->app->bind(ConversionTrackingService::class, fn () => $service);
        $service->expects($this->once())->method('outcomeDestination')->willReturn(['category' => 'SIGNUP', 'type' => 'UPLOAD_CLICKS']);
        $google = $this->createMock(DataManagerService::class);
        $google->expects($this->once())->method('ingestConversion')->with('131', '42', ['wbraid' => 'qa-web'])->willReturn(['success' => true, 'requestId' => 'upload-1']);
        $google->expects($this->exactly(2))->method('retrieveRequestStatus')->with('upload-1')->willReturn(
            ['success' => false],
            ['success' => true, 'destinations' => [['destination' => ['operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => '131'], 'productDestinationId' => '42'],
                'requestStatus' => 'SUCCESS', 'eventsIngestionStatus' => ['recordCount' => 1]]]]);
        $this->app->instance(DataManagerService::class, $google);
        $values = ['mode' => 'live', 'source_id' => 'business123', 'gbraid' => 'qa-app', 'wbraid' => 'qa-web', 'ad_personalization' => 'denied'];
        $id = $this->postEvent($strategy, $values)->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);
        $rows = DB::table('campaign_outcome_events')->where('id', $id);
        $this->assertNull($rows->value('payload'));
        try {
            $this->wire($strategy, $id);
            $this->fail('The failed status lookup should be retried.');
        } catch (\RuntimeException $e) {
            (new DeliverCampaignOutcome($id))->failed($e);
        }
        $this->assertSame('failed', $rows->value('delivery_status'));
        $this->assertSame('upload-1', $rows->value('provider_receipt'));
        $this->assertSame($id, $this->postEvent($strategy, $values)->assertAccepted()->json('event_id'));
        $this->wire($strategy, $id);
        $row = $rows->first();
        $this->assertSame('processed', $row->delivery_status);
        $this->assertSame('upload-1', $row->provider_receipt);
        $this->assertSame(hash('sha256', 'business123'), Crypt::decryptString($row->provider_event_id));
        $this->assertSame('granted', $row->ad_user_data);
        $this->assertSame('denied', $row->ad_personalization);
        $this->assertNull($row->payload);
    }

    public function test_spent_retries_can_restore_only_the_exact_original_signed_payload(): void
    {
        $this->freezeSecond();
        $strategy = $this->fixture('Google');
        $values = ['mode' => 'live', 'gclid' => 'original-click'];
        $id = $this->postEvent($strategy, $values)->assertAccepted()->json('event_id');
        $rows = DB::table('campaign_outcome_events')->where('id', $id);
        (new DeliverCampaignOutcome($id))->failed(new \RuntimeException('Temporary provider outage'));
        $original = $rows->first();
        $this->assertSame('failed', $original->delivery_status);
        $this->assertNull($original->payload);
        $this->assertNotEmpty($original->retry_payload_hash);
        $this->postEvent($strategy, array_replace($values, ['gclid' => 'different-click']))->assertConflict();
        $this->postEvent($strategy, array_replace($values, ['ad_user_data' => 'unknown']))->assertConflict();
        $this->assertNull($rows->value('payload'));
        $this->assertSame($id, $this->postEvent($strategy, $values)->assertAccepted()->json('event_id'));
        $restored = $rows->first();
        $this->assertSame($original->consent_observed_at, $restored->consent_observed_at);
        $this->assertSame('original-click', json_decode(Crypt::decryptString($restored->payload), true)['gclid']);
        $this->assertNull($restored->ad_personalization);
    }
}
