<?php

namespace Tests\Feature;

use App\Jobs\CheckGoogleConversionProcessing;
use App\Jobs\RecordSiteGoogleConversion;
use App\Models\ExceptionLog;
use App\Models\MccAccount;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\OwnSiteConversionDelivery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleConversionProcessingTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): DataManagerService
    {
        $service = new DataManagerService(new MccAccount(['google_customer_id' => '987']));
        (new \ReflectionProperty($service, 'cachedToken'))->setValue($service, 'fixture-token');

        return $service;
    }

    private function event(array $changes = []): SpectraConversionEvent
    {
        return SpectraConversionEvent::create(array_replace([
            'event' => 'signup', 'mode' => 'server_google', 'user_id' => User::factory()->create()->id,
            'occurred_at' => now()->subHour(), 'ad_identifiers' => ['gclid' => 'fixture-click'],
            'google_conversion_resource' => 'customers/123/conversionActions/456',
            'google_request_id' => 'fixture-request', 'google_accepted_at' => now()->subMinutes(31),
            'uploaded_to_google' => true,
        ], $changes));
    }

    private function processingResponse(string $status, array $changes = []): array
    {
        return ['requestStatusPerDestination' => [array_replace_recursive([
            'destination' => ['operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => '123'],
                'productDestinationId' => '456'],
            'requestStatus' => $status, 'eventsIngestionStatus' => ['recordCount' => '1'],
        ], $changes)]];
    }

    public function test_acceptance_saves_destination_receipt_and_queues_delayed_processing_check(): void
    {
        $this->freezeTime();
        Cache::flush();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
        $user = User::factory()->create(['gclid' => 'fixture-click']);
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'fixture-request'])]);
        (new RecordSiteGoogleConversion($user, 'signup'))->handle($this->service());
        $record = SpectraConversionEvent::sole();
        $this->assertSame('provider_accepted', $record->googleDeliveryStatus());
        $this->assertSame('customers/123/conversionActions/456', $record->google_conversion_resource);
        $this->assertSame(now()->timestamp, $record->google_accepted_at->timestamp);
        Queue::assertPushed(CheckGoogleConversionProcessing::class, fn ($job) => $job->eventId === $record->id && $job->delay->timestamp === now()->addMinutes(30)->timestamp);
    }

    public function test_success_confirms_processing_for_exact_destination_without_new_ingestion(): void
    {
        Cache::flush();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
        $event = $this->event();
        Http::fake(['datamanager.googleapis.com/*' => Http::response($this->processingResponse('SUCCESS'))]);
        $job = new CheckGoogleConversionProcessing($event->id, 'fixture-request');
        $job->handle($this->service());
        $job->handle($this->service());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://datamanager.googleapis.com/v1/requestStatus:retrieve')
            && $request['requestId'] === 'fixture-request');
        $this->assertSame('SUCCESS', $event->fresh()->google_processing_status);
        $this->assertSame('processed', $event->fresh()->googleDeliveryStatus());
        $this->assertTrue(app(OwnSiteConversionDelivery::class)->hasProcessedSignup());
        $this->assertSame('not_verified', app(OwnSiteConversionDelivery::class)->summary()['reported_in_google_ads']);
        Setting::set('conversion_resource_name.signup_import', 'customers/999/conversionActions/456');
        $this->assertFalse(app(OwnSiteConversionDelivery::class)->hasProcessedSignup());
    }

    public function test_processing_failure_is_persisted_and_reported_to_admin(): void
    {
        $event = $this->event();
        Http::fake(['datamanager.googleapis.com/*' => Http::response($this->processingResponse('FAILED', [
            'errorInfo' => ['errorCounts' => [['recordCount' => '1', 'reason' => 'PROCESSING_ERROR_REASON_INVALID_CLICK']]],
        ]))]);
        $before = ExceptionLog::count();
        (new CheckGoogleConversionProcessing($event->id, 'fixture-request'))->handle($this->service());
        $event->refresh();
        $this->assertTrue($event->uploaded_to_google, 'Acceptance remains historical fact even when processing fails.');
        $this->assertSame('processing_failed', $event->googleDeliveryStatus());
        $this->assertSame('PROCESSING_ERROR_REASON_INVALID_CLICK', $event->google_processing_details['errors'][0]['reason']);
        $this->assertSame($before + 1, ExceptionLog::count());
        $this->assertStringContainsString('processing failed', ExceptionLog::latest('id')->first()->message);
    }

    public function test_success_with_warnings_is_distinct_and_partial_success_is_never_success(): void
    {
        $event = $this->event();
        Http::fake(['datamanager.googleapis.com/*' => Http::sequence()
            ->push($this->processingResponse('SUCCESS', [
                'warningInfo' => ['warningCounts' => [['recordCount' => '1', 'reason' => 'PROCESSING_WARNING_REASON_DECRYPTION_ERROR']]],
            ]))
            ->push($this->processingResponse('PARTIAL_SUCCESS'))]);
        (new CheckGoogleConversionProcessing($event->id, 'fixture-request'))->handle($this->service());
        $this->assertSame('processed_with_warnings', $event->fresh()->googleDeliveryStatus());
        $partial = $this->event();
        (new CheckGoogleConversionProcessing($partial->id, 'fixture-request'))->handle($this->service());
        $this->assertSame('processing_failed', $partial->fresh()->googleDeliveryStatus());
    }

    public function test_processing_releases_with_backoff_and_expires_after_acceptance_budget(): void
    {
        $this->freezeTime();
        $event = $this->event();
        Http::fake(['datamanager.googleapis.com/*' => Http::response($this->processingResponse('PROCESSING'))]);
        $job = new CheckGoogleConversionProcessing($event->id, 'fixture-request');
        $job->withFakeQueueInteractions()->handle($this->service());
        $job->assertReleased(2340);
        $this->assertSame('processing', $event->fresh()->googleDeliveryStatus());
        $event->update(['google_accepted_at' => now()->subDay()]);
        $job = new CheckGoogleConversionProcessing($event->id, 'fixture-request');
        $job->withFakeQueueInteractions()->handle($this->service());
        $job->assertNotReleased();
        Http::assertSentCount(1);
        $this->assertSame('TIMED_OUT', $event->fresh()->google_processing_status);
        $this->assertSame('processing_unknown', $event->fresh()->googleDeliveryStatus());
    }

    public function test_early_check_and_legacy_receipt_do_not_query_google(): void
    {
        $this->freezeTime();
        $event = $this->event(['google_accepted_at' => now()]);
        $job = new CheckGoogleConversionProcessing($event->id, 'fixture-request');
        $job->withFakeQueueInteractions()->handle($this->service());
        $job->assertReleased(1800);
        $event->update(['google_accepted_at' => null]);
        (new CheckGoogleConversionProcessing($event->id, 'fixture-request'))->handle($this->service());
        Http::assertNothingSent();
        $this->assertSame('legacy_unverified', $event->fresh()->googleDeliveryStatus());
    }

    public function test_wrong_destination_missing_status_or_wrong_count_remains_unverified(): void
    {
        $responses = [
            [],
            $this->processingResponse('SUCCESS', ['destination' => ['operatingAccount' => ['accountId' => '999']]]),
            $this->processingResponse('SUCCESS', ['eventsIngestionStatus' => ['recordCount' => '0']]),
            $this->processingResponse('REQUEST_STATUS_UNKNOWN'),
        ];
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }
        Http::fake(['datamanager.googleapis.com/*' => $sequence]);
        foreach ($responses as $response) {
            $event = $this->event();
            $job = new CheckGoogleConversionProcessing($event->id, 'fixture-request');
            $job->withFakeQueueInteractions()->handle($this->service());
            $job->assertReleased();
            $this->assertSame('processing_unknown', $event->fresh()->googleDeliveryStatus());
        }
    }

    public function test_upload_without_receipt_fails_but_validate_only_remains_a_dry_run(): void
    {
        Http::fake(['datamanager.googleapis.com/*' => Http::response([])]);
        $service = $this->service();
        $live = $service->ingestConversion('123', '456', ['gclid' => 'fixture-click'], 0, 'AUD', now());
        $this->assertFalse($live['success']);
        $validation = $service->ingestConversion('123', '456', ['gclid' => 'fixture-click'], 0, 'AUD', now(), validateOnly: true);
        $this->assertTrue($validation['success']);
        Queue::assertNotPushed(CheckGoogleConversionProcessing::class);
        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }
}
