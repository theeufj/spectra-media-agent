<?php

namespace Tests\Feature;

use App\Support\HelpArticles;
use App\Support\PublicContentModifiedAt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Every sitemap contains only that host's preferred canonical content.
 */
class TenantSitemapTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(PublicContentModifiedAt::class, function ($mock) {
            $mock->shouldReceive('forSources')->andReturn('2026-09-20T09:00:00+10:00');
        });
    }

    public function test_sitemap_uses_the_requesting_host(): void
    {
        $response = $this->get('https://realpropertyads.com/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('<loc>https://realpropertyads.com/pricing</loc>', $response->getContent());
        $this->assertStringNotContainsString('sitetospend.com', $response->getContent());
        $this->assertSame('application/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $urls = array_map(fn ($url) => (string) $url->loc, iterator_to_array($xml->url, false));
        $this->assertEqualsCanonicalizing([
            'https://realpropertyads.com/',
            'https://realpropertyads.com/pricing',
            'https://realpropertyads.com/how-it-works',
        ], $urls);
    }

    public function test_sitemap_still_serves_sitetospend_on_its_own_domain(): void
    {
        $response = $this->get('https://sitetospend.com/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('<loc>https://sitetospend.com/pricing</loc>', $response->getContent());
        $this->assertStringContainsString('<loc>https://sitetospend.com/features</loc>', $response->getContent());
        $this->assertStringContainsString('<loc>https://sitetospend.com/google-ads-management</loc>', $response->getContent());
        foreach (HelpArticles::index() as $article) {
            $this->assertStringContainsString('<loc>https://sitetospend.com/blog/'.$article['slug'].'</loc>', $response->getContent());
        }
        $this->assertStringNotContainsString('/login</loc>', $response->getContent());
        $this->assertStringNotContainsString('/register</loc>', $response->getContent());
        $this->assertStringContainsString('<lastmod>2026-09-20T09:00:00+10:00</lastmod>', $response->getContent());
    }

    public function test_robots_sitemap_line_uses_the_requesting_host(): void
    {
        $response = $this->get('https://realpropertyads.com/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('Sitemap: https://realpropertyads.com/sitemap.xml', $response->getContent());
        $this->assertStringNotContainsString('sitetospend.com', $response->getContent());
        $this->assertStringNotContainsString('Sitemap: https://realpropertyads.com/llms.txt', $response->getContent());
    }

    public function test_preview_sitemap_publishes_no_urls_and_robots_publishes_no_sitemap(): void
    {
        $host = config('tenants.forge_domain');
        $sitemap = $this->get('https://'.$host.'/sitemap.xml')->assertOk();
        $sitemap->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringNotContainsString('<loc>', $sitemap->getContent());

        $robots = $this->get('https://'.$host.'/robots.txt')->assertOk();
        $this->assertStringContainsString('Disallow: /', $robots->getContent());
        $this->assertStringNotContainsString('Sitemap:', $robots->getContent());
    }
}
