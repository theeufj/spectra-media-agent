<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\ExceptionLog;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\SiteSignupConversionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SiteSignupDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_organic_registration_is_saved_without_fabricating_ad_attribution(): void
    {
        Mail::fake();
        $this->post('/register', ['name' => 'Organic fixture', 'email' => 'organic-fixture@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345'])->assertRedirect();
        $event = SpectraConversionEvent::where('event', 'signup')->sole();
        $this->assertEmpty($event->ad_identifiers);
        $this->assertFalse($event->uploaded_to_google);
        $this->assertSame('no_click_identifier', $event->googleDeliveryStatus());
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
    }

    public function test_real_registration_uses_durable_id_deduplication_and_actual_registration_time(): void
    {
        $user = User::factory()->create(['wbraid' => 'fixture-braid', 'created_at' => now()->subMinutes(10)]);
        $service = app(SiteSignupConversionService::class);
        $event = $service->record($user);
        $second = $service->record($user);
        $this->assertSame($event->id, $second->id);
        $this->assertSame($user->created_at->timestamp, $event->occurred_at->timestamp);
        $this->assertSame(['wbraid' => 'fixture-braid'], $event->ad_identifiers);
        $this->assertSame('awaiting_upload', $event->googleDeliveryStatus());
        Queue::assertPushed(RecordSiteGoogleConversion::class, 1);
        Queue::assertPushed(RecordSiteGoogleConversion::class, function ($job) use ($event) {
            $this->assertSame($event->id, (fn () => $this->conversionEventId)->call($job));
            $this->assertSame($event->occurred_at->timestamp, (fn () => $this->occurredAt)->call($job)->getTimestamp());
            $this->assertTrue($job->afterCommit);

            return true;
        });
    }

    public function test_queue_outage_preserves_event_and_reports_failure_without_breaking_signup(): void
    {
        $user = User::factory()->create(['gclid' => 'fixture-click']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Fixture queue unavailable'));
        $before = ExceptionLog::count();
        $event = app(SiteSignupConversionService::class)->record($user);
        $this->assertFalse($event->uploaded_to_google);
        $this->assertStringContainsString('Queue dispatch failed', $event->upload_error);
        $this->assertSame($before + 1, ExceptionLog::count());
    }

    public function test_google_signup_records_once_and_existing_google_login_is_not_a_new_conversion(): void
    {
        Mail::fake();
        $identity = (new \Laravel\Socialite\Two\User)->map([
            'id' => 'fixture-id', 'email' => 'google-fixture@example.com', 'name' => 'Google fixture',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);
        $this->withSession(['click_ids' => ['gbraid' => 'fixture-google-braid']])
            ->get('/auth/google/callback')->assertRedirect();
        $user = User::where('email', 'google-fixture@example.com')->firstOrFail();
        $this->assertSame('fixture-google-braid', $user->gbraid);
        $this->assertDatabaseCount('spectra_conversion_events', 1);
        Queue::assertPushed(RecordSiteGoogleConversion::class, 1);
        $this->get('/auth/google/callback')->assertRedirect();
        $this->assertDatabaseCount('spectra_conversion_events', 1);
        Queue::assertPushed(RecordSiteGoogleConversion::class, 1);
    }
}
