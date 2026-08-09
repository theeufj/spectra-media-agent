<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureClickIds;
use App\Jobs\RecordSiteGoogleConversion;
use App\Models\Setting;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Google does not always send a gclid.
 *
 * Where iOS ATT applies, auto-tagging substitutes wbraid (web-to-web) or gbraid
 * (app-to-web) *instead of* gclid. Code that checks only gclid therefore drops
 * those visitors entirely — no identifier stored, no conversion uploadable, no
 * signal to Smart Bidding. On this account 99% of clicks are mobile or tablet,
 * so that is the majority path, not an edge case.
 */
class GoogleClickIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public static function identifierProvider(): array
    {
        return [
            'gclid (non-iOS)' => ['gclid'],
            'gbraid (iOS app-to-web)' => ['gbraid'],
            'wbraid (iOS web-to-web)' => ['wbraid'],
        ];
    }

    /** @dataProvider identifierProvider */
    public function test_every_google_identifier_is_captured_from_the_url(string $param): void
    {
        $this->get("/?{$param}=TestValue123")->assertSuccessful();

        $this->assertSame('TestValue123', session("click_ids.{$param}"));
    }

    /** @dataProvider identifierProvider */
    public function test_every_google_identifier_survives_registration(string $param): void
    {
        Queue::fake();

        $this->withSession(['click_ids' => [$param => 'TestValue123']])
            ->post('/register', [
                'name' => 'Test Person',
                'email' => 'ios-visitor@example.com',
                'password' => 'Password!2345',
                'password_confirmation' => 'Password!2345',
            ]);

        $user = User::where('email', 'ios-visitor@example.com')->firstOrFail();

        $this->assertSame('TestValue123', $user->{$param});
        $this->assertTrue($user->hasGoogleClickId());

        // The upload must be attempted for all three, not just gclid.
        Queue::assertPushed(RecordSiteGoogleConversion::class);
    }

    /** @dataProvider identifierProvider */
    public function test_the_upload_sends_the_identifier_google_actually_gave_us(string $param): void
    {
        Cache::flush();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');

        $user = User::factory()->create([$param => 'TestValue123']);

        /** @var DataManagerService&\Mockery\MockInterface $dm */
        $dm = Mockery::mock(DataManagerService::class);
        // The identifier must be sent under its own key: submitting a wbraid as
        // though it were a gclid is rejected, not silently accepted.
        $dm->shouldReceive('ingestConversion')
            ->withArgs(fn (...$args) => ($args[2] ?? null) === [$param => 'TestValue123'])
            ->andReturn(['success' => true, 'requestId' => 'req-1']);

        (new RecordSiteGoogleConversion($user, 'signup'))->handle($dm);

        $this->assertDatabaseHas('spectra_conversion_events', [
            'user_id' => $user->id,
            'event' => 'signup',
            'gclid' => 'TestValue123',
            'uploaded_to_google' => true,
        ]);
    }

    public function test_gclid_wins_when_more_than_one_is_somehow_present(): void
    {
        $user = User::factory()->create(['gclid' => 'G', 'gbraid' => 'B', 'wbraid' => 'W']);

        $this->assertSame(['gclid' => 'G'], $user->googleAdIdentifiers());
    }

    public function test_a_user_with_no_google_identifier_uploads_nothing(): void
    {
        $user = User::factory()->create();

        /** @var DataManagerService&\Mockery\MockInterface $dm */
        $dm = Mockery::mock(DataManagerService::class);
        $dm->shouldNotReceive('ingestConversion');

        (new RecordSiteGoogleConversion($user, 'signup'))->handle($dm);

        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }

    public function test_the_middleware_still_captures_the_other_platforms(): void
    {
        $this->get('/?fbclid=FB123&msclid=MS123');

        $this->assertSame('FB123', CaptureClickIds::get('fbclid'));
        $this->assertSame('MS123', CaptureClickIds::get('msclid'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
