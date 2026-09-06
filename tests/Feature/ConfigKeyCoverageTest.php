<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every config() key the application reads must actually exist.
 *
 * A missing key is not an error — config() just returns null, and the call site
 * carries on as though the feature were switched off. That is how the whole
 * 'openrouter' block was deleted from config/services.php by an over-greedy
 * edit and nothing noticed: isConfigured() started returning false, every
 * image and video generation silently fell back to Gemini, and the suite stayed
 * green because OpenRouterProviderTest sets the key itself in setUp().
 *
 * Keys with a deliberate inline default belong in ALLOWED below, with the
 * reason. Everything else must be defined in config/.
 */
class ConfigKeyCoverageTest extends TestCase
{
    /**
     * Keys read without being defined, each for a stated reason.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Read as config('auth.verification.expire', 60) — Laravel has no such
        // key by default and the inline default is the intended value.
        'auth.verification.expire' => 'inline default of 60 minutes',

        // Metered ad-spend billing is an unreleased feature. The command reads
        // config('billing.metered_ad_spend_enabled', false) and returns early,
        // so billing:report-ad-spend is deliberately a no-op until the config
        // block is added along with the rest of the feature.
        'billing.metered_ad_spend_enabled' => 'unreleased feature, defaults false',
        'billing.ad_spend_meter' => 'unreleased feature, only read when the flag above is on',

        // One candidate in a fallback list that is array_filter()ed, so a null
        // simply drops out and the search continues with the usual locations.
        'services.ffmpeg.path' => 'optional override in a filtered candidate list',
    ];

    public function test_every_config_key_the_app_reads_is_defined(): void
    {
        $missing = [];

        foreach ($this->configKeysRead() as $key => $sites) {
            if (array_key_exists($key, self::ALLOWED)) {
                continue;
            }

            if (! config()->has($key)) {
                $missing[$key] = $sites;
            }
        }

        $this->assertSame([], $missing, "These config() keys resolve to null, so the code reading them is inert:\n".
            collect($missing)->map(fn ($sites, $key) => "  {$key}  ←  ".implode(', ', $sites))->implode("\n"));
    }

    public function test_the_allow_list_does_not_outlive_its_reason(): void
    {
        // An entry that has since been defined should be removed, so the list
        // stays a record of real exceptions rather than accumulated noise.
        $stale = array_values(array_filter(
            array_keys(self::ALLOWED),
            fn ($key) => config()->has($key)
        ));

        $this->assertSame([], $stale, 'These keys are now defined and should leave ALLOWED: '.implode(', ', $stale));
    }

    /**
     * Every config('a.b.c') read in application code, mapped to where it appears.
     *
     * @return array<string, list<string>>
     */
    private function configKeysRead(): array
    {
        $roots = [base_path('app'), base_path('routes'), base_path('bootstrap')];
        $found = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                preg_match_all("/config\(\s*'([a-z0-9_]+(?:\.[a-zA-Z0-9_]+)+)'/", $src, $matches);

                foreach ($matches[1] as $key) {
                    $found[$key][] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        return array_map(fn ($sites) => array_values(array_unique($sites)), $found);
    }
}
