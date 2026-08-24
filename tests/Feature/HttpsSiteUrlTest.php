<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\Url;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Stored site URLs must always be https. CrawlSitemap preserves an explicit
 * scheme, so an http:// row poisons the crawl (and most sites bot-block or
 * 301 plain http) — the model mutator rewrites it at every write path.
 */
class HttpsSiteUrlTest extends TestCase
{
    use DatabaseTransactions;

    public function test_force_https_normalises_every_input_shape(): void
    {
        $this->assertSame('https://example.com', Url::forceHttps('http://example.com'));
        $this->assertSame('https://example.com', Url::forceHttps('HTTP://example.com'));
        $this->assertSame('https://example.com', Url::forceHttps('https://example.com'));
        $this->assertSame('https://example.com', Url::forceHttps('example.com'));
        $this->assertSame('https://example.com', Url::forceHttps('  example.com  '));
        $this->assertNull(Url::forceHttps(null));
        $this->assertNull(Url::forceHttps('   '));
    }

    public function test_customer_website_is_rewritten_to_https_on_save(): void
    {
        $customer = Customer::factory()->create(['website' => 'http://thedeckguy.com.au']);

        $this->assertSame('https://thedeckguy.com.au', $customer->fresh()->website);
    }
}
