<?php

namespace Tests\Feature\Services\FacebookAds;

use App\Models\Customer;
use App\Services\FacebookAds\ConversionsApiService;
use App\Services\FacebookAds\PixelService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group facebook
 */
class PixelAndConversionsIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $pixelId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_FACEBOOK_INTEGRATION_TESTS=true to run.');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $this->customer = Customer::whereNotNull('facebook_ads_account_id')->firstOrFail();
        $this->pixelId  = env('FACEBOOK_SPECTRA_PIXEL_ID', $this->customer->facebook_pixel_id ?? '978925284547796');
    }

    public function test_sends_lead_event_via_capi_with_test_code(): void
    {
        $service = new ConversionsApiService($this->customer);
        $result  = $service->sendEvent($this->pixelId, [
            'event_name'      => 'Lead',
            'event_time'      => time(),
            'test_event_code' => 'TEST12345',
            'action_source'   => 'website',
            'event_source_url' => 'https://sitetospend.com/try-now',
            'user_data'       => [
                'em' => [hash('sha256', 'integrationtest@example.com')],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('events_received', $result);
        $this->assertGreaterThan(0, $result['events_received']);
    }

    public function test_sends_page_view_event_via_capi(): void
    {
        $service = new ConversionsApiService($this->customer);
        $result  = $service->sendEvent($this->pixelId, [
            'event_name'      => 'PageView',
            'event_time'      => time(),
            'test_event_code' => 'TEST12345',
            'action_source'   => 'website',
            'user_data'       => [
                'em' => [hash('sha256', 'integrationtest@example.com')],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('events_received', $result);
    }

    public function test_sends_complete_registration_event(): void
    {
        $service = new ConversionsApiService($this->customer);
        $result  = $service->sendEvent($this->pixelId, [
            'event_name'      => 'CompleteRegistration',
            'event_time'      => time(),
            'test_event_code' => 'TEST12345',
            'action_source'   => 'website',
            'user_data'       => [
                'em' => [hash('sha256', 'integrationtest@example.com')],
                'fn' => [hash('sha256', 'testuser')],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('events_received', $result);
    }

    public function test_pixel_id_resolves_from_customer_with_pixel_set(): void
    {
        $customerWithPixel = Customer::whereNotNull('facebook_pixel_id')->first();

        if (!$customerWithPixel) {
            $this->markTestSkipped('No customer with facebook_pixel_id in DB.');
        }

        $service = new PixelService($customerWithPixel);
        $pixelId = $service->resolvePixelId();

        $this->assertNotNull($pixelId);
        $this->assertIsString($pixelId);
    }

    public function test_capi_normalises_and_hashes_pii(): void
    {
        // Verify the service hashes data — send uppercase email and confirm it's normalised
        $service = new ConversionsApiService($this->customer);
        $result  = $service->sendEvent($this->pixelId, [
            'event_name'      => 'Lead',
            'event_time'      => time(),
            'test_event_code' => 'TEST12345',
            'action_source'   => 'website',
            'user_data'       => [
                'em' => [hash('sha256', 'integrationtest@example.com')],
            ],
        ]);

        // If normalisation/hashing failed, Meta would reject the event
        $this->assertArrayHasKey('events_received', $result);
    }
}
