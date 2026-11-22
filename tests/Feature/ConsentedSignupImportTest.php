<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\MccAccount;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\SignupImportDestination;
use App\Services\SiteSignupConversionService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsentedSignupImportTest extends TestCase
{
    use DatabaseTransactions;

    private function registration(array $props = [], array $clicks = []): SpectraConversionEvent
    {
        Mail::fake();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
        $this->withSession(['click_ids' => $clicks])->post('/register', array_replace(['name' => 'Measurement Fixture',
            'email' => 'example.fixture+unit@gmail.com', 'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
            'ad_measurement_consent' => 1, 'ad_measurement_consent_version' => 'signup-email-v1'], $props))->assertRedirect();

        return SpectraConversionEvent::where('event', 'signup')->sole();
    }

    private function deliver(SpectraConversionEvent $record, bool $enhanced): void
    {
        $reader = $this->createMock(SignupImportDestination::class);
        $reader->method('allowsEmail')->with($record->google_conversion_resource, $record->ad_identifiers ?? [])->willReturn($enhanced);
        $this->app->instance(SignupImportDestination::class, $reader);
        $service = new DataManagerService(new MccAccount(['google_customer_id' => '987']));
        (new \ReflectionProperty($service, 'cachedToken'))->setValue($service, 'fixture-token');
        (new RecordSiteGoogleConversion($record->user()->firstOrFail(), 'signup', $record->occurred_at, $record->id))->handle($service);
    }

    public function test_consent_and_verified_email_allow_a_real_registration_without_an_ad_click_to_be_measured_once(): void
    {
        $record = $this->registration();
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
        $this->assertSame('awaiting_email_verification', $record->googleDeliveryStatus());
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'fixture-receipt'])]);
        $this->deliver($record, true);
        Http::assertNothingSent();
        $user = $record->user()->firstOrFail();
        $user->markEmailAsVerified();
        event(new Verified($user));
        event(new Verified($user));
        Queue::assertPushed(RecordSiteGoogleConversion::class, 1);
        $this->deliver($record->fresh(), true);
        $this->deliver($record->fresh(), true);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($record) {
            $event = $request['events'][0];
            $this->assertArrayNotHasKey('adIdentifiers', $event);
            $this->assertSame([['emailAddress' => hash('sha256', 'examplefixture@gmail.com')]], $event['userData']['userIdentifiers']);
            $this->assertSame($record->deduplication_key, $event['transactionId']);
            $this->assertSame(['adUserData' => 'CONSENT_GRANTED'], $event['consent']);

            return $request['destinations'][0]['productDestinationId'] === '456';
        });
        $this->assertSame('provider_accepted', $record->fresh()->googleDeliveryStatus());
        $this->assertFalse($record->fresh()->hasGoogleClickIdentifier());
        $this->assertFalse(array_key_exists('verified_source', $record->fresh()->toArray()));
    }

    public function test_unverified_email_with_both_braids_sends_only_the_web_braid_and_never_a_second_conversion(): void
    {
        $record = $this->registration(clicks: ['gclid' => '{gclid}', 'gbraid' => 'app-braid', 'wbraid' => 'web-braid']);
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'fixture-receipt'])]);
        $this->deliver($record, true);
        Http::assertSent(fn ($req) => $req['events'][0]['adIdentifiers'] === ['wbraid' => 'web-braid'] && ! isset($req['events'][0]['userData']));
        $user = $record->user()->firstOrFail();
        $user->markEmailAsVerified();
        event(new Verified($user));
        Queue::assertPushed(RecordSiteGoogleConversion::class, 1);
    }

    public static function consentChoices(): array
    {
        return [[0], [null]];
    }

    #[DataProvider('consentChoices')]
    public function test_later_consent_never_authorizes_an_earlier_unconsented_signup(?int $choice): void
    {
        $record = $this->registration(['ad_measurement_consent' => $choice], ['gclid' => 'fixture-click']);
        $user = $record->user()->firstOrFail();
        $user->update(['ad_measurement_consent' => true]);
        $user->markEmailAsVerified();
        event(new Verified($user));
        $this->deliver($record, true);
        Http::assertNothingSent();
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
        $this->assertSame('consent_not_granted', $record->fresh()->googleDeliveryStatus());
    }

    public function test_email_alone_cannot_be_uploaded_if_google_has_disabled_enhanced_matching(): void
    {
        $record = $this->registration();
        $user = $record->user()->firstOrFail();
        $user->markEmailAsVerified();
        event(new Verified($user));
        try {
            $this->deliver($record->fresh(), false);
            $this->fail('An unavailable enhanced conversion destination must block the upload.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('eligible enhanced-conversion', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame('upload_failed', $record->fresh()->googleDeliveryStatus());
    }

    public function test_verification_survives_dispatch_outage_and_recovers_the_original_receipt(): void
    {
        $receipt = $this->registration();
        $user = $receipt->user()->firstOrFail();
        $user->markEmailAsVerified();
        $service = app(SiteSignupConversionService::class);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        $service->emailVerified($user);
        $this->assertNotEmpty($receipt->fresh()->verified_source['email_verified_at']);
        $this->assertStringContainsString('Queue dispatch failed', $receipt->fresh()->upload_error);
        $this->travel(2)->hours();
        Bus::fake();
        $service->retryPendingDispatches();
        Bus::assertDispatched(RecordSiteGoogleConversion::class, 1);
        $this->assertDatabaseCount('spectra_conversion_events', 1);
    }

    public function test_get_google_login_cannot_opt_in_and_an_unverified_oauth_identity_cannot_create_a_signup(): void
    {
        $social = $this->createMock(\Laravel\Socialite\Two\GoogleProvider::class);
        $social->expects($this->exactly(2))->method('redirect')->willReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        $social->expects($this->once())->method('user')->willReturn((new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => false])
            ->map(['email' => 'fixture@example.test', 'name' => 'fixture']));
        Socialite::shouldReceive('driver')->with('google')->andReturn($social);
        $this->post(route('register.google.redirect'), ['ad_measurement_consent' => 1, 'ad_measurement_consent_version' => 'signup-email-v1'])
            ->assertRedirect()->assertSessionHas('oauth_signup_measurement.consent', true);
        $this->get('/auth/google/redirect?ad_measurement_consent=1&ad_measurement_consent_version=signup-email-v1')
            ->assertRedirect()->assertSessionMissing('oauth_signup_measurement');
        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'fixture@example.test']);
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_consenting_oauth_signup_uses_real_clicks_only_for_google_and_meta(): void
    {
        Mail::fake();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
        config(['services.facebook.spectra_pixel_id' => '222', 'services.facebook.system_user_token' => 'fixture-token']);
        $social = $this->createMock(\Laravel\Socialite\Two\GoogleProvider::class);
        $social->method('user')->willReturn((new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])
            ->map(['email' => 'oauth-only@example.test', 'name' => 'OAuth-only fixture']));
        Socialite::shouldReceive('driver')->with('google')->andReturn($social);
        $this->withSession(['oauth_signup_measurement' => ['consent' => true, 'version' => 'signup-email-v1'],
            'click_ids' => ['gclid' => 'fixture-g', 'fbclid' => 'fixture-f']])->get('/auth/google/callback')->assertRedirect();
        $receipt = SpectraConversionEvent::where('event', 'signup')->sole();
        $this->assertFalse($receipt->hasSignupEmailMatch());
        $user = $receipt->user()->firstOrFail();
        $this->assertNull(app(SiteSignupConversionService::class)->verifiedFormEmail($user));
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'fixture-receipt']),
            'graph.facebook.com/*/222/events' => Http::response(['events_received' => 1, 'fbtrace_id' => 'fixture-meta'])]);
        $this->deliver($receipt, true);
        (new \App\Jobs\RecordSiteFacebookConversion($user, 'signup', 'https://sitetospend.com/register', 'Fixture/1', $receipt->id))->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => isset($request['events']) && $request['events'][0]['adIdentifiers'] === ['gclid' => 'fixture-g'] && ! isset($request['events'][0]['userData']));
        Http::assertSent(fn ($request) => isset($request['data']) && ! isset(json_decode($request['data'], true)[0]['user_data']['em']));
    }
}
