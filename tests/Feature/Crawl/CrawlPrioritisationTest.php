<?php

namespace Tests\Feature\Crawl;

use App\Jobs\CrawlPage;
use App\Jobs\CrawlSitemap;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spend the crawl budget on pages that describe the business.
 *
 * A storefront's about, shipping and collection pages say what the company is
 * and who it serves. Its product pages repeat the same three sentences with a
 * different noun — useful for the first handful so the platform knows what is
 * sold, noise after that, and each one costs a headless render and a paid
 * embedding.
 *
 * Start2finish offered 15,326 product URLs against roughly thirty pages that
 * described the business. Unordered, the budget fills with dresses and the
 * about page is never crawled at all.
 */
class CrawlPrioritisationTest extends TestCase
{
    use DatabaseTransactions;

    private function sitemapJob(): CrawlSitemap
    {
        Customer::unsetEventDispatcher();

        return new CrawlSitemap(
            User::factory()->create(),
            'https://shop.test/sitemap.xml',
            Customer::factory()->create()->id,
        );
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function prioritise(array $urls): array
    {
        $job = $this->sitemapJob();

        $jobs = array_map(
            fn ($url) => new CrawlPage(User::factory()->make(), $url, 1),
            $urls,
        );

        $method = new \ReflectionMethod(CrawlSitemap::class, 'prioritise');
        $method->setAccessible(true);

        return array_map(fn ($j) => $j->url, $method->invoke($job, $jobs));
    }

    public function test_pages_that_describe_the_business_come_first(): void
    {
        Http::fake();

        $ordered = $this->prioritise([
            'https://shop.test/products/dress-1',
            'https://shop.test/pages/about',
            'https://shop.test/products/dress-2',
            'https://shop.test/collections/winter',
        ]);

        // If the budget runs out mid-list it must be products that are lost,
        // not the about page.
        $this->assertSame('https://shop.test/pages/about', $ordered[0]);
        $this->assertSame('https://shop.test/collections/winter', $ordered[1]);
    }

    public function test_product_pages_are_capped_at_their_share(): void
    {
        Http::fake();
        config(['crawl.max_pages_per_site' => 400, 'crawl.product_page_share' => 0.25]);

        $urls = array_map(fn ($i) => "https://shop.test/products/item-{$i}", range(1, 500));
        $ordered = $this->prioritise($urls);

        // 25% of 400. The rest of the budget stays available for pages that
        // say something about the business.
        $this->assertCount(100, $ordered);
    }

    public function test_a_small_catalogue_is_taken_whole(): void
    {
        Http::fake();
        config(['crawl.max_pages_per_site' => 400, 'crawl.product_page_share' => 0.25]);

        // Capping is about diminishing returns, not about products being
        // unwanted — a shop with twelve products should have all twelve read.
        $urls = array_map(fn ($i) => "https://shop.test/products/item-{$i}", range(1, 12));

        $this->assertCount(12, $this->prioritise($urls));
    }

    public function test_a_site_with_no_products_is_untouched(): void
    {
        Http::fake();

        $urls = [
            'https://shop.test/pages/about',
            'https://shop.test/pages/contact',
            'https://shop.test/blogs/news/launch',
        ];

        $this->assertCount(3, $this->prioritise($urls));
    }
}
