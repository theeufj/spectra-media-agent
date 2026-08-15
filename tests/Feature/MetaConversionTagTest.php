<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meta needs to be told when someone converts, not only that they visited.
 *
 * The base pixel tag fires fbq('track','PageView') on All Pages and nothing
 * else, so for every customer on this platform Meta received page views and no
 * conversions — while the Google Ads tag beside it in the same container had
 * been firing on form submit all along.
 *
 * That gap matters more on Meta than anywhere else. Its delivery system is
 * almost entirely signal-driven: a campaign optimising for conversions that has
 * never seen one does not underperform slightly, it optimises for whatever it
 * can measure. Page views are cheap to buy and worth nothing.
 */
class MetaConversionTagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.gtm.platform_refresh_token' => 'test-refresh',
            'services.gtm.platform_account_id' => '6351790509',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'gtm_container_id' => 'GTM-TEST123',
            'gtm_account_id' => '6351790509',
            'gtm_workspace_id' => '1',
            'gtm_config' => ['container_path' => 'accounts/6351790509/containers/250472733'],
        ]);
    }

    private function fakeGtm(array &$captured = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/triggers' => function ($request) use (&$captured) {
                if ($request->method() === 'GET') {
                    return Http::response(['trigger' => []]);
                }
                $captured['triggers'][] = $request->data();

                return Http::response(['triggerId' => (string) (100 + count($captured['triggers']))]);
            },
            '*/tags' => function ($request) use (&$captured) {
                if ($request->method() === 'GET') {
                    return Http::response(['tag' => []]);
                }
                $captured['tag'] = $request->data();

                return Http::response(['tagId' => '1']);
            },
            '*' => Http::response([]),
        ]);
    }

    private function html(array $captured): string
    {
        foreach ($captured['tag']['parameter'] ?? [] as $parameter) {
            if (($parameter['key'] ?? '') === 'html') {
                return $parameter['value'];
            }
        }

        return '';
    }

    public function test_it_tracks_a_conversion_event_not_a_page_view(): void
    {
        $captured = [];
        $this->fakeGtm($captured);

        app(GTMContainerService::class)->addFacebookConversionTag($this->customer(), '1234567890');

        $html = $this->html($captured);

        $this->assertStringContainsString("fbq('track', 'Lead'", $html);
        $this->assertStringNotContainsString('PageView', $html, 'the conversion tag must not report a page view');
    }

    public function test_the_event_carries_an_id_so_it_can_be_deduplicated(): void
    {
        // Offline conversions already reach these pixels through the Conversions
        // API. The moment anything sends this website event server-side too, an
        // event without an ID is counted twice — inflating reported conversions
        // and feeding the optimisation agents doubled data.
        $captured = [];
        $this->fakeGtm($captured);

        app(GTMContainerService::class)->addFacebookConversionTag($this->customer(), '1234567890');

        $html = $this->html($captured);

        $this->assertStringContainsString('eventID', $html);
        $this->assertStringContainsString('spectraMetaEventId', $html, 'the id must reach the dataLayer for a server-side sender to reuse');
    }

    public function test_it_fires_on_conversion_triggers_never_on_all_pages(): void
    {
        // GTM's built-in All Pages trigger is 2147479553. A conversion tag on it
        // counts every pageview as a conversion — a mistake already made once in
        // the Spectra container.
        $captured = [];
        $this->fakeGtm($captured);

        app(GTMContainerService::class)->addFacebookConversionTag($this->customer(), '1234567890');

        $triggerIds = $captured['tag']['firingTriggerId'] ?? [];

        $this->assertNotEmpty($triggerIds, 'a tag with no trigger never fires');
        $this->assertNotContains('2147479553', $triggerIds);

        $types = array_column($captured['triggers'] ?? [], 'type');
        $this->assertContains('formSubmission', $types);
        $this->assertContains('pageview', $types, 'sites that redirect to a thank-you page convert too');
    }

    public function test_it_guards_against_a_missing_base_pixel(): void
    {
        // A customer who removes the base tag should lose conversion tracking,
        // not get a JavaScript error on every form submission.
        $captured = [];
        $this->fakeGtm($captured);

        app(GTMContainerService::class)->addFacebookConversionTag($this->customer(), '1234567890');

        $this->assertStringContainsString("typeof fbq !== 'function'", $this->html($captured));
    }

    public function test_a_purchase_event_carries_value_and_currency(): void
    {
        // Without them Meta records that a sale happened but not what it was
        // worth, which makes value-based bidding impossible.
        $captured = [];
        $this->fakeGtm($captured);

        app(GTMContainerService::class)->addFacebookConversionTag($this->customer(), '1234567890', [
            'event_name' => 'Purchase',
            'value' => 250.0,
            'currency' => 'AUD',
        ]);

        $html = $this->html($captured);

        $this->assertStringContainsString("fbq('track', 'Purchase'", $html);
        $this->assertStringContainsString('value: 250', $html);
        $this->assertStringContainsString("currency: 'AUD'", $html);
    }

    public function test_a_container_without_gtm_is_reported_not_silently_skipped(): void
    {
        $customer = Customer::factory()->create(['gtm_container_id' => null]);

        $result = app(GTMContainerService::class)->addFacebookConversionTag($customer, '1234567890');

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }
}
