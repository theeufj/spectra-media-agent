<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * sitemap.xml and robots.txt must speak the requesting tenant's domain.
 * When they were static files hardcoded to sitetospend.com, a crawl of
 * realpropertyads.com ingested 28 sitetospend pages and the customer's
 * brand guideline came out flavoured by the wrong site.
 */
class TenantSitemapTest extends TestCase
{
    public function test_sitemap_uses_the_requesting_host(): void
    {
        $response = $this->get('https://realpropertyads.com/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('<loc>https://realpropertyads.com/pricing</loc>', $response->getContent());
        $this->assertStringNotContainsString('sitetospend.com', $response->getContent());
        $this->assertSame('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_sitemap_still_serves_sitetospend_on_its_own_domain(): void
    {
        $response = $this->get('https://sitetospend.com/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('<loc>https://sitetospend.com/pricing</loc>', $response->getContent());
    }

    public function test_robots_sitemap_line_uses_the_requesting_host(): void
    {
        $response = $this->get('https://realpropertyads.com/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('Sitemap: https://realpropertyads.com/sitemap.xml', $response->getContent());
        $this->assertStringNotContainsString('sitetospend.com', $response->getContent());
    }
}
