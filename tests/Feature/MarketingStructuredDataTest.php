<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The marketing pages' structured data has to be in the HTML the server sends,
 * not built by React afterwards.
 *
 * This app has no Inertia SSR, so a page component contributes nothing to the
 * response body — `curl /` returns one empty div. Google's renderer runs the
 * JavaScript and gets there eventually; GPTBot, ClaudeBot, PerplexityBot,
 * Google-Extended and Applebot-Extended do not, and public/robots.txt names
 * every one of them and lets them in. A JSON-LD block written inside a React
 * <Head> is therefore invisible to precisely the crawlers it was written for,
 * which is where it lived until this test existed.
 *
 * These assertions are on the raw response string on purpose. Asserting on the
 * Inertia props would pass just as happily with the schema back in the JSX.
 */
class MarketingStructuredDataTest extends TestCase
{
    use DatabaseTransactions;

    /** Every ld+json block in a response, decoded. */
    private function schemaBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return array_map(
            fn (string $json) => json_decode(html_entity_decode($json), true, 512, JSON_THROW_ON_ERROR),
            $matches[1],
        );
    }

    public function test_the_home_page_serves_its_schema_without_javascript(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $blocks = $this->schemaBlocks($html);

        // One block, not two: the page used to emit its own copy client-side,
        // and a second FAQPage on the same URL is a structured-data error.
        $this->assertCount(1, $blocks, 'The home page should serve exactly one JSON-LD block.');
        $this->assertSame(['@context', '@graph'], array_keys($blocks[0]));
        $this->assertSame('https://schema.org', $blocks[0]['@context']);

        $types = array_column($blocks[0]['@graph'], '@type');
        $this->assertEqualsCanonicalizing(
            ['Organization', 'SoftwareApplication', 'FAQPage'],
            $types,
        );
    }

    public function test_the_rendered_questions_are_the_questions_in_the_markup(): void
    {
        $response = $this->get('/')->assertOk();

        $graph = $this->schemaBlocks($response->getContent())[0]['@graph'];
        $faqPage = collect($graph)->firstWhere('@type', 'FAQPage');

        $marked = array_column($faqPage['mainEntity'], 'name');
        $rendered = array_column($response->viewData('page')['props']['faqs'], 'question');

        // Google requires the answer to a marked-up question to be visible on
        // the page. One config array feeds both sides so it cannot drift; this
        // fails if someone reintroduces a second list.
        $this->assertSame($rendered, $marked);
        $this->assertNotEmpty($marked);
    }

    public function test_the_homepage_advertises_only_the_available_google_subscription(): void
    {
        $this->withoutExceptionHandling();
        $starter = Plan::updateOrCreate(['slug' => 'starter'], [
            'name' => 'Starter', 'price_cents' => 9900, 'is_active' => true,
            'is_free' => false, 'billing_interval' => 'month', 'stripe_price_id' => 'price_google_starter',
        ]);
        $this->makePlan('Test Growth', 18_300);

        $graph = $this->schemaBlocks($this->get('/')->assertOk()->getContent())[0]['@graph'];
        $offer = collect($graph)->firstWhere('@type', 'SoftwareApplication')['offers'];
        $this->assertSame('Offer', $offer['@type']);
        $this->assertSame('99.00', $offer['price']);
        $this->assertSame('USD', $offer['priceCurrency']);
        $this->assertSame('P1M', $offer['priceSpecification']['billingDuration']);

        config(['services.stripe.setup_fee_usd_cents' => 100_123, 'billing.early_exit.minimum_months' => 5]);
        $this->get('/pricing')->assertOk()
            ->assertSee('first 5 paid months')
            ->assertSee('US$1,001.23 setup fee')
            ->assertSee('that leaves US$902.23')
            ->assertSee('href="/terms-of-service#early-exit"', false);

        config(['billing.early_exit.minimum_months' => 1]);
        $this->get('/pricing')->assertOk()
            ->assertSee('your first paid month can trigger')
            ->assertDontSee('After one paid month');

        $starter->update(['is_active' => false]);
        config(['billing.early_exit.minimum_months' => 3]);
        $this->get('/pricing')->assertOk()
            ->assertSee('Paid signup is currently unavailable')
            ->assertSee('first 3 paid months')
            ->assertDontSee('After one paid month');
        $graph = $this->schemaBlocks($this->get('/')->assertOk()->getContent())[0]['@graph'];
        $this->assertArrayNotHasKey('offers', collect($graph)->firstWhere('@type', 'SoftwareApplication'));
    }

    public function test_the_setup_offer_remains_available_only_on_property_pricing(): void
    {
        foreach (['/', '/pricing'] as $url) {
            $response = $this->get('https://sitetospend.com'.$url)->assertOk();
            $response->assertDontSee('one-time', false);
            $this->assertArrayNotHasKey('setupFeeUsd', $response->viewData('page')['props']);
        }
        $faqs = $this->get('https://realpropertyads.com/pricing')->assertOk()->viewData('page')['props']['faqs'];
        $this->assertStringContainsString('US$'.$this->setupFee(), implode(' ', array_column($faqs, 'answer')));
    }

    public function test_the_pricing_page_serves_its_faq_schema_server_side(): void
    {
        $blocks = $this->schemaBlocks($this->get('/pricing')->assertOk()->getContent());

        $this->assertCount(1, $blocks);
        $this->assertSame(['FAQPage'], array_column($blocks[0]['@graph'], '@type'));
    }

    private function makePlan(string $name, int $priceCents): Plan
    {
        return Plan::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'price_cents' => $priceCents,
            'is_active' => true,
            'is_free' => false,
        ]);
    }

    /**
     * Every public URL, and what its metadata has to look like.
     *
     * @return array<string, array{0: string}>
     */
    public static function publicPages(): array
    {
        return [
            'home' => ['/'],
            'features' => ['/features'],
            'how it works' => ['/how-it-works'],
            'pricing' => ['/pricing'],
            'about' => ['/about'],
            'blog index' => ['/blog'],
            'blog article' => ['/blog/how-ai-agents-work'],
            'terms' => ['/terms-of-service'],
            'privacy' => ['/privacy-policy'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_a_public_page_declares_each_tag_exactly_once(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        // Inertia replaces only the tags it owns, so a <Head> in a page
        // component appended a second one rather than overriding the server's.
        // Six pages shipped two descriptions and two og:titles that said
        // different things, and every blog article shipped two canonicals
        // pointing at different hosts — which Google resolves by ignoring both.
        foreach ([
            'title' => '#<title[^>]*>#',
            'description' => '#<meta name="description"#',
            'canonical' => '#<link rel="canonical"#',
            'og:title' => '#<meta property="og:title"#',
            'og:description' => '#<meta property="og:description"#',
        ] as $label => $pattern) {
            $this->assertSame(
                1,
                preg_match_all($pattern, $html),
                "$url declares its $label more than once (or not at all).",
            );
        }
    }

    #[DataProvider('publicPages')]
    public function test_a_public_page_fits_a_search_result(string $url): void
    {
        $props = $this->get($url)->assertOk()->viewData('page')['props'];

        // Google truncates past roughly these lengths. /features shipped a
        // 191-character description because the client-side copy — the one
        // Google actually rendered — was never held to the limit the
        // controller's own docblock states.
        $this->assertLessThanOrEqual(60, mb_strlen($props['meta']['title']), "$url has an over-long title.");
        $this->assertLessThanOrEqual(155, mb_strlen($props['meta']['description']), "$url has an over-long description.");
        $this->assertNotEmpty($props['meta']['canonical']);
    }

    public function test_a_shared_blog_article_canonicalises_to_its_original_host(): void
    {
        $html = $this->get('/blog/how-ai-agents-work')->assertOk()->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m);

        // Publishing the same generic guide with an independent canonical on
        // every skin made Google select the RPA copy over the SiteToSpend one.
        $this->assertSame(
            'https://sitetospend.com/blog/how-ai-agents-work',
            $m[1],
        );
    }

    public function test_the_pages_that_carry_schema_serve_it_server_side(): void
    {
        foreach ([
            '/features' => ['ItemList'],
            '/how-it-works' => ['HowTo'],
            '/about' => ['AboutPage'],
            '/blog' => ['Blog'],
            '/blog/how-ai-agents-work' => ['Article', 'BreadcrumbList'],
        ] as $url => $expected) {
            $blocks = $this->schemaBlocks($this->get($url)->assertOk()->getContent());

            $this->assertCount(1, $blocks, "$url should serve exactly one JSON-LD block.");
            $this->assertSame($expected, array_column($blocks[0]['@graph'], '@type'), "$url has the wrong schema types.");
        }
    }

    /**
     * The vertical skin's pages have to carry the vertical's own metadata.
     *
     * RealEstateLanding is rendered by the same controller action as Landing and
     * used to inherit its meta wholesale, so realpropertyads.com served
     * "…| sitetospend" in the <title> and every Open Graph tag — to Facebook,
     * LinkedIn, Slack and every AI crawler, none of which run the JavaScript
     * that was overwriting it in the browser.
     */
    public function test_the_vertical_skin_does_not_serve_the_flagship_brand(): void
    {
        config(['tenants.override' => 'realpropertyads.com']);

        foreach (['/', '/how-it-works', '/pricing'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match('#<title[^>]*>([^<]*)</title>#', $html, $title);
            preg_match('#<meta property="og:title" content="([^"]*)"#', $html, $og);

            $this->assertStringNotContainsStringIgnoringCase('sitetospend', $title[1], "$url leaks the flagship brand into its title.");
            $this->assertStringNotContainsStringIgnoringCase('sitetospend', $og[1], "$url leaks the flagship brand into og:title.");
            $this->assertStringContainsString('Real Property Ads', $title[1], "$url does not name the vertical.");
        }
    }

    private function setupFee(): int
    {
        return (int) round(config('services.stripe.setup_fee_usd_cents') / 100);
    }
}
