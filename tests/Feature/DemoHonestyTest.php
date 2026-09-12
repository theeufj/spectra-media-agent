<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DemoController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The public demo is a stranger's first contact with the product, and it was
 * making things up.
 *
 * Asked to read yourfirststore.com, it rendered "Transform Your Business | Sign
 * Up Today" and "Discover why thousands trust our platform" under the heading
 * "Your AI-Generated Ad Package". Neither string came from the site or from
 * Gemini — both were hardcoded, one in the controller's catch block and one as
 * a `||` fallback in the panel, so an empty result was indistinguishable from a
 * confident one.
 *
 * Two things underneath it. `$adCopy` started as empty arrays, so a response
 * that returned but did not parse raised no exception and the catch never ran —
 * success came back true with nothing in it. And the text the prompt saw was a
 * static fetch's <title>, <meta description> and <h1>, which for a page that
 * builds itself in the browser is almost nothing: that site gives 52 characters
 * of visible text to a fetch and about 3,900 to Chromium, which this server is
 * already running for the screenshot in the same request.
 */
class DemoHonestyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_no_invented_ad_copy_survives_anywhere_in_the_stack(): void
    {
        $banned = [
            'Transform Your Business',
            'Leading Industry Solution',
            'Discover why thousands trust our platform',
            'The all-in-one solution you have been looking for',
            'Flexible pricing to suit any scale',
        ];

        // Comments stripped first: both files explain what they used to emit,
        // and quoting the old string in a comment is the opposite of the bug.
        $controller = self::withoutComments(file_get_contents(app_path('Http/Controllers/Api/DemoController.php')));
        $panel = self::withoutComments(file_get_contents(resource_path('js/Components/DemoResultsPanel.jsx')));

        foreach ($banned as $phrase) {
            // The controller may name these to forbid them in the prompt; it
            // must never emit them. The panel must not carry them at all
            // outside the comment explaining why.
            $this->assertStringNotContainsString(
                "'".$phrase,
                $controller,
                "DemoController still returns the invented phrase: {$phrase}",
            );
            $this->assertStringNotContainsString(
                '"'.$phrase,
                $panel,
                "DemoResultsPanel still falls back to the invented phrase: {$phrase}",
            );
        }
    }

    public function test_the_prompt_forbids_the_filler_it_used_to_produce(): void
    {
        // Squished: the prompt is a wrapped heredoc, so its sentences are
        // broken across lines in the source.
        $controller = (string) preg_replace(
            '/\s+/',
            ' ',
            file_get_contents(app_path('Http/Controllers/Api/DemoController.php')),
        );

        $this->assertStringContainsString('Do not write generic SaaS filler', $controller);
        $this->assertStringContainsString(
            'return empty arrays rather than inventing a business',
            $controller,
            'the model must be told that nothing is an acceptable answer',
        );
    }

    /**
     * Source with its comments removed, so an explanation of a removed string
     * does not read as the string still being there.
     */
    private static function withoutComments(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', ' ', $source);

        return (string) preg_replace('#^\s*//.*$#m', ' ', $source);
    }

    /** @return array<string, array{array<int, mixed>, list<string>}> */
    public static function fontCases(): array
    {
        return [
            'the browser default stack is not a brand' => [['Arial', 'Helvetica', 'sans-serif'], []],
            'a real typeface survives' => [['Gilroy', 'Arial'], ['Gilroy']],
            'quotes and padding are trimmed' => [['"Gilroy"', ' Inter '], ['Gilroy', 'Inter']],
            'system stacks are dropped' => [['-apple-system', 'BlinkMacSystemFont', 'Segoe UI'], []],
            'nothing in, nothing out' => [[], []],
        ];
    }

    /**
     * @param  array<int, mixed>  $input
     * @param  list<string>  $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fontCases')]
    public function test_only_typefaces_that_mean_something_are_reported(array $input, array $expected): void
    {
        // The demo listed "Arial, Helvetica" under Typography as though it had
        // discovered something. That is the absence of a choice.
        $method = new \ReflectionMethod(DemoController::class, 'brandFonts');

        $this->assertSame($expected, $method->invoke(null, $input));
    }
}
