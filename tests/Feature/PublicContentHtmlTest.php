<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\MarketingPages;
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

    public function test_google_management_price_and_actions_are_visible_before_the_article_and_match_react_props(): void
    {
        Plan::query()->update(['is_active' => false]);
        $starting = $this->heroPlan('hero-monthly', 19_875);
        $this->heroPlan('hero-growth', 28_800);
        $this->heroPlan('hero-inactive', 100, ['is_active' => false]);
        $this->heroPlan('hero-annual', 200, ['billing_interval' => 'year']);
        $this->heroPlan('hero-free', 300, ['is_free' => true]);
        $this->heroPlan('hero-custom', 0);
        $this->heroPlan('setup_only', 400);

        $response = $this->get('https://sitetospend.com/google-ads-management')->assertOk();
        $props = $response->viewData('page')['props'];
        $hero = $props['publicContent']['hero'];
        $this->assertSame('Google Ads management from US$198.75/month', $hero['headline']);
        $this->assertSame('Advertising spend is additional. Subscription prices are in US dollars.', $hero['note']);
        $this->assertStringContainsString('researches keywords, prepares Google Ads copy and monitors your campaign', $hero['intro']);
        $this->assertSame('Small Business Google Ads Management | sitetospend', $props['meta']['title']);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame($hero['headline'], trim($xpath->evaluate('string(//*[@id="app"]//main//h1)')));
        $this->assertGreaterThan(0, $xpath->query('//*[@id="app"]//main//p[normalize-space(.)="'.$hero['note'].'" and following::article]')->length);
        foreach (['primaryCta', 'secondaryCta'] as $key) {
            $action = $hero[$key];
            $this->assertGreaterThan(0, $xpath->query('//*[@id="app"]//main//a[@href="'.$action['href'].'" and normalize-space(.)="'.$action['label'].'" and following::article]')->length);
        }
        $page = json_decode($xpath->evaluate('string(//*[@id="app"]/@data-page)'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($hero, $page['props']['publicContent']['hero']);
        $this->assertStringContainsString($props['service']['content'], $response->getContent());

        $starting->update(['price_cents' => 21_901]);
        $updated = $this->get('https://sitetospend.com/google-ads-management')->assertOk()->viewData('page')['props'];
        $this->assertSame('Google Ads management from US$219.01/month', $updated['publicContent']['hero']['headline']);
    }

    public function test_google_management_hero_does_not_invent_a_price_without_a_paid_monthly_plan(): void
    {
        Plan::query()->update(['is_active' => false]);
        $this->heroPlan('hero-annual', 8_000, ['billing_interval' => 'year']);
        $this->heroPlan('hero-custom', 0);
        $response = $this->get('https://sitetospend.com/google-ads-management')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame($props['service']['headline'], $props['publicContent']['hero']['headline']);
        $this->assertStringNotContainsString('US$', $props['publicContent']['hero']['headline']);
        $this->assertSame('/pricing', $props['publicContent']['hero']['secondaryCta']['href']);
    }

    public function test_other_service_pages_keep_their_original_hero_and_one_time_fee(): void
    {
        config(['services.stripe.setup_fee_usd_cents' => 147_550]);
        foreach (['google-ads-setup', 'ai-ads-management'] as $slug) {
            $response = $this->get('https://sitetospend.com/'.$slug)->assertOk();
            $props = $response->viewData('page')['props'];
            $service = MarketingPages::find($slug, '1,475.50');
            $this->assertNull($props['publicContent']['hero']);
            $this->assertSame($service['headline'], $props['service']['headline']);
            $this->assertSame($service['intro'], $props['service']['intro']);
            if ($slug === 'google-ads-setup') {
                $this->assertStringContainsString('US$1,475.50', $props['service']['headline']);
                $this->assertStringNotContainsString('Current management plans', $response->getContent());
            }
        }
    }

    private function heroPlan(string $slug, int $priceCents, array $overrides = []): Plan
    {
        return Plan::updateOrCreate(['slug' => $slug], [
            'name' => $slug,
            'price_cents' => $priceCents,
            'billing_interval' => 'month',
            'is_active' => true,
            'is_free' => false,
            ...$overrides,
        ]);
    }
}
