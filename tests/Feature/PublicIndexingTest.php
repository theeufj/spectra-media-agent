<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicIndexingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Crawl headers and server-rendered identity do not depend on whether
        // the frontend manifest was rebuilt before this PHP test was run.
        $this->withoutVite();
    }

    public static function sharedPages(): array
    {
        return array_map(fn ($path) => [$path], [
            '/features', '/about', '/blog/how-ai-agents-work',
            '/terms-of-service', '/privacy-policy',
            '/google-ads-management', '/ai-ads-management', '/google-ads-setup',
        ]);
    }

    #[DataProvider('sharedPages')]
    public function test_shared_vertical_pages_prefer_the_original_site(string $path): void
    {
        $response = $this->get('https://realpropertyads.com'.$path)->assertOk();
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://sitetospend.com'.$path.'"',
            $response->getContent(),
        );
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_distinct_vertical_pages_keep_their_own_canonical_and_identity(): void
    {
        foreach (['/', '/pricing', '/how-it-works'] as $path) {
            $response = $this->get('https://realpropertyads.com'.$path)->assertOk();
            $this->assertStringContainsString(
                '<link rel="canonical" href="https://realpropertyads.com'.$path.'"',
                $response->getContent(),
            );
            $this->assertStringContainsString('Real Property Ads', $response->getContent());
            $response->assertHeaderMissing('X-Robots-Tag');
        }
    }

    public function test_a_preview_host_is_noindexed_even_when_serving_public_content(): void
    {
        $response = $this->get('https://'.config('tenants.forge_domain').'/features')->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('href="https://sitetospend.com/features"', $response->getContent());
    }

    public function test_account_forms_and_private_routes_cannot_be_indexed(): void
    {
        foreach (['/login', '/register', '/dashboard'] as $path) {
            $this->get('https://sitetospend.com'.$path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_blog_pagination_is_self_canonical_without_tracking_parameters(): void
    {
        $response = $this->get('https://sitetospend.com/blog?page=2&utm_source=google')->assertOk();
        $this->assertStringContainsString('href="https://sitetospend.com/blog?page=2"', $response->getContent());
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_blog_filter_combinations_are_noindexed_but_followable(): void
    {
        $this->get('https://sitetospend.com/blog?category=Google+Ads')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }
}
