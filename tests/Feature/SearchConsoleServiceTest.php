<?php

namespace Tests\Feature;

use App\Jobs\VerifySearchConsoleBinding;
use App\Models\Customer;
use App\Models\SeoAudit;
use App\Models\User;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\SEO\SearchConsoleService;
use App\Support\WorkStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SearchConsoleServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.gtm.platform_refresh_token' => 'test-refresh',
            'services.gtm.platform_account_id' => '123',
        ]);
    }

    private function fakeAuth(array $extra = [], array $sites = [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']]): void
    {
        Http::fake(array_merge([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => $sites]),
        ], $extra));
    }

    private function boundCustomer(string $website = 'https://example.com', string $property = 'https://example.com/'): Customer
    {
        $customer = Customer::factory()->create(['website' => $website]);
        $customer->forceFill([
            'search_console_property' => $property,
            'search_console_verified_host' => strtolower((string) parse_url($website, PHP_URL_HOST)),
            'search_console_verified_at' => now(),
        ])->save();

        return $customer;
    }

    private function assignedCustomer(): Customer
    {
        return Customer::factory()->create([
            'website' => 'https://example.com', 'gtm_container_id' => 'GTM-ABC123', 'gtm_account_id' => '123', 'gtm_installed' => true,
            'gtm_config' => ['container_id' => 'GTM-ABC123', 'account_id' => '123', 'container_path' => 'accounts/123/containers/456', 'provisioned_at' => now()->toIso8601String()],
        ]);
    }

    private function fakeWebsite(string $html = '<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123"></iframe>'): void
    {
        $fetcher = $this->createMock(PublicWebsiteFetcher::class);
        $fetcher->expects($this->once())->method('get')->with('https://example.com/')->willReturn(new Response(new \GuzzleHttp\Psr7\Response(200, [], $html)));
        $this->app->instance(PublicWebsiteFetcher::class, $fetcher);
    }

    public function test_an_explicit_domain_binding_is_verified_against_platform_access(): void
    {
        $this->fakeAuth(sites: [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner']]);
        $this->assertTrue(app(SearchConsoleService::class)->isVerified($this->boundCustomer('https://www.example.com/pricing', 'sc-domain:example.com')));
    }

    public function test_url_prefix_binding_must_cover_the_exact_host(): void
    {
        $this->fakeAuth();
        $customer = $this->boundCustomer('https://www.example.com/', 'https://example.com/');
        $this->assertFalse(app(SearchConsoleService::class)->isVerified($customer));
        Http::assertNothingSent();
    }

    public function test_platform_domain_ownership_never_grants_an_unbound_customer_access(): void
    {
        $this->fakeAuth(sites: [['siteUrl' => 'sc-domain:sitetospend.com', 'permissionLevel' => 'siteOwner']]);
        $customer = Customer::factory()->create(['website' => 'https://sitetospend.com']);
        $service = app(SearchConsoleService::class);
        $this->assertFalse($service->isVerified($customer));
        $this->assertFalse($service->performance($customer)['success']);
        $this->assertFalse($service->inspectUrl($customer, 'https://sitetospend.com/features')['success']);
        Http::assertNothingSent();
    }

    public function test_editing_website_to_another_tenant_blocks_even_a_previously_bound_customer(): void
    {
        $this->fakeAuth();
        $customer = $this->boundCustomer();
        $customer->update(['website' => 'https://sitetospend.com']);
        $service = app(SearchConsoleService::class);
        $this->assertFalse($service->performance($customer)['success']);
        $this->assertFalse($service->inspectUrl($customer, 'https://sitetospend.com/features')['success']);
        Http::assertNothingSent();
    }

    public function test_customer_mass_assignment_cannot_create_or_replace_an_ownership_binding(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://sitetospend.com']);
        $customer->fill(['search_console_property' => 'sc-domain:sitetospend.com', 'search_console_verified_host' => 'sitetospend.com', 'search_console_verified_at' => now()])->save();
        $this->assertNull($customer->fresh()->search_console_property);
        $this->assertNull($customer->fresh()->search_console_verified_host);
        $this->assertNull($customer->fresh()->search_console_verified_at);
    }

    public function test_sites_we_cannot_query_are_not_treated_as_verified(): void
    {
        $this->fakeAuth(sites: [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteUnverifiedUser']]);
        $this->assertFalse(app(SearchConsoleService::class)->isVerified($this->boundCustomer()));
    }

    public function test_performance_preserves_fractional_position_and_filters_out_other_subdomains(): void
    {
        $this->fakeAuth(['*searchAnalytics/query' => Http::response([
            'responseAggregationType' => 'byPage',
            'rows' => [['keys' => ['ppc agency'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 7.6]],
        ])]);
        $result = app(SearchConsoleService::class)->performance($this->boundCustomer('https://example.com', 'sc-domain:example.com'));
        $this->assertTrue($result['success']);
        $this->assertSame(7.6, $result['rows'][0]['position']);
        $this->assertSame('byPage', $result['aggregation']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'searchAnalytics')) {
                return false;
            }
            $filter = $request['dimensionFilterGroups'][0]['filters'][0];
            $this->assertSame('page', $filter['dimension']);
            $this->assertSame('includingRegex', $filter['operator']);
            $regex = '~'.$filter['expression'].'~';
            $this->assertSame(1, preg_match($regex, 'https://example.com/features'));
            $this->assertSame(0, preg_match($regex, 'https://sandbox.example.com/features'));
            $this->assertSame(0, preg_match($regex, 'https://example.com.attacker.test/features'));
            $this->assertSame('auto', $request['aggregationType']);

            return true;
        });
    }

    public function test_scope_and_property_permission_errors_have_distinct_diagnostics(): void
    {
        $this->fakeAuth(['*searchAnalytics/query' => Http::sequence()
            ->push(['error' => ['message' => 'Request had insufficient authentication scopes.', 'details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]], 403)
            ->push(['error' => ['message' => 'User does not have sufficient permission for site.']], 403)]);
        $service = app(SearchConsoleService::class);
        $customer = $this->boundCustomer();
        $scopeError = $service->performance($customer)['error'];
        $this->assertStringContainsString('connection needs permission for Search Console reports', $scopeError);
        $this->assertStringNotContainsString('php artisan', $scopeError);
        $this->assertStringContainsString('property permissions', $service->performance($customer)['error']);
    }

    public function test_readonly_property_add_failure_never_creates_a_binding(): void
    {
        $this->fakeAuth([
            '*siteVerification/v1/webResource*' => Http::response(['id' => 'verified']),
            '*webmasters/v3/sites/https*' => Http::response(['error' => ['message' => 'Request had insufficient authentication scopes.']], 403),
        ]);
        $this->fakeWebsite();
        $customer = $this->assignedCustomer();
        $result = app(SearchConsoleService::class)->verifyViaTagManager($customer);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('adding the Search Console property failed', $result['error']);
        $this->assertNull($customer->fresh()->search_console_property);
    }

    public function test_binding_is_saved_only_after_matching_assigned_container_and_both_google_operations_succeed(): void
    {
        $this->fakeAuth([
            '*siteVerification/v1/webResource*' => Http::response(['id' => 'verified']),
            '*webmasters/v3/sites/https*' => Http::response([], 204),
        ]);
        $this->fakeWebsite();
        $customer = $this->assignedCustomer();
        $customer->update(['gtm_installed' => false]); // Saved flags are not ownership evidence.
        $result = app(SearchConsoleService::class)->verifyViaTagManager($customer);
        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/', $customer->fresh()->search_console_property);
        $this->assertSame('example.com', $customer->fresh()->search_console_verified_host);
        $this->assertNotNull($customer->fresh()->search_console_verified_at);
    }

    public function test_detecting_some_other_platform_container_is_not_ownership_proof(): void
    {
        $this->fakeAuth();
        $this->fakeWebsite('<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-OTHERS"></iframe>');
        $customer = $this->assignedCustomer();
        $result = app(SearchConsoleService::class)->verifyViaTagManager($customer);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not installed', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_container_assigned_to_another_customer_cannot_be_adopted_for_search_console(): void
    {
        $this->fakeAuth();
        $owner = $this->assignedCustomer();
        $copy = $this->assignedCustomer();
        $copy->update(['website' => 'https://sitetospend.com']);
        $result = app(SearchConsoleService::class)->verifyViaTagManager($copy);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('exclusive platform-assigned', $result['error']);
        $this->assertNull($copy->fresh()->search_console_property);
        Http::assertNothingSent();
    }

    public function test_scrape_detected_container_without_provisioning_provenance_is_refused(): void
    {
        $this->fakeAuth();
        $customer = Customer::factory()->create(['website' => 'https://sitetospend.com', 'gtm_container_id' => 'GTM-ABC123', 'gtm_installed' => true]);
        $result = app(SearchConsoleService::class)->verifyViaTagManager($customer);
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_domain_binding_inspection_preserves_evidence_but_cannot_read_a_sandbox_subdomain(): void
    {
        $this->fakeAuth(['*urlInspection/index:inspect' => Http::response(['inspectionResult' => ['indexStatusResult' => [
            'verdict' => 'FAIL', 'coverageState' => 'Duplicate, Google chose different canonical than user',
            'googleCanonical' => 'https://other.example/features', 'userCanonical' => 'https://example.com/features',
        ]]])], [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner']]);
        $customer = $this->boundCustomer('https://example.com', 'sc-domain:example.com');
        $service = app(SearchConsoleService::class);
        $this->assertFalse($service->inspectUrl($customer, 'https://sandbox.example.com/features')['success']);
        Http::assertNothingSent();
        $result = $service->inspectUrl($customer, 'https://example.com/features');
        $this->assertTrue($result['success']);
        $this->assertSame('https://other.example/features', $result['google_canonical']);
    }

    public function test_exact_reporting_window_includes_final_data_only(): void
    {
        $this->fakeAuth(['*searchAnalytics/query' => Http::response(['rows' => []])]);
        $result = app(SearchConsoleService::class)->performance($this->boundCustomer());
        $this->assertSame(now()->subDays(30)->toDateString(), $result['reporting_start']);
        $this->assertSame(now()->subDays(3)->toDateString(), $result['reporting_end']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics') && $request['dataState'] === 'final' && $request['type'] === 'web');
    }

    public function test_customer_without_a_website_is_handled_cleanly(): void
    {
        $result = app(SearchConsoleService::class)->performance(Customer::factory()->create(['website' => null]));
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no website', $result['error']);
        Http::assertNothingSent();
    }

    public function test_verification_action_is_queued_for_selected_owned_customer_and_ignores_injected_customer(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $user->customers()->attach($customer);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->post(route('seo.search-console.verify'), ['customer_id' => $other->id])->assertRedirect();
        $this->post(route('seo.search-console.verify'))->assertRedirect();
        Queue::assertPushed(VerifySearchConsoleBinding::class, 1);
        Queue::assertPushed(VerifySearchConsoleBinding::class, fn ($job) => $job->customerId === $customer->id);
        Queue::assertNotPushed(VerifySearchConsoleBinding::class, fn ($job) => $job->customerId === $other->id);
    }

    public function test_queued_verification_reports_actionable_setup_failure(): void
    {
        $customer = Customer::factory()->create();
        $run = WorkStatus::start($customer->id, 'search-console-verification');
        (new VerifySearchConsoleBinding($customer->id, $run))->handle(app(SearchConsoleService::class));
        $this->assertSame('failed', WorkStatus::get($customer->id, 'search-console-verification')['status']);
        $this->assertStringContainsString('platform-assigned', WorkStatus::get($customer->id, 'search-console-verification')['message']);
    }

    public function test_site_indexing_report_does_not_replace_the_latest_technical_score(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer);
        $technical = SeoAudit::create(['customer_id' => $customer->id, 'url' => $customer->website, 'score' => 85, 'created_at' => now()->subMinute()]);
        $site = SeoAudit::create(['customer_id' => $customer->id, 'url' => $customer->website, 'score' => null, 'indexing_analysis' => ['scope' => 'sitemap']]);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])->get(route('seo.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('latestAudit.id', $technical->id)->where('indexingAudit.id', $site->id));
    }
}
