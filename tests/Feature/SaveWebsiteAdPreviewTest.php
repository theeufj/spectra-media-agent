<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\BrandGuidelineExtractorService;
use App\Services\Demo\CampaignForecastPreview;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class SaveWebsiteAdPreviewTest extends TestCase
{
    use DatabaseTransactions;

    private const WEBSITE = 'https://93.184.216.34/plumbing';

    private const FLAGSHIP = 'https://sitetospend.com';

    private const COPY = [
        'headlines' => ['Brisbane Leak Repairs', 'Book a Licensed Plumber'],
        'descriptions' => ['Book a Brisbane plumber for leaking taps and pipes.', 'Get an upfront quote for your home.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.cloudflare.turnstile_site_key' => null, 'services.cloudflare.turnstile_secret_key' => null]);
    }

    private function fakePreview(array $copy = self::COPY): void
    {
        Http::fake(['https://93.184.216.34/*' => Http::response(
            '<!doctype html><html><head><title>Acme Plumbing</title></head><body><h1>Acme Plumbing</h1><p>We repair leaking taps and pipes in homes across Brisbane. Contact us to arrange a quote from a licensed plumber.</p></body></html>'
        )]);
        $this->mock(BrandGuidelineExtractorService::class)->shouldNotReceive('analyzeVisualStyle');
        $this->mock(CampaignForecastPreview::class)->shouldNotReceive('forUrl');
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willReturn(['text' => json_encode($copy, JSON_THROW_ON_ERROR)]);
        $this->app->instance(GeminiService::class, $gemini);
    }

    private function startRegistration(): string
    {
        $this->fakePreview();
        $response = $this->postJson('/api/demo/generate-full', ['url' => self::WEBSITE])
            ->assertOk()->assertJsonPath('ad_copy', self::COPY)->assertJsonMissingPath('visuals');
        $this->continueInBrowser($response);
        $token = $response->json('preview_token');

        $this->get('/register?preview_token='.$token)->assertRedirect(route('register'));
        $response = $this->get(route('register'))->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Register')
            ->where('previewUrl', self::WEBSITE)->where('previewUnavailable', false)->missing('previewToken'));

        return $response->viewData('page')['props']['previewReference'];
    }

    public function test_the_flagship_preview_works_without_contact_and_survives_verification_and_workspace_creation(): void
    {
        $reference = $this->startRegistration();
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('landing_leads', ['url' => self::WEBSITE]);

        $response = $this->post(route('register'), [
            'name' => 'Preview owner', 'email' => 'saved-ad@example.com', 'save_preview' => true, 'preview_reference' => $reference,
            'password' => 'use-a-good-password', 'password_confirmation' => 'use-a-good-password',
        ])->assertRedirect(route('verification.notice'));
        $this->continueInBrowser($response);

        $user = User::where('email', 'saved-ad@example.com')->firstOrFail();
        $draft = ['url' => self::WEBSITE, 'ad_copy' => self::COPY];
        $this->assertEquals($draft, $user->saved_ad_preview);
        $this->assertArrayNotHasKey('saved_ad_preview', $user->toArray());
        $this->assertFalse($user->customers()->exists());
        $this->get(route('quick-start'))->assertRedirect(route('verification.notice'));

        $signed = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ], absolute: false);
        $this->get($signed)->assertRedirect(route('quick-start'));
        $this->get(route('quick-start'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('savedAdPreview.ad_copy.headlines', self::COPY['headlines'])
            ->where('savedAdPreview.ad_copy.descriptions', self::COPY['descriptions'])
            ->where('demoUrl', self::WEBSITE));

        $this->post(route('quick-start.process'), ['website_url' => self::WEBSITE, 'timezone' => 'Australia/Sydney'])
            ->assertRedirect(route('quick-start.scanning'));
        $workspace = $user->customers()->firstOrFail();
        $this->assertEquals($draft, $workspace->saved_ad_preview);
        $this->assertArrayNotHasKey('saved_ad_preview', $workspace->toArray());
        $this->get(route('brand-guidelines.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('savedAdPreview.ad_copy.descriptions', self::COPY['descriptions']));

        $other = Customer::factory()->create();
        $user->customers()->attach($other, ['role' => 'owner']);
        $this->post(route('customers.switch', $other))->assertRedirect(route('dashboard'));
        $this->get(route('quick-start'))->assertInertia(fn (AssertableInertia $page) => $page->where('savedAdPreview', null));
    }

    public function test_the_draft_can_expire_in_the_original_browser(): void
    {
        [$token, $journey] = $this->startTrackedPreview();
        $this->travel(3)->hours();
        $this->get(self::FLAGSHIP.'/register?preview_token='.$token)->assertRedirect(route('register'));
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $journey, 'stage' => 'save_clicked']);
        $this->get(self::FLAGSHIP.'/register')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('previewUnavailable', true)->where('previewUrl', null));
        $this->post(self::FLAGSHIP.'/register', [
            'name' => 'Direct signup', 'email' => 'expired-ad@example.com', 'save_preview' => true,
            'password' => 'use-a-good-password', 'password_confirmation' => 'use-a-good-password',
        ])->assertSessionHasErrors('save_preview');
        $this->assertDatabaseMissing('users', ['email' => 'expired-ad@example.com']);
    }

    public function test_only_actual_navigation_from_a_valid_save_link_records_a_click(): void
    {
        [$token, $journey] = $this->startTrackedPreview();
        $link = self::FLAGSHIP.'/register?preview_token=';

        $this->get($link.str_repeat('a', 64))->assertRedirect(route('register'));
        $this->get($link.$token, ['Sec-Purpose' => 'prefetch;prerender'])->assertRedirect(route('register'));
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $journey, 'stage' => 'save_clicked']);

        $this->get($link.$token)->assertRedirect(route('register'));
        $this->get($link.$token)->assertRedirect(route('register'));
        $this->assertSame(1, DB::table('signup_funnel_stages')->where('journey_id', $journey)->where('stage', 'save_clicked')->count());
    }

    public function test_only_signup_with_a_persisted_flagship_draft_records_it_as_saved(): void
    {
        [$token, $journey] = $this->startTrackedPreview();
        $this->get(self::FLAGSHIP.'/register?preview_token='.$token)->assertRedirect(route('register'));
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $journey, 'stage' => 'signup_saved_draft']);

        $register = $this->get(self::FLAGSHIP.'/register')->assertOk();
        $this->post(self::FLAGSHIP.'/register', [
            'name' => 'Preview owner', 'email' => 'tracked-ad@example.com', 'save_preview' => true,
            'preview_reference' => $register->viewData('page')['props']['previewReference'],
            'password' => 'use-a-good-password', 'password_confirmation' => 'use-a-good-password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $journey, 'stage' => 'signup_saved_draft']);
        $user = User::where('email', 'tracked-ad@example.com')->firstOrFail();
        $this->assertSame($journey, $user->signup_funnel_journey_id);
        $this->assertEquals(['url' => self::WEBSITE, 'ad_copy' => self::COPY], $user->saved_ad_preview);
    }

    private function startTrackedPreview(): array
    {
        $landing = $this->get(self::FLAGSHIP.'/')->assertOk();
        $journey = session('sitetospend_signup_journey');
        $this->assertIsString($journey);
        $this->continueInBrowser($landing);

        $this->fakePreview();
        $preview = $this->withCredentials()->postJson(self::FLAGSHIP.'/api/demo/generate-full', ['url' => self::WEBSITE])->assertOk();
        $this->continueInBrowser($preview);

        return [$preview->json('preview_token'), $journey];
    }

    public function test_a_copied_token_does_not_share_the_draft_with_another_browser(): void
    {
        $this->fakePreview();
        $token = $this->postJson('/api/demo/generate-full', ['url' => self::WEBSITE])
            ->assertOk()->json('preview_token');

        $this->get('/register?preview_token='.$token)->assertRedirect(route('register'));
        $this->get(route('register'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('previewUnavailable', true)->where('previewUrl', null));
    }

    private function continueInBrowser(TestResponse $response): void
    {
        $name = config('session.cookie');
        $this->withCookie($name, $response->getCookie($name)->getValue());
    }

    public function test_an_old_registration_form_cannot_claim_a_different_preview(): void
    {
        $reference = $this->startRegistration();

        $newToken = app(\App\Services\Demo\SavedAdPreview::class)->create(request(), self::WEBSITE, self::COPY);
        $this->get('/register?preview_token='.$newToken)->assertRedirect(route('register'));

        $this->post(route('register'), [
            'name' => 'Other tab', 'email' => 'other-tab@example.com', 'save_preview' => true, 'preview_reference' => $reference,
            'password' => 'use-a-good-password', 'password_confirmation' => 'use-a-good-password',
        ])->assertSessionHasErrors('save_preview');
        $this->assertDatabaseMissing('users', ['email' => 'other-tab@example.com']);
    }

    public function test_generated_headlines_without_a_description_are_not_saveable(): void
    {
        $this->fakePreview(['headlines' => ['Acme Plumbing'], 'descriptions' => [' ', 123]]);
        $this->postJson('/api/demo/generate-full', ['url' => self::WEBSITE])
            ->assertUnprocessable()->assertJsonMissingPath('preview_token')->assertJsonValidationErrors('url');
        Mail::assertNothingSent();
    }

    public function test_google_signup_saves_the_exact_draft_and_uses_the_existing_identity_flow(): void
    {
        $reference = $this->startRegistration();
        $email = 'google-preview@example.com';
        $this->googleSignUp($email, $reference);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals(['url' => self::WEBSITE, 'ad_copy' => self::COPY], $user->saved_ad_preview);
        $this->get(route('quick-start'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('savedAdPreview.ad_copy.headlines', self::COPY['headlines']));
    }

    public function test_google_sign_in_to_an_existing_account_keeps_its_existing_draft(): void
    {
        $reference = $this->startRegistration();
        $previous = ['url' => 'https://83.184.216.34/', 'ad_copy' => self::COPY];
        $user = User::factory()->create(['email' => 'google-returning@example.com', 'saved_ad_preview' => $previous]);
        $this->googleSignUp($user->email, $reference);

        $this->assertEquals($previous, $user->fresh()->saved_ad_preview);
        $this->assertAuthenticatedAs($user);
    }

    private function googleSignUp(string $email, string $reference): void
    {
        $identity = (new GoogleUser)->setRaw(['email_verified' => true])->map(['email' => $email, 'name' => 'Preview owner']);
        $provider = $this->createMock(Provider::class);
        $provider->expects($this->once())->method('redirect')->willReturn(new RedirectResponse('https://accounts.google.com/test'));
        $provider->expects($this->once())->method('user')->willReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);

        $this->post(route('register.google.redirect'), ['save_preview' => true, 'preview_reference' => $reference, 'ad_measurement_consent' => false])
            ->assertRedirect('https://accounts.google.com/test');
        $response = $this->get(route('google.callback'))->assertRedirect(route('quick-start'));
        $this->continueInBrowser($response);
    }
}
