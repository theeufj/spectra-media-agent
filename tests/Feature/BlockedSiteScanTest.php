<?php

namespace Tests\Feature;

use App\Jobs\DiscoverNavigationUrls;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\SiteScanFailed;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\Crawling\WebsiteRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A WAF / bot-protection service doesn't fail the homepage fetch — it serves
 * a shell document with scripts but no readable text and no navigation.
 * DiscoverNavigationUrls must recognise that shell and end the scan with a
 * SiteScanFailed email that tells the customer their site is blocking us,
 * instead of letting the chain die into a misleading generic failure.
 */
class BlockedSiteScanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_waf_shell_document_looks_blocked(): void
    {
        $shell = '<!DOCTYPE html><html><head><title>Just a moment...</title>'
            .'<script src="/cdn-cgi/challenge-platform/orchestrate.js"></script>'
            .'<style>body{margin:0}</style></head>'
            .'<body><script>window.__CF$cv$params={r:"8f3"};'.str_repeat('var x=1;', 500).'</script></body></html>';

        $this->assertTrue(DiscoverNavigationUrls::looksBlocked($shell));
    }

    public function test_an_empty_body_is_not_evidence_of_bot_protection(): void
    {
        $this->assertFalse(DiscoverNavigationUrls::looksBlocked('<html><head></head><body></body></html>'));
    }

    public function test_a_javascript_app_explains_unreadable_content_instead_of_blaming_the_firewall(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $customer->users()->attach($user, ['role' => 'owner']);
        $html = '<html><head><title>Real Property Ads</title><script src="/app.js"></script></head>'
            .'<body><div id="app" data-page="{&quot;component&quot;:&quot;Landing&quot;}"></div></body></html>';
        $this->mock(WebsiteRenderer::class, function ($mock) use ($html) {
            $mock->shouldReceive('html')->once()->andReturn($html);
        });

        (new DiscoverNavigationUrls($customer, $user))->handle();

        Notification::assertSentTo($user, SiteScanFailed::class, function ($notification) use ($user) {
            $reason = $notification->toArray($user)['reason'];

            return str_contains($reason, 'JavaScript') && ! str_contains($reason, 'blocking');
        });
        $this->assertFalse(DiscoverNavigationUrls::looksBlocked($html));
    }

    public function test_renderer_failure_uses_the_dns_pinned_fetcher(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->mock(WebsiteRenderer::class, function ($mock) {
            $mock->shouldReceive('html')->once()->andThrow(new \RuntimeException('Renderer unavailable'));
        });
        $this->mock(PublicWebsiteFetcher::class, function ($mock) use ($customer) {
            $mock->shouldReceive('get')->once()->with($customer->website)
                ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], '<html><body></body></html>')));
        });

        (new DiscoverNavigationUrls($customer, $user))->handle();

        Http::assertNothingSent();
        Notification::assertSentTo($user, SiteScanFailed::class);
    }

    public function test_a_real_content_page_does_not_look_blocked(): void
    {
        $page = '<html><body><nav><a href="/services">Services</a></nav><main>'
            .'<h1>Deck Builders Melbourne Homeowners Trust</h1>'
            .'<p>'.str_repeat('New deck construction, restoration and maintenance across Melbourne. ', 10).'</p>'
            .'</main></body></html>';

        $this->assertFalse(DiscoverNavigationUrls::looksBlocked($page));
    }
}
