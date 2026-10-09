<?php

namespace Tests\Feature;

use App\Support\HelpArticles;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicContentDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_blog_pagination_exposes_articles_and_following_pages_without_javascript(): void
    {
        $html = $this->get('/blog?page=2')->assertOk()->getContent();
        $dom = $this->document($html);

        $this->assertSame(1, $dom->query('//*[@id="app"]//h1')->length);
        $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//a[@href="/blog?page=3"]')->length);
        $this->assertSame(9, $dom->query('//*[@id="app"]//main//article')->length);
        $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//a[@href="/blog/why-your-google-ads-stop-working"]')->length);
        $this->assertSame('https://sitetospend.com/blog?page=2', $dom->evaluate('string(//link[@rel="canonical"]/@href)'));
    }

    public function test_category_and_page_are_normalised_for_the_rendered_listing(): void
    {
        $response = $this->get('/blog?page=999&category=Unknown')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(3, $props['pagination']['current_page']);
        $this->assertNull($props['activeCategory']);
        $this->assertSame('https://sitetospend.com/blog?page=3', $props['meta']['canonical']);
        $this->assertCount(2, $props['articles']);
    }

    public function test_article_serves_the_same_body_and_relevant_links_before_javascript(): void
    {
        $html = $this->get('/blog/how-conversion-tracking-works')->assertOk()->getContent();
        $dom = $this->document($html);

        $this->assertSame(1, $dom->query('//*[@id="app"]//h1')->length);
        $this->assertStringContainsString('quote_request_success', $dom->query('//*[@id="app"]//article')->item(0)->textContent);
        $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//a[@href="/google-ads-management"]')->length);
        $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//aside//a[@href="/blog/what-is-smart-bidding"]')->length);
        $this->assertStringContainsString('Hypothetical', $dom->query('//*[@id="app"]//article')->item(0)->textContent);
    }

    public function test_commercial_pages_have_unique_server_visible_copy_and_the_configured_setup_price(): void
    {
        config(['services.stripe.setup_fee_usd_cents' => 123456]);
        $headings = [];
        foreach (['/google-ads-management', '/ai-ads-management', '/google-ads-setup'] as $url) {
            $dom = $this->document($this->get($url)->assertOk()->getContent());
            $this->assertSame(1, $dom->query('//*[@id="app"]//h1')->length);
            $this->assertGreaterThanOrEqual(5, $dom->query('//*[@id="app"]//main//article/h2')->length);
            $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//a[@href="/blog/how-conversion-tracking-works"]')->length);
            $headings[] = $dom->query('//*[@id="app"]//h1')->item(0)->textContent;
            if ($url === '/google-ads-setup') {
                $this->assertStringContainsString('US$1,234.56', $headings[2]);
                $this->assertStringContainsString('handed over paused', $dom->query('//*[@id="app"]//main//article')->item(0)->textContent);
            }
        }
        $this->assertCount(3, array_unique($headings));
    }

    public function test_authored_guide_links_point_to_existing_guides(): void
    {
        foreach (HelpArticles::all() as $article) {
            preg_match_all('#href="(/blog/([^"]+))"#', $article['content'], $matches);
            foreach ($matches[2] as $slug) {
                $this->assertNotNull(HelpArticles::find($slug), $article['slug'].' links to a missing guide: '.$slug);
            }
        }
    }

    public function test_every_guide_has_a_readable_body_and_valid_search_metadata(): void
    {
        foreach (HelpArticles::all() as $article) {
            $response = $this->get('/blog/'.$article['slug'])->assertOk();
            $dom = $this->document($response->getContent());
            $props = $response->viewData('page')['props'];
            $this->assertSame(1, $dom->query('//*[@id="app"]//h1')->length, $article['slug']);
            $this->assertSame($article['title'], $dom->evaluate('string(//*[@id="app"]//h1)'));
            $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//main//article//ol | //*[@id="app"]//main//article//ul')->length);
            $this->assertGreaterThan(0, $dom->query('//*[@id="app"]//main//article//a[starts-with(@href, "/blog/")]')->length);
            $this->assertLessThanOrEqual(60, mb_strlen($props['meta']['title']), $article['slug']);
            $this->assertLessThanOrEqual(155, mb_strlen($props['meta']['description']), $article['slug']);
            $this->assertSame('https://sitetospend.com/blog/'.$article['slug'], $props['meta']['canonical']);
        }
    }

    public function test_legal_pages_serve_existing_contract_copy_before_javascript(): void
    {
        foreach (['/terms-of-service' => 'terms', '/privacy-policy' => 'privacy'] as $url => $source) {
            $dom = $this->document($this->get($url)->assertOk()->getContent());
            $this->assertSame(1, $dom->query('//*[@id="app"]//h1')->length);
            $this->assertStringContainsString('Contact Us', $dom->query('//*[@id="app"]//article')->item(0)->textContent);
            $this->assertStringContainsString('Last updated:', file_get_contents(resource_path('legal/'.$source.'.html')));
        }
    }
}
