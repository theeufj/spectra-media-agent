<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A GTM tag without a firing trigger never runs.
 *
 * Conversions happen two ways on most sites: a form submits in place, or the
 * visitor lands on a thank-you page. Attaching only a formSubmission trigger
 * misses every site that redirects after submit — the more common pattern in
 * lead generation, which is most of what this platform advertises.
 *
 * The opposite mistake is worse and has already been made once: the Spectra
 * container had a conversion tag on GTM's built-in All Pages trigger, so every
 * pageview would have counted as a conversion. Any default trigger here has to
 * be specific enough that it cannot match the home page.
 */
class GtmConversionTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Without these the service cannot mint an access token and returns
        // before it ever reaches the trigger logic under test.
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

    /**
     * @param  list<array<string,mixed>>  $existingTriggers
     */
    private function fakeGtm(array $existingTriggers = [], array &$captured = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/triggers' => function ($request) use ($existingTriggers, &$captured) {
                if ($request->method() === 'GET') {
                    return Http::response(['trigger' => $existingTriggers]);
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

    public function test_a_conversion_tag_is_never_created_without_a_trigger(): void
    {
        $captured = [];
        $this->fakeGtm(captured: $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500', ['conversion_label' => 'abc123']
        );

        $this->assertNotEmpty($captured['tag']['firingTriggerId'] ?? [], 'a tag with no trigger never fires');
    }

    public function test_both_form_submit_and_thank_you_page_are_attached(): void
    {
        // Form submit alone misses every site that redirects to a confirmation
        // page after submitting, which is the more common pattern.
        $captured = [];
        $this->fakeGtm(captured: $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500', ['conversion_label' => 'abc123']
        );

        $this->assertCount(2, $captured['tag']['firingTriggerId']);

        $types = array_column($captured['triggers'] ?? [], 'type');
        $this->assertContains('formSubmission', $types);
        $this->assertContains('pageview', $types);
    }

    public function test_the_page_trigger_cannot_match_the_home_page(): void
    {
        // The mistake already made once on the Spectra container: a conversion
        // tag on All Pages turns every visit into a conversion.
        $captured = [];
        $this->fakeGtm(captured: $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500', ['conversion_label' => 'abc123']
        );

        $pageview = collect($captured['triggers'] ?? [])->firstWhere('type', 'pageview');
        $this->assertNotNull($pageview);

        $pattern = $pageview['filter'][0]['parameter'][1]['value'];

        // '#' delimiter, because the pattern itself contains forward slashes.
        $regex = '#'.$pattern.'#i';

        $this->assertMatchesRegularExpression($regex, 'https://x.com/thank-you');
        $this->assertMatchesRegularExpression($regex, 'https://x.com/thank_you');
        $this->assertMatchesRegularExpression($regex, 'https://x.com/order-complete');
        $this->assertDoesNotMatchRegularExpression($regex, 'https://x.com/');
        $this->assertDoesNotMatchRegularExpression($regex, 'https://x.com/pricing');
    }

    public function test_existing_triggers_are_reused_rather_than_duplicated(): void
    {
        // Provisioning re-runs — SetupConversionTracking retries three times —
        // so creating a fresh trigger each pass would litter the container.
        $captured = [];
        $this->fakeGtm([
            ['triggerId' => '55', 'name' => 'Spectra — Form Submit'],
            ['triggerId' => '56', 'name' => 'Spectra — Conversion Page View'],
        ], $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500', ['conversion_label' => 'abc123']
        );

        $this->assertEmpty($captured['triggers'] ?? [], 'no new triggers should be created');
        $this->assertSame(['55', '56'], $captured['tag']['firingTriggerId']);
    }

    public function test_an_explicit_trigger_overrides_the_defaults(): void
    {
        $captured = [];
        $this->fakeGtm(captured: $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500',
            ['conversion_label' => 'abc123', 'firing_trigger_id' => '999']
        );

        $this->assertSame(['999'], $captured['tag']['firingTriggerId']);
        $this->assertEmpty($captured['triggers'] ?? []);
    }

    public function test_the_aw_prefix_is_stripped_for_the_awct_tag(): void
    {
        // awct requires a bare numeric conversion id; leaving "AW-" on produces
        // a tag Google accepts and never fires.
        $captured = [];
        $this->fakeGtm(captured: $captured);

        app(GTMContainerService::class)->addConversionTag(
            $this->customer(), 'Spectra — Signup', 'AW-18115663500', ['conversion_label' => 'abc123']
        );

        $conversionId = collect($captured['tag']['parameter'])->firstWhere('key', 'conversionId')['value'];
        $this->assertSame('18115663500', $conversionId);
    }
}
