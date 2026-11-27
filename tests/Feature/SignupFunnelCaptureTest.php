<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SignupFunnelCaptureTest extends TestCase
{
    use DatabaseTransactions;

    private const SITE = 'https://sitetospend.com';

    private const PAID = '/?utm_source=google&utm_medium=performance_max&utm_campaign=spectra_9&utm_content=strategy_11';

    private function visit(string $path): TestResponse
    {
        $res = $this->get(self::SITE.$path);
        $session = $res->getCookie(config('session.cookie'), false);
        if ($session) {
            $this->withUnencryptedCookie(config('session.cookie'), $session->getValue());
        }

        return $res;
    }

    private function currentJourneyId(): string
    {
        $id = session('sitetospend_signup_journey');
        $this->assertIsString($id);

        return $id;
    }

    private function stage(string $stage, array $other = []): TestResponse
    {
        return $this->withCredentials()->postJson(self::SITE.'/funnel/stages', ['stage' => $stage, ...$other]);
    }

    public function test_campaign_survives_navigation_and_reloads_and_no_untrusted_dimensions_are_retained(): void
    {
        $this->visit(self::PAID.'&utm_term=someone%40private.example')->assertOk();
        $this->stage('landing_visible')->assertNoContent();
        $this->stage('landing_visible')->assertNoContent();
        $this->stage('preview_started')->assertNoContent();
        $this->stage('preview_failed', ['reason' => 'timeout'])->assertNoContent();
        $this->stage('preview_failed', ['reason' => 'unavailable'])->assertNoContent();
        $this->visit('/google-ads-management?utm_source=facebook&utm_medium=social&utm_campaign=spectra_28')->assertOk();
        $this->visit('/register')->assertOk();
        $cohort = DB::table('signup_funnel_journeys')->where('id', $this->currentJourneyId())->sole();
        $this->assertSame('google', $cohort->source);
        $this->assertSame('spectra_9', $cohort->campaign_key);
        $this->assertSame('home_preview', $cohort->entry_surface);
        $events = DB::table('signup_funnel_stages')->where('journey_id', $cohort->id)->pluck('reason', 'stage')->all();
        $this->assertEqualsCanonicalizing(['landing_view', 'landing_visible', 'preview_started', 'preview_failed', 'signup_viewed'], array_keys($events));
        $this->assertSame('timeout', $events['preview_failed']);
        Http::assertNothingSent();
    }

    public function test_endpoint_requires_a_real_landing_and_rejects_fabricated_server_stages_private_fields_and_cross_site_requests(): void
    {
        $originalCount = DB::table('signup_funnel_journeys')->count();
        $this->stage('landing_visible')->assertNoContent();
        $this->assertDatabaseCount('signup_funnel_journeys', $originalCount);
        $this->visit('/register?utm_source=google&utm_medium=cpc&utm_campaign=abc%40private.example')->assertOk();
        $id = $this->currentJourneyId();
        $this->assertDatabaseHas('signup_funnel_journeys', ['id' => $id, 'source' => 'google', 'campaign_key' => null]);
        foreach (['signup_completed', 'signup_saved_draft', 'paid_subscription', 'email_verified', 'signup_submitted', 'landing_view', 'signup_viewed'] as $serverStage) {
            $this->stage($serverStage)->assertUnprocessable();
        }
        $this->stage('signup_started', ['email' => 'private@example.test'])->assertUnprocessable();
        $this->stage('signup_failed', ['reason' => 'raw arbitrary message'])->assertUnprocessable();
        $this->stage('signup_failed', ['reason' => 'email_taken'])->assertUnprocessable();
        $this->stage('preview_started')->assertNoContent();
        $this->assertSame(['signup_viewed'], DB::table('signup_funnel_stages')->where('journey_id', $id)->pluck('stage')->all());
        $this->withHeader('Origin', 'https://outside.example')->stage('signup_started')->assertForbidden();
    }

    public function test_browser_save_click_requires_a_displayed_preview_and_counts_once_even_after_draft_expiry(): void
    {
        $this->visit('/')->assertOk();
        $id = $this->currentJourneyId();
        $this->stage('save_clicked')->assertNoContent();
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'save_clicked']);
        $this->stage('preview_succeeded')->assertNoContent();
        $this->travel(3)->hours();
        $this->stage('save_clicked')->assertNoContent();
        $this->stage('save_clicked')->assertNoContent();
        $this->assertSame(1, DB::table('signup_funnel_stages')->where('journey_id', $id)->where('stage', 'save_clicked')->count());
    }

    public function test_password_registration_and_signed_verification_in_separate_session_are_observed_once_without_ad_consent(): void
    {
        Mail::fake();
        Notification::fake();
        $this->visit(self::PAID.'&gclid=fixtureClickIdentifier1234567890')->assertOk();
        $beforeLogin = $this->visit('/register')->assertOk();
        $cookieName = config('session.cookie');
        $beforeLogin->assertCookie($cookieName);
        $oldSession = $beforeLogin->getCookie($cookieName, false)->getValue();
        $this->post(self::SITE.'/register', ['name' => 'Funnel Fixture', 'email' => 'funnel@example.test', 'password' => 'Password!23456',
            'password_confirmation' => 'Password!23456', 'ad_measurement_consent' => false])->assertRedirect();
        $user = User::where('email', 'funnel@example.test')->sole();
        $cohort = DB::table('signup_funnel_journeys')->where('id', $this->currentJourneyId())->sole();
        $this->assertSame((string) $cohort->id, $user->signup_funnel_journey_id);
        $this->assertArrayNotHasKey('signup_funnel_journey_id', $user->toArray());
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $cohort->id, 'stage' => 'signup_completed']);
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $cohort->id, 'stage' => 'email_verified']);
        // A slow diagnostics request holding the old session cookie may complete
        // after registration; its response cannot replace the new auth or CSRF cookie.
        $this->withUnencryptedCookie(config('session.cookie'), $oldSession);
        $late = $this->stage('signup_started');
        $this->assertSame([], $late->headers->getCookies());
        // The verifying session has no diagnostic identifier. Only the actual
        // authenticated, signed verification may finish the originating journey.
        $this->app['session']->invalidate();
        $this->unencryptedCookies = [];
        $this->actingAs($user);
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)], false);
        $this->get(self::SITE.$signed)->assertRedirect();
        $this->get(self::SITE.$signed)->assertRedirect();
        $this->assertSame($cohort->id, $user->fresh()->signup_funnel_journey_id);
        $this->assertSame(1, DB::table('signup_funnel_stages')->where('journey_id', $cohort->id)->where('stage', 'email_verified')->count());
        $this->withHeader('Sec-GPC', '1')->visit('/pricing')->assertOk();
        $this->assertDatabaseMissing('signup_funnel_journeys', ['id' => $cohort->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'signup_funnel_journey_id' => null]);
    }

    public function test_stage_post_requires_valid_legacy_session_csrf_and_cannot_refresh_its_cookie(): void
    {
        // Laravel skips CSRF only while running in the testing environment.
        $this->app->detectEnvironment(fn () => 'production');
        $this->visit(self::PAID)->assertOk();
        $token = $this->app['session']->token();
        $this->stage('landing_visible')->assertStatus(419);
        $res = $this->withHeader('X-CSRF-TOKEN', $token)->stage('landing_visible');
        $res->assertNoContent();
        $this->assertSame([], $res->headers->getCookies());
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $this->currentJourneyId(), 'stage' => 'landing_visible']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('additionalEntryPages')]
    public function test_public_entry_pages_accept_visible_visits(string $path, string $surface): void
    {
        $this->visit($path)->assertOk();
        $id = $this->currentJourneyId();
        $this->stage('landing_visible')->assertNoContent();
        $this->assertDatabaseHas('signup_funnel_journeys', ['id' => $id, 'entry_surface' => $surface]);
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'landing_visible']);
    }

    public static function additionalEntryPages(): array
    {
        return [['/pricing', 'pricing'], ['/register', 'register'], ['/features', 'other_marketing'],
            ['/how-it-works', 'other_marketing'], ['/about', 'other_marketing'], ['/ai-ads-management', 'other_marketing'],
            ['/blog', 'guides']];
    }

    public function test_supported_meta_and_service_entry_are_classified_without_recording_other_utm_values(): void
    {
        $this->visit('/google-ads-management?utm_source=facebook&utm_medium=display&utm_campaign=spectra_20&utm_content=private-tag')->assertOk();
        $cohort = DB::table('signup_funnel_journeys')->where('id', $this->currentJourneyId())->sole();
        $this->assertSame('meta', $cohort->source);
        $this->assertSame('spectra_20', $cohort->campaign_key);
        $this->assertSame('google_ads_service', $cohort->entry_surface);
        $this->assertEqualsCanonicalizing(['id', 'source', 'campaign_key', 'entry_surface', 'started_at'], array_keys((array) $cohort));
    }

    public function test_new_google_oauth_signup_completes_and_verifies_but_login_to_existing_account_does_neither(): void
    {
        Mail::fake();
        $social = $this->createMock(\Laravel\Socialite\Two\GoogleProvider::class);
        $identities = ['existing-oauth@example.test', 'fresh-oauth@example.test'];
        $social->method('user')->willReturnCallback(function () use (&$identities) {
            return (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])
                ->map(['email' => array_shift($identities), 'name' => 'Funnel fixture']);
        });
        $social->method('redirect')->willReturn(redirect('https://accounts.google.com/'));
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($social);
        User::factory()->create(['email' => 'existing-oauth@example.test', 'tenant_key' => 'sitetospend']);
        $this->visit('/register')->assertOk();
        $id = $this->currentJourneyId();
        $this->post(self::SITE.'/auth/google/register', ['ad_measurement_consent' => false])->assertRedirect();
        $this->visit('/auth/google/callback')->assertRedirect();
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'signup_completed']);
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'email_verified']);
        $this->post(self::SITE.'/logout')->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = [];
        $this->visit('/register')->assertOk();
        $id = $this->currentJourneyId();
        $this->post(self::SITE.'/auth/google/register', ['ad_measurement_consent' => false])->assertRedirect();
        $this->visit('/auth/google/callback')->assertRedirect();
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'signup_completed']);
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'email_verified']);
        $this->assertSame($id, User::where('email', 'fresh-oauth@example.test')->sole()->signup_funnel_journey_id);
    }

    public function test_missing_diagnostics_table_cannot_abort_an_outer_application_transaction(): void
    {
        $originalCount = DB::table('signup_funnel_journeys')->count();
        \Illuminate\Support\Facades\Schema::rename('signup_funnel_stages', 'isolated_funnel_stage_fixture');
        $this->visit(self::PAID)->assertOk();
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS usable')->usable);
        $this->assertDatabaseCount('signup_funnel_journeys', $originalCount);
    }

    public function test_failed_signup_records_deduplicated_categories_without_marking_signup_complete(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        $this->visit('/register')->assertOk();
        $id = $this->currentJourneyId();
        $this->stage('signup_failed', ['reason' => 'validation'])->assertNoContent();
        $this->post(self::SITE.'/register', ['email' => 'existing@example.test'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'signup_failed', 'reason' => 'email_taken']);
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'signup_submitted']);
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $id, 'stage' => 'signup_completed']);
    }

    public function test_other_hosts_purpose_prefetch_known_bots_and_privacy_signals_do_not_create_a_cohort(): void
    {
        $originalCount = DB::table('signup_funnel_journeys')->count();
        $this->get('https://realpropertyads.com/')->assertOk();
        $this->postJson('https://realpropertyads.com/funnel/stages', ['stage' => 'landing_visible'])->assertNotFound();
        $this->withHeader('Sec-Purpose', 'prefetch;prerender')->visit(self::PAID)->assertOk();
        $this->flushHeaders();
        $this->withHeader('User-Agent', 'Googlebot/2.1')->visit(self::PAID)->assertOk();
        $this->flushHeaders();
        $this->withHeader('DNT', '1')->visit(self::PAID)->assertOk();
        $this->flushHeaders();
        $this->visit('/register')->assertOk();
        $this->assertDatabaseCount('signup_funnel_journeys', $originalCount);
    }

    public function test_explicit_privacy_signal_retires_existing_cohort_and_stage_submission_cannot_restore_it(): void
    {
        $this->visit(self::PAID)->assertOk();
        $id = $this->currentJourneyId();
        $this->withHeader('Sec-GPC', '1')->stage('preview_started')->assertNoContent();
        $this->flushHeaders();
        $this->stage('preview_started')->assertNoContent();
        $this->assertDatabaseMissing('signup_funnel_journeys', ['id' => $id]);
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $id]);
    }
}
