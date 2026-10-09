<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicContentHtmlTest extends TestCase
{
    use DatabaseTransactions;

    public static function marketingPages(): array
    {
        return array_map(fn ($path) => [$path], ['/', '/features', '/how-it-works', '/pricing', '/about']);
    }

    #[DataProvider('marketingPages')]
    public function test_public_product_content_is_readable_before_javascript(string $path): void
    {
        $response = $this->get('https://sitetospend.com'.$path)->assertOk();
        $props = $response->viewData('page')['props'];
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $headings = $xpath->query('//*[@id="app"]//h1');
        $this->assertSame(1, $headings->length);
        $this->assertSame($props['publicContent']['headline'], trim($headings->item(0)->textContent));
        foreach ($props['publicContent']['links'] as $link) {
            $this->assertGreaterThan(0, $xpath->query('//*[@id="app"]//a[@href="'.$link['href'].'"]')->length);
        }
        $page = json_decode($xpath->evaluate('string(//*[@id="app"]/@data-page)'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($props['publicContent'], $page['props']['publicContent']);
    }

    public function test_crawlers_and_people_receive_the_same_product_copy(): void
    {
        $person = $this->get('https://sitetospend.com/features')->assertOk()->viewData('page')['props']['publicContent'];
        $crawler = $this->withHeader('User-Agent', 'Googlebot')->get('https://sitetospend.com/features')->assertOk()->viewData('page')['props']['publicContent'];
        $this->assertSame($person, $crawler);
    }

    public function test_the_property_skin_keeps_its_own_public_product_copy(): void
    {
        $response = $this->get('https://realpropertyads.com/')->assertOk();
        $content = $response->viewData('page')['props']['publicContent'];
        $this->assertSame('Google Ads built around your property business', $content['headline']);
        $this->assertStringContainsString('<h1', $response->getContent());
        $this->assertStringContainsString($content['headline'], $response->getContent());
    }

    public function test_private_application_pages_do_not_render_public_product_content(): void
    {
        $response = $this->get('/login')->assertOk();
        $this->assertStringNotContainsString('Product and campaign guides', $response->getContent());
        $this->assertNull($response->viewData('page')['props']['publicContent'] ?? null);
    }
}
