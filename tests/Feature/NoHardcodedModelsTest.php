<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * config/ai.php is the only place a model name may be written.
 *
 * CLAUDE.md has said "never hardcode a model string" since the AI stack
 * settled, and twelve of them had accumulated anyway — mostly as the *second*
 * argument to config(), which is the shape that hides best. `config('ai.models.image',
 * 'gemini-2.5-flash-image')` reads as compliant and pins a two-generation-old
 * model the moment the key is missing. DemoController was still defaulting to
 * gemini-1.5-flash-latest that way.
 *
 * Model names are values, and values belong in config where the cost table and
 * the fallback chain can see them: a model referenced from code but absent from
 * config/ai.php's pricing table has its spend recorded as zero.
 */
class NoHardcodedModelsTest extends TestCase
{
    /**
     * Files allowed to name a model: the config itself, and the tests that
     * assert on specific model behaviour.
     */
    private const ALLOWED = [
        'config/ai.php',
    ];

    private const PATTERN = "/'(gemini|gpt|claude|grok|veo|imagen|x-ai)[-\/][a-zA-Z0-9._-]+'/";

    public function test_no_model_name_is_written_outside_the_ai_config(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $relative = str_replace(base_path().'/', '', $file);

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);

            foreach ($lines as $i => $line) {
                if (preg_match(self::PATTERN, $line, $m)) {
                    $offenders[] = sprintf('%s:%d  %s', $relative, $i + 1, trim($m[0]));
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Model names belong in config/ai.php, read back through',
            "config('ai.models.*'). Watch for the config() second argument —",
            'a default there is a hardcoded model that only bites when the key',
            'is missing, which is exactly when nobody is looking.',
            '',
            ...$offenders,
        ]));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach ([app_path(), config_path()] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
