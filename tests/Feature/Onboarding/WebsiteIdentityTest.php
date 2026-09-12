<?php

namespace Tests\Feature\Onboarding;

use App\Services\Onboarding\PlaceholderSiteDetector;
use App\Services\Onboarding\WebsiteIdentity;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Two real signups, both lost in the first minute, both for the same reason:
 * the product told them what their business was and was confidently wrong.
 *
 * One pasted a bit.ly link to her trucking brokerage and had her business
 * created as "Bit.ly". The other entered a parked domain, and the extractor
 * described the domain broker squatting on it — scoring itself 94 out of 100,
 * because the score measures how cleanly the page parsed and a parking page
 * parses beautifully.
 */
class WebsiteIdentityTest extends TestCase
{
    private function identity(): WebsiteIdentity
    {
        return new WebsiteIdentity;
    }

    public function test_a_shortener_is_followed_to_the_real_site(): void
    {
        Http::fake([
            'bit.ly/*' => Http::response('', 200, []),
        ]);

        // The fake cannot model a redirect chain, so this pins the narrower
        // contract the resolver depends on: a shortener is recognised as one.
        $this->assertTrue($this->identity()->isShortener('bit.ly'));
        $this->assertTrue($this->identity()->isShortener('www.t.co'));
        $this->assertFalse($this->identity()->isShortener('lisbeth.com'));
        $this->assertFalse(
            $this->identity()->isShortener('my.co'),
            'a two-letter TLD is not a shortener — guessing would rename real businesses',
        );
    }

    public function test_an_unreachable_shortener_leaves_the_url_alone_rather_than_failing_signup(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertSame(
            'https://bit.ly/truck-brokerage-services',
            $this->identity()->resolve('https://bit.ly/truck-brokerage-services'),
        );
    }

    public function test_an_ordinary_url_is_not_fetched_at_all(): void
    {
        Http::preventStrayRequests();

        // Only shorteners are followed. An ordinary site redirecting to www is
        // not worth a request per signup.
        $this->assertSame(
            'https://lisbeth.com',
            $this->identity()->resolve('https://lisbeth.com'),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'the shortener that became a business name' => ['https://bit.ly/truck-brokerage', 'Bit'],
            'a plain domain' => ['https://lisbeth.com', 'Lisbeth'],
            'www is not part of the name' => ['https://www.acme.com', 'Acme'],
            'hyphens are spaces a domain could not hold' => ['https://cherished-domains.com', 'Cherished Domains'],
            'a compound tld keeps the label in front of it' => ['https://acme.co.uk', 'Acme'],
            'an australian compound tld' => ['https://joesplumbing.com.au', 'Joesplumbing'],
            'a subdomain does not become the name' => ['https://shop.acme.co.uk', 'Acme'],
            'a bare host with no scheme' => ['example.org', 'Example'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function test_the_business_name_comes_from_the_domain_not_the_host(string $url, string $expected): void
    {
        // ucfirst(str_replace('www.', '', $host)) gave "Bit.ly", "Lisbeth.com"
        // and "Cherished-domains.com".
        $this->assertSame($expected, $this->identity()->businessName($url));
    }

    public function test_a_domain_for_sale_page_is_flagged_rather_than_described(): void
    {
        // The opening of what the crawler actually stored for lisbeth.com.
        $parked = 'Cherished Domains Services Why Us About Contact PREMIUM DOMAIN BROKERAGE '
            .'CherishedDomains.com We help individuals and businesses secure premium domain names '
            .'through discreet outreach, professional negotiation, and expert guidance. Whether '
            .'you are building a new brand or upgrading your digital identity, we represent your '
            .'interests throughout the acquisition process and handle every stage of the transfer.';

        $warning = (new PlaceholderSiteDetector)->warningFor($parked, 'https://lisbeth.com');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('lisbeth.com', $warning);
    }

    /** @return array<string, array{string}> */
    public static function placeholders(): array
    {
        return [
            'for sale' => ['This domain is for sale. Click here to make an offer on this domain today.'],
            'marketplace' => ['Buy this domain. Listed on Afternic. The owner of this domain has it listed for sale.'],
            'not launched' => ['Coming soon! Our website is under construction, please check back shortly.'],
            'default server page' => ['Welcome to nginx! If you see this page, the web server is successfully installed.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('placeholders')]
    public function test_placeholder_pages_are_caught(string $content): void
    {
        $this->assertNotNull((new PlaceholderSiteDetector)->warningFor($content, 'https://example.com'));
    }

    public function test_a_real_business_page_is_not_flagged(): void
    {
        // Long enough and with none of the signals. A false positive is
        // recoverable — the customer is asked to confirm — but it must not be
        // the normal case.
        $real = str_repeat(
            'Joe\'s Plumbing has served Adelaide since 1998. We handle blocked drains, hot water '
            .'systems, gas fitting and emergency callouts, with licensed tradespeople and a '
            .'lifetime guarantee on workmanship. Book online or call us. ',
            6,
        );

        $this->assertNull((new PlaceholderSiteDetector)->warningFor($real, 'https://joesplumbing.com.au'));
    }

    public function test_a_nearly_empty_page_is_flagged(): void
    {
        $this->assertNotNull((new PlaceholderSiteDetector)->warningFor('Hello.', 'https://example.com'));
        $this->assertNotNull((new PlaceholderSiteDetector)->warningFor('', 'https://example.com'));
    }
}
