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
        $starting = $this->heroPlan('starter', 9_900, ['stripe_price_id' => 'price_starter']);
        $this->heroPlan('hero-growth', 28_800);
        $this->heroPlan('hero-inactive', 100, ['is_active' => false]);
        $this->heroPlan('hero-annual', 200, ['billing_interval' => 'year']);
        $this->heroPlan('hero-free', 300, ['is_free' => true]);
        $this->heroPlan('hero-custom', 0);
        $this->heroPlan('setup_only', 400);

        $response = $this->get('https://sitetospend.com/google-ads-management')->assertOk();
        $props = $response->viewData('page')['props'];
        $hero = $props['publicContent']['hero'];
        $this->assertSame(['href' => '/#build-my-ads', 'label' => 'Build my ads'], $hero['primaryCta']);
        $this->assertSame('Google Ads management for US$99/month', $hero['headline']);
        $this->assertSame('Advertising spend is additional. Subscription prices are in US dollars.', $hero['note']);
        $this->assertStringContainsString('one small business website', $hero['intro']);
        $this->assertStringContainsString('approve the campaign and set a separate advertising budget before launch', $hero['intro']);
        $this->assertSame('Google Ads Management for US$99/month | Site to Spend', $props['meta']['title']);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame($hero['headline'], trim($xpath->evaluate('string(//*[@id="app"]//main//h1)')));
        $this->assertSame($hero['intro'], trim($xpath->evaluate('string(//*[@id="app"]//main//h1/following-sibling::p[1])')));
        $this->assertSame(1, $xpath->query('//*[@id="app"]//article//a[@href="/#sample-report"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="app"]//article//a[@href="/terms-of-service#early-exit"]')->length);
        $this->assertStringContainsString('fictional leak-repair campaign report', $xpath->evaluate('string(//*[@id="app"]//article)'));

        $graph = json_decode($xpath->evaluate('string(//script[@type="application/ld+json"])'), true, 512, JSON_THROW_ON_ERROR)['@graph'];
        $service = collect($graph)->firstWhere('@type', 'Service');
        $this->assertSame($hero['headline'], $service['name']);
        $this->assertSame($props['meta']['description'], $service['description']);
        $this->assertSame('Offer', $service['offers']['@type']);
        $this->assertSame('99.00', $service['offers']['price']);
        $this->assertSame('USD', $service['offers']['priceCurrency']);
        $this->assertSame('P1M', $service['offers']['priceSpecification']['billingDuration']);
        $questions = collect($graph)->firstWhere('@type', 'FAQPage')['mainEntity'];
        foreach ($questions as $question) {
            $this->assertStringContainsString($question['acceptedAnswer']['text'], $xpath->evaluate('string(//*[@id="app"]//main//dl)'));
        }
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
        $this->assertSame($updated['service']['headline'], $updated['publicContent']['hero']['headline']);
        $this->assertEmpty($updated['plans']);
        $this->assertArrayNotHasKey('offers', collect($updated['meta']['schema'])->firstWhere('@type', 'Service'));
        $this->assertStringNotContainsString('US$99/month', $updated['meta']['description']);
        $this->assertStringContainsString('Paid signup is currently unavailable', $updated['service']['faqs'][0]['answer']);
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
        $this->get('https://sitetospend.com/pricing')->assertOk()
            ->assertSee('Paid signup is currently unavailable')
            ->assertSee('href="/terms-of-service#early-exit"', false);
    }

    public function test_crawler_summaries_describe_the_same_flagship_offer_and_link_to_its_current_availability(): void
    {
        $summary = file_get_contents(public_path('llms.txt'));
        $expanded = file_get_contents(public_path('llms-full.txt'));
        $this->assertStringStartsWith($summary, $expanded);
        $this->assertStringContainsString('US$'.number_format(Plan::STARTER_MONTHLY_PRICE_CENTS / 100).'/month', $summary);
        $this->assertStringContainsString('https://sitetospend.com/pricing', $summary);
        $this->assertStringContainsString('https://sitetospend.com/google-ads-management', $summary);
        $this->assertStringContainsString('https://sitetospend.com/terms-of-service#early-exit', $summary);
        $this->assertStringNotContainsString('https://sitetospend.com/google-ads-setup', $expanded);
    }

    public function test_service_explains_the_existing_account_exception_with_current_checkout_terms(): void
    {
        config(['services.stripe.setup_fee_usd_cents' => 120_550, 'billing.early_exit.minimum_months' => 1]);
        $service = $this->get('https://sitetospend.com/google-ads-management')->assertOk();
        $service->assertSeeText('within your first paid month')
            ->assertSeeText('US$1,205.50 setup fee can apply, less subscription fees already paid')
            ->assertSeeText('Accounts we provision and operate for you are exempt');
        config(['billing.early_exit.minimum_months' => 5]);
        $this->get('https://sitetospend.com/google-ads-management')->assertOk()->assertSeeText('within your first 5 paid months');
        config(['billing.early_exit.minimum_months' => 0]);
        $this->get('https://sitetospend.com/google-ads-management')->assertOk()->assertDontSeeText('US$1,205.50');
    }

    public function test_other_service_pages_keep_their_original_hero_and_one_time_fee(): void
    {
        config(['services.stripe.setup_fee_usd_cents' => 147_550]);
        foreach (['google-ads-setup', 'ai-ads-management'] as $slug) {
            $response = $this->get('https://realpropertyads.com/'.$slug)->assertOk();
            $props = $response->viewData('page')['props'];
            $service = MarketingPages::find($slug, '1,475.50');
            $response->assertSee('href="/#build-my-ads"', false);
            $this->assertNull($props['publicContent']['hero']);
            $this->assertSame($service['headline'], $props['service']['headline']);
            $this->assertSame($service['intro'], $props['service']['intro']);
            if ($slug === 'google-ads-setup') {
                $this->assertStringContainsString('US$1,475.50', $props['service']['headline']);
                $this->assertStringNotContainsString('Current management plans', $response->getContent());
            }
        }
    }

    public function test_every_service_fallback_links_to_the_homepage_preview_before_registration(): void
    {
        foreach (['google-ads-management', 'ai-ads-management'] as $slug) {
            $response = $this->get('https://sitetospend.com/'.$slug)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertGreaterThan(0, $xpath->query('//*[@id="app"]//main//a[@href="/#build-my-ads" and normalize-space(.)="Build my ads" and following::article]')->length);
            $this->assertSame(0, $xpath->query('//*[@id="app"]//a[starts-with(@href, "/register")] | //*[@id="app"]//form')->length);
        }

        $this->get('https://sitetospend.com/google-ads-setup?gclid=sample')->assertStatus(301)
            ->assertRedirect('https://sitetospend.com/google-ads-management?gclid=sample');
        $this->get('https://sitetospend.com/')->assertOk()
            ->assertSee('id="build-my-ads"', false)
            ->assertSee('id="sample-report"', false)
            ->assertSee('Illustrative report · Fictional campaign');
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
