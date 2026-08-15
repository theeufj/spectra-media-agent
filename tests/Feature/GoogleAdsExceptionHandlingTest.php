<?php

namespace Tests\Feature;

use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;
use Tests\TestCase;

/**
 * The exception class the Google Ads services catch must be the real one.
 *
 * Seventy-nine services caught the class under V22\Errors rather than the real
 * one, which the SDK does not define — it lives under Lib\V22. PHP does not
 * complain about a catch clause naming a missing class; the clause simply never
 * matches, so every one of those handlers was dead and each API error escaped
 * the code written to contain it. Seventy-two of the files had no fallback
 * catch at all.
 *
 * Nothing about that is visible by reading the file: the import looks right, the
 * catch looks right, and the failure only appears when Google returns an error.
 * So it is worth asserting directly.
 */
class GoogleAdsExceptionHandlingTest extends TestCase
{
    public function test_the_exception_class_exists(): void
    {
        $this->assertTrue(class_exists(GoogleAdsException::class));
    }

    public function test_the_old_namespace_is_still_absent(): void
    {
        // If a future SDK adds this class, the original imports would start
        // working and this test can go. Until then its absence is the whole
        // reason the handlers were dead.
        // Assembled rather than written out, so that this file does not itself
        // contain the string the scan below looks for.
        $missing = 'Google\\Ads\\GoogleAds\\V22\\'.'Errors\\GoogleAdsException';

        $this->assertFalse(class_exists($missing), 'if this now exists, revisit the import fix');
    }

    public function test_no_service_still_imports_the_missing_class(): void
    {
        $offenders = [];

        // Split so this file is not its own first match.
        $needle = 'GoogleAds\\V22\\'.'Errors\\GoogleAdsException';

        foreach (['app', 'tests'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir))
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());

                    if (str_contains($contents, $needle)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'these files catch a class that does not exist: '.implode(', ', $offenders));
    }

    public function test_a_catch_clause_naming_a_missing_class_never_matches(): void
    {
        // The behaviour that made this survive review. PHP raises nothing for an
        // unknown class in a catch clause — it just does not match, and the
        // exception carries on past the handler.
        $token = bin2hex(random_bytes(6));
        $escaped = '';

        try {
            try {
                throw new \RuntimeException($token);
            } catch (\Totally\Missing\ExceptionClass $e) { // @phpstan-ignore-line
                $this->fail('a missing class should never match');
            }
        } catch (\RuntimeException $e) {
            $escaped = $e->getMessage();
        }

        $this->assertSame($token, $escaped, 'the exception should escape a catch clause naming a missing class');
    }
}
