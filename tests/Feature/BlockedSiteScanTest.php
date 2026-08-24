<?php

namespace Tests\Feature;

use App\Jobs\DiscoverNavigationUrls;
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
    public function test_a_waf_shell_document_looks_blocked(): void
    {
        $shell = '<!DOCTYPE html><html><head><title>Just a moment...</title>'
            .'<script src="/cdn-cgi/challenge-platform/orchestrate.js"></script>'
            .'<style>body{margin:0}</style></head>'
            .'<body><script>window.__CF$cv$params={r:"8f3"};'.str_repeat('var x=1;', 500).'</script></body></html>';

        $this->assertTrue(DiscoverNavigationUrls::looksBlocked($shell));
    }

    public function test_an_empty_rendered_body_looks_blocked(): void
    {
        $this->assertTrue(DiscoverNavigationUrls::looksBlocked('<html><head></head><body></body></html>'));
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
