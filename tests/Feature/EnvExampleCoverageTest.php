<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every setting this application invented has to be written down.
 *
 * 53 keys were readable from config/ and appeared nowhere in .env.example,
 * including every AI_MODEL_* override — the documented escape hatch for
 * CLAUDE.md's "never hardcode a model string" rule, which nobody could use
 * because nobody could find it. CI also does `cp .env.example .env`, so the
 * suite runs against whatever this file describes.
 *
 * Laravel's own config files are excluded: their keys are framework defaults
 * with sane values, and listing all 148 of them would bury the ones that matter.
 */
class EnvExampleCoverageTest extends TestCase
{
    /**
     * Config files this application wrote, as opposed to Laravel's stock ones.
     */
    private const PROJECT_CONFIGS = [
        'activity', 'ai', 'billing', 'budget_rules', 'campaigns', 'conversions',
        'crawl', 'email_sequences', 'feature_usage', 'first_campaign', 'googleads',
        'linkedinads', 'microsoftads', 'notification_templates', 'optimization',
        'platform_architecture', 'platform_rules', 'seasonal_strategies',
        'support_chat', 'tenants', 'verticals', 'services', 'browsershot',
        'filesystems', 'backup',
    ];

    public function test_every_project_env_key_appears_in_env_example(): void
    {
        $documented = $this->documentedKeys();
        $undocumented = [];

        foreach (self::PROJECT_CONFIGS as $name) {
            $path = config_path($name.'.php');

            if (! file_exists($path)) {
                continue;
            }

            preg_match_all("/env\('([A-Z0-9_]+)'/", file_get_contents($path), $matches);

            foreach ($matches[1] as $key) {
                if (! in_array($key, $documented, true)) {
                    $undocumented[$key] = $name;
                }
            }
        }

        ksort($undocumented);

        $this->assertSame([], $undocumented, implode("\n", [
            'These keys are read by config/ but are not in .env.example, so a',
            'fresh deploy has no way to know they exist. Add them with a blank',
            'value and a comment saying what changes when you set one.',
            '',
            ...array_map(fn ($k, $v) => "  {$k}  (config/{$v}.php)", array_keys($undocumented), $undocumented),
        ]));
    }

    /**
     * @return list<string>
     */
    private function documentedKeys(): array
    {
        preg_match_all(
            '/^([A-Z0-9_]+)=/m',
            file_get_contents(base_path('.env.example')),
            $matches,
        );

        return $matches[1];
    }
}
