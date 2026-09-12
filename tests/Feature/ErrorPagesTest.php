<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * An error page is still our page.
 *
 * All five were dark navy with a purple gradient numeral and a purple button —
 * a palette and typeface used nowhere else in the product. Someone who
 * mistyped a URL or followed a stale link was shown something that looked like
 * it belonged to a different company, with no navigation and one way out.
 *
 * They also said nothing useful: "The page you're looking for doesn't exist or
 * has been moved" is true of every 404 ever written, and gives the reader
 * nothing to do next.
 *
 * These read the shipped templates rather than rendering them, and the layout
 * is included rather than extended: @extends/@yield leaves an output buffer
 * open when a response is captured in a test, which marks every test that
 * touches an error page risky. The two real requests at the end cover the
 * wiring.
 */
class ErrorPagesTest extends TestCase
{
    private function template(string $name): string
    {
        $path = resource_path("views/errors/{$name}.blade.php");

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        // Keys are words, not numbers: PHP casts numeric-string array keys to
        // int, which no longer matches the declared array<string, …>.
        return [
            'not found' => ['404'],
            'forbidden' => ['403'],
            'expired session' => ['419'],
            'server error' => ['500'],
            'maintenance' => ['503'],
        ];
    }

    public function test_the_shared_layout_uses_the_products_own_palette(): void
    {
        $layout = $this->template('layout');

        // The old navy ground and indigo accent.
        $this->assertStringNotContainsString('#0f172a', $layout);
        $this->assertStringNotContainsString('#6366f1', $layout);
        $this->assertStringContainsString('#ff4d00', $layout);
    }

    public function test_the_shared_layout_offers_a_way_out(): void
    {
        $layout = $this->template('layout');

        $this->assertStringContainsString('support-tickets/create', $layout);
        $this->assertStringContainsString('Back to dashboard', $layout);
        $this->assertStringContainsString('Back to home', $layout);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_every_error_page_uses_the_shared_layout(string $status): void
    {
        $this->assertStringContainsString("@include('errors.layout'", $this->template($status));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_every_error_page_says_something_specific(string $status): void
    {
        $page = $this->template($status);

        $this->assertStringNotContainsString("doesn't exist or has been moved", $page);
        $this->assertMatchesRegularExpression("/'message' => '.{40,}'/s", $page);
    }

    public function test_a_missing_url_serves_the_new_page(): void
    {
        $this->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Back to home');
    }

    public function test_the_missing_url_page_is_not_the_old_dark_one(): void
    {
        $html = $this->get('/this-route-does-not-exist')->getContent();

        $this->assertStringNotContainsString('#0f172a', $html);
        $this->assertStringContainsString('#ff4d00', $html);
    }
}
