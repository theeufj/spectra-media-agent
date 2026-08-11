<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\SEO\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Search Console replaces SERP scraping for rank tracking on owned domains.
 *
 * The scraping path produced 4,264 ranking rows over three months, every one
 * with a null position — not because the site did not rank, but because
 * Firecrawl returned 402 (out of credits) and the service recorded the miss as
 * data. Nothing noticed, because nothing read the output.
 *
 * Search Console is free, first-party, and reports average position across real
 * impressions rather than one scraped snapshot. Ownership comes from the GTM
 * container Spectra already publishes for the customer, so no per-customer
 * OAuth is involved — the management-account pattern the codebase requires.
 */
class SearchConsoleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.gtm.platform_refresh_token' => 'test-refresh',
        ]);
    }

    /**
     * Auth plus the sites listing, which the service consults to pick the right
     * property (www vs non-www are separate properties in Search Console).
     */
    private function fakeAuth(array $extra = [], array $sites = [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']]): void
    {
        Http::fake(array_merge([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => $sites]),
        ], $extra));
    }

    public function test_a_site_is_matched_by_exact_url_prefix(): void
    {
        // Search Console properties are exact: https://example.com/ and
        // example.com are different things, and a mismatch reads as "not
        // verified" rather than erroring.
        $this->fakeAuth();

        $customer = Customer::factory()->create(['website' => 'http://www.example.com/pricing']);

        $this->assertTrue(app(SearchConsoleService::class)->isVerified($customer));
    }

    public function test_a_domain_property_is_matched(): void
    {
        // Search Console has two property kinds. A Domain property
        // (sc-domain:example.com) covers every subdomain and scheme; URL-prefix
        // properties do not. sitetospend.com is registered as a Domain property,
        // so matching only the https:// forms reported it unverified and skipped
        // Search Console altogether.
        $this->fakeAuth(sites: [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner']]);

        $customer = Customer::factory()->create(['website' => 'https://www.example.com/pricing']);

        $this->assertTrue(app(SearchConsoleService::class)->isVerified($customer));
    }

    public function test_sites_we_cannot_query_are_not_treated_as_verified(): void
    {
        // Search Console lists sites the account can see but not read.
        // Counting those as verified would send every query into a 403.
        $this->fakeAuth(sites: [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteUnverifiedUser']]);

        $customer = Customer::factory()->create(['website' => 'https://example.com']);

        $this->assertFalse(app(SearchConsoleService::class)->isVerified($customer));
    }

    public function test_performance_returns_position_clicks_and_impressions(): void
    {
        $this->fakeAuth([
            '*searchAnalytics/query' => Http::response([
                'rows' => [
                    ['keys' => ['ppc agency'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 7.6],
                ],
            ]),
        ]);

        $customer = Customer::factory()->create(['website' => 'https://example.com']);

        $result = app(SearchConsoleService::class)->performance($customer);

        $this->assertTrue($result['success']);
        $this->assertSame('ppc agency', $result['rows'][0]['key']);
        $this->assertSame(12, $result['rows'][0]['clicks']);
        // Kept as a float: 8.4 → 7.6 is real progress that rounding would hide.
        $this->assertSame(7.6, $result['rows'][0]['position']);
    }

    public function test_a_403_explains_the_missing_scope_rather_than_saying_forbidden(): void
    {
        // The account's actual failure mode today. "Forbidden" would send
        // someone hunting for a permissions problem on the customer's site,
        // when the fix is reissuing the platform token.
        $this->fakeAuth([
            '*searchAnalytics/query' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $customer = Customer::factory()->create(['website' => 'https://example.com']);

        $result = app(SearchConsoleService::class)->performance($customer);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('webmasters.readonly', $result['error']);
        $this->assertStringContainsString('google:platform-token', $result['error']);
    }

    public function test_verification_is_refused_when_the_container_is_not_installed(): void
    {
        // Verification works via the GTM container, so it can only succeed once
        // the snippet is actually on the site. Attempting it earlier burns an
        // API call and returns a confusing Google error.
        $this->fakeAuth();

        $customer = Customer::factory()->create([
            'website' => 'https://example.com',
            'gtm_container_id' => 'GTM-ABC123',
            'gtm_installed' => false,
        ]);

        $result = app(SearchConsoleService::class)->verifyViaTagManager($customer);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not installed', $result['error']);
    }

    public function test_a_customer_without_a_website_is_handled_cleanly(): void
    {
        $this->fakeAuth();

        $customer = Customer::factory()->create(['website' => null]);

        $result = app(SearchConsoleService::class)->performance($customer);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no website', $result['error']);
    }

    public function test_reporting_lag_is_respected(): void
    {
        // Search Console reports two to three days behind. Querying up to today
        // returns zeros, which would look like a site that lost all its traffic.
        $captured = null;

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']],
            ]),
            '*searchAnalytics/query' => function ($request) use (&$captured) {
                $captured = $request->data();

                return Http::response(['rows' => []]);
            },
        ]);

        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        app(SearchConsoleService::class)->performance($customer, 'query', 28);

        $this->assertNotNull($captured);
        $this->assertLessThan(
            now()->subDays(2)->toDateString(),
            $captured['endDate'],
            'the window must end before the reporting lag'
        );
    }
}
