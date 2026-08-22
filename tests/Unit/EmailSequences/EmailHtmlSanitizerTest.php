<?php

namespace Tests\Unit\EmailSequences;

use App\Services\EmailSequences\EmailHtmlSanitizer;
use App\Services\EmailSequences\SequenceBodyRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sanitiser is the only thing standing between admin-authored HTML and two
 * places that render it unescaped — the preview iframe and the email itself.
 *
 * These tests are written as "this exact string must not survive" rather than
 * "the output looks about right", because the failure mode is a payload that
 * passes a loose assertion.
 */
class EmailHtmlSanitizerTest extends TestCase
{
    private EmailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new EmailHtmlSanitizer;
    }

    // ── Things that must never survive ────────────────────────────────────

    /**
     * Named rather than positional so a failure names the payload that got
     * through, instead of "dataset #7".
     *
     * @return array<string, array{string, string}>
     */
    public static function dangerousInputs(): array
    {
        return [
            'script tag' => ['<p>Hi</p><script>alert(1)</script>', 'alert'],
            'inline event handler' => ['<p onclick="alert(1)">Hi</p>', 'onclick'],
            'javascript href' => ['<a href="javascript:alert(1)">Click</a>', 'javascript:'],
            'javascript href, mixed case' => ['<a href="JaVaScRiPt:alert(1)">Click</a>', 'javascript:'],
            'javascript href with a tab' => ["<a href=\"java\tscript:alert(1)\">Click</a>", 'script:'],
            'data uri image' => ['<img src="data:text/html;base64,PHNjcmlwdD4=">', 'data:'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>', 'iframe'],
            'style block' => ['<style>@import url(https://evil.test)</style><p>Hi</p>', 'import'],
            'css url() background' => ['<p style="background: url(https://evil.test/pixel.gif)">Hi</p>', 'url('],
            'css expression' => ['<p style="width: expression(alert(1))">Hi</p>', 'expression'],
            'form' => ['<form action="https://evil.test"><input name="card"></form>', '<form'],
            'object' => ['<object data="https://evil.test"></object>', 'object'],
            'svg' => ['<svg onload="alert(1)"></svg>', 'svg'],
            'srcdoc' => ['<img src="https://a.test/x.png" srcdoc="<script>alert(1)</script>">', 'srcdoc'],
        ];
    }

    #[DataProvider('dangerousInputs')]
    public function test_dangerous_markup_does_not_survive(string $input, string $forbidden): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            $forbidden,
            $this->sanitizer->sanitize($input),
        );
    }

    public function test_script_contents_are_removed_not_merely_unwrapped(): void
    {
        // Unwrapping a <script> would leave its source as visible body text,
        // which is a different bug with the same root cause.
        $output = $this->sanitizer->sanitize('<p>Hello</p><script>alert("boom")</script>');

        $this->assertStringNotContainsString('boom', $output);
        $this->assertStringContainsString('Hello', $output);
    }

    public function test_sibling_after_a_removed_node_is_still_sanitised(): void
    {
        // Removing a node while iterating the live DOMNodeList skips the next
        // sibling. That is how a sanitiser lets the second payload through.
        $output = $this->sanitizer->sanitize(
            '<script>alert(1)</script><p onclick="alert(2)">One</p><script>alert(3)</script><p onclick="alert(4)">Two</p>'
        );

        $this->assertStringNotContainsString('alert', $output);
        $this->assertStringNotContainsString('onclick', $output);
        $this->assertStringContainsString('One', $output);
        $this->assertStringContainsString('Two', $output);
    }

    // ── Things that must survive ──────────────────────────────────────────

    public function test_basic_formatting_is_kept(): void
    {
        $output = $this->sanitizer->sanitize(
            '<p><strong>Bold</strong> and <em>italic</em> and <u>underlined</u></p><ul><li>One</li></ul>'
        );

        foreach (['<strong>', '<em>', '<u>', '<ul>', '<li>'] as $tag) {
            $this->assertStringContainsString($tag, $output);
        }
    }

    public function test_safe_links_and_images_are_kept(): void
    {
        $output = $this->sanitizer->sanitize(
            '<a href="https://sitetospend.com">Visit</a><img src="https://cdn.test/logo.png" alt="Logo" width="200">'
        );

        $this->assertStringContainsString('https://sitetospend.com', $output);
        $this->assertStringContainsString('https://cdn.test/logo.png', $output);
        $this->assertStringContainsString('alt="Logo"', $output);
        $this->assertStringContainsString('width="200"', $output);
    }

    public function test_links_get_target_and_noopener(): void
    {
        $output = $this->sanitizer->sanitize('<a href="https://sitetospend.com">Visit</a>');

        $this->assertStringContainsString('target="_blank"', $output);
        $this->assertStringContainsString('noopener', $output);
    }

    public function test_mailto_and_tel_survive(): void
    {
        $output = $this->sanitizer->sanitize(
            '<a href="mailto:james@sitetospend.com">Email</a><a href="tel:+61400000000">Call</a>'
        );

        $this->assertStringContainsString('mailto:james@sitetospend.com', $output);
        $this->assertStringContainsString('tel:+61400000000', $output);
    }

    public function test_allowed_style_properties_survive_and_others_do_not(): void
    {
        $output = $this->sanitizer->sanitize(
            '<p style="color: #ff4d00; font-size: 18px; position: absolute; float: left">Hi</p>'
        );

        $this->assertStringContainsString('color: #ff4d00', $output);
        $this->assertStringContainsString('font-size: 18px', $output);
        // Positioning is dropped because Outlook ignores it — an email that
        // relies on it looks right only in the preview.
        $this->assertStringNotContainsString('position', $output);
        $this->assertStringNotContainsString('float', $output);
    }

    public function test_tables_survive_because_that_is_how_email_lays_out(): void
    {
        $output = $this->sanitizer->sanitize(
            '<table role="presentation" cellpadding="0"><tr><td bgcolor="#ff4d00" align="center">Button</td></tr></table>'
        );

        $this->assertStringContainsString('<table', $output);
        $this->assertStringContainsString('<td', $output);
        $this->assertStringContainsString('bgcolor="#ff4d00"', $output);
    }

    public function test_unknown_tags_are_unwrapped_rather_than_deleted(): void
    {
        // Losing the wrapper is acceptable; losing the sentence is not.
        $output = $this->sanitizer->sanitize('<section><p>Keep this sentence</p></section>');

        $this->assertStringContainsString('Keep this sentence', $output);
        $this->assertStringNotContainsString('<section', $output);
    }

    public function test_placeholders_survive_intact(): void
    {
        // Substitution runs before sending; a mangled placeholder would reach
        // a real prospect as its own name.
        $output = $this->sanitizer->sanitize('<p>Hi {{ first_name }}, about {{ website }}</p>');

        $this->assertStringContainsString('{{ first_name }}', $output);
        $this->assertStringContainsString('{{ website }}', $output);
    }

    public function test_a_placeholder_in_an_href_still_substitutes(): void
    {
        // Both parsing and serialising an href percent-encode the braces, so
        // this arrived as href="%7B%7B%20website%20%7D%7D" — a link pointing
        // nowhere in every rich email that used one. Asserting on the
        // substituted result rather than the exact braces, because the
        // whitespace inside them is not the property that matters.
        $output = $this->sanitizer->sanitize('<a href="{{ website }}">Your site</a>');

        $this->assertStringNotContainsString('%7B', $output);

        $substituted = (new SequenceBodyRenderer($this->sanitizer))
            ->substitute($output, ['website' => 'https://example.com']);

        $this->assertStringContainsString('href="https://example.com"', $substituted);
    }

    public function test_utf8_is_not_mangled(): void
    {
        $output = $this->sanitizer->sanitize('<p>Spend — £50 · “quoted” · café</p>');

        foreach (['—', '£50', '·', 'café'] as $fragment) {
            $this->assertStringContainsString($fragment, $output);
        }
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function test_relative_urls_are_dropped(): void
    {
        // An inbox has no base document, so a relative src is a broken image
        // for every recipient however it is treated.
        $output = $this->sanitizer->sanitize('<img src="/storage/logo.png" alt="Logo">');

        $this->assertStringNotContainsString('/storage/logo.png', $output);
    }
}
