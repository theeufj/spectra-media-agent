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

    public function test_no_optional_override_is_left_blank_and_uncommented(): void
    {
        // The failure this catches: a key that has a config default, written as
        // `FOO=` rather than `# FOO=`. env() then returns "" instead of the
        // default — feature flags read as off, model names come back empty, and
        // numeric settings (including the GOOGLE_ADS_USD_RATE_* conversion
        // rates) cast to 0. Secrets with no config default are legitimately
        // blank, so only keys config/ supplies a default for are checked.
        $withDefaults = [];

        foreach (self::PROJECT_CONFIGS as $name) {
            $path = config_path($name.'.php');

            if (! file_exists($path)) {
                continue;
            }

            // env('KEY', <literal>) — a two-argument call with a real value.
            // A default that is itself an env() call is a fallback *name*, not
            // a value: config/services.php reads
            // env('STRIPE_SECRET_KEY', env('STRIPE_SECRET')), where both are
            // secrets with no working default and blank is the correct entry.
            preg_match_all(
                "/env\('([A-Z0-9_]+)'\s*,\s*(.)/",
                file_get_contents($path),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                if ($match[2] !== 'e') {
                    $withDefaults[] = $match[1];
                }
            }
        }

        preg_match_all(
            '/^([A-Z0-9_]+)=\s*$/m',
            file_get_contents(base_path('.env.example')),
            $blank,
        );

        $offenders = array_values(array_intersect($blank[1], $withDefaults));
        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'These keys have a default in config/ but are written blank in',
            '.env.example, so they override that default with an empty string.',
            'Comment them out instead: `# KEY=`.',
            '',
            ...$offenders,
        ]));
    }

    /**
     * @return list<string>
     */
    private function documentedKeys(): array
    {
        // Commented lines count as documented. An optional override belongs in
        // .env.example commented out: `FOO=` is not the same as an absent FOO —
        // env() returns "" for the former, so a blank line overrides the config
        // default with an empty string instead of deferring to it. CI does
        // `cp .env.example .env`, which is how blanking these keys turned off
        // every feature flag and emptied every model name in the build.
        preg_match_all(
            '/^#?\s*([A-Z0-9_]+)=/m',
            file_get_contents(base_path('.env.example')),
            $matches,
        );

        return $matches[1];
    }
}
