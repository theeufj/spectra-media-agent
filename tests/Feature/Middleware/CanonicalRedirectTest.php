<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\CanonicalRedirect;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression: canonicalisation was silently breaking the app in production.
 *
 * CanonicalRedirect stripped every query parameter not on an allowlist and
 * 301'd to the stripped URL — but only when app()->environment('production'),
 * so nothing in development or the test suite ever saw it. The admin
 * dashboard's ?tab= and ?period= were not on the list, so clicking a tab
 * bounced straight back to the bare URL and appeared to do nothing at all.
 *
 * These tests run the middleware with the environment forced to production,
 * because testing it in any other environment tests nothing.
 */
class CanonicalRedirectTest extends TestCase
{
    private function response(string $url): \Symfony\Component\HttpFoundation\Response
    {
        // The condition under test is environment-gated, so the environment has
        // to be forced or the middleware short-circuits and every assertion
        // passes for the wrong reason.
        $this->app->detectEnvironment(fn () => 'production');

        return (new CanonicalRedirect)->handle(
            Request::create($url, 'GET'),
            fn () => response('ok'),
        );
    }

    // ── The app must keep its query strings ──────────────────────────────────

    public function test_admin_dashboard_keeps_its_tab_and_period(): void
    {
        $response = $this->response('https://sitetospend.com/admin/dashboard?tab=readiness&period=30');

        $this->assertSame(200, $response->getStatusCode(), 'the admin dashboard was redirected away from its own query string');
    }

    /**
     * @dataProvider appUrls
     */
    public function test_application_query_strings_survive(string $url): void
    {
        $this->assertSame(200, $this->response($url)->getStatusCode(), "{$url} was stripped");
    }

    public static function appUrls(): array
    {
        // Every one of these reads a parameter that was not on the old
        // allowlist, so every one was broken in production.
        return [
            'dashboard tab' => ['https://sitetospend.com/admin/dashboard?tab=economics'],
            'ai costs period' => ['https://sitetospend.com/admin/ai-costs?period=7'],
            'execution metrics' => ['https://sitetospend.com/admin/execution-metrics?platform=google'],
            'campaign filter' => ['https://sitetospend.com/campaigns?campaign_id=12'],
            'analytics days' => ['https://sitetospend.com/analytics?days=90'],
        ];
    }

    // ── Marketing pages still get canonicalised ──────────────────────────────

    public function test_junk_is_still_stripped_from_a_marketing_page(): void
    {
        $response = $this->response('https://sitetospend.com/pricing?random=1');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://sitetospend.com/pricing', $response->headers->get('Location'));
    }

    public function test_ad_click_ids_survive_on_marketing_pages(): void
    {
        // Stripping these breaks conversion tracking: gtag never sets _gcl_aw
        // and CaptureClickIds never stores the gclid for server-side upload.
        $response = $this->response('https://sitetospend.com/?gclid=abc123&utm_source=google');

        $this->assertSame(200, $response->getStatusCode(), 'ad click IDs were stripped from the landing page');
    }

    public function test_a_blog_article_is_canonicalised(): void
    {
        $response = $this->response('https://sitetospend.com/blog/some-article?junk=1');

        $this->assertSame(301, $response->getStatusCode());
    }

    // ── Host and scheme canonicalisation is unchanged ────────────────────────

    public function test_www_is_redirected_and_the_query_survives(): void
    {
        $response = $this->response('https://www.sitetospend.com/admin/dashboard?tab=readiness');

        $this->assertSame(301, $response->getStatusCode());
        // The host changes; the query must not.
        $this->assertSame(
            'https://sitetospend.com/admin/dashboard?tab=readiness',
            $response->headers->get('Location'),
        );
    }

    public function test_http_is_upgraded_and_the_query_survives(): void
    {
        $response = $this->response('http://sitetospend.com/admin/dashboard?tab=readiness');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(
            'https://sitetospend.com/admin/dashboard?tab=readiness',
            $response->headers->get('Location'),
        );
    }

    public function test_nothing_happens_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $response = (new CanonicalRedirect)->handle(
            Request::create('http://sitetospend.com/pricing?junk=1', 'GET'),
            fn () => response('ok'),
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
