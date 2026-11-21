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
        $values = ['challenge' => $nonce, 'fbc' => 'fb.1.1780000000000.valid', 'test_code' => 'TEST123'];
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
        $service->expects($this->exactly(2))->method('outcomeDestination')->with('131', 'customers/131/conversionActions/42')->willReturn(
            $diagnostic + ['last_tag_at' => now()->subDay()->utc()->toDateTimeString()],
            $diagnostic + ['last_tag_at' => now()->utc()->toDateTimeString()]);
        $nonce = $this->postJson(route('campaigns.outcome.test', [$strategy->campaign, $strategy->uuid]))->assertOk()->json('challenge');
        $id = $this->postEvent($strategy, ['challenge' => $nonce, 'tag_send_to' => 'AW-131/abc',
            'tag_seen_at' => now()->toIso8601String(), 'tag_event_id' => 'registration-123'])->assertAccepted()->json('event_id');
        $this->wire($strategy, $id);
        $evidence = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);
        $this->assertTrue($evidence['verified_for_launch']);
        $this->assertSame('unverified', $evidence['provider_attribution']);
        $this->assertSame(0, $evidence['verified_events']);
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
}
