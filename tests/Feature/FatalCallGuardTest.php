<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * bin/check-fatal-classes is the only thing standing between the baseline and
 * production for errors PHP raises when the line runs rather than when it parses.
 *
 * `arguments.count` was not on its list, so eighteen miscounted argument lists
 * sat in phpstan-baseline.neon as "known findings". One of them was
 * `UpdateCampaignStatus::pause()` invoked with a single argument: an
 * ArgumentCountError the moment a customer's third card decline tried to stop
 * their campaigns, which is why campaigns kept serving on a dead card.
 *
 * These assertions exist so the list cannot be quietly narrowed again, and so
 * the App-namespace filter keeps matching the message shapes PHPStan actually
 * emits — the arity messages start with a capitalised noun ("Class App\Foo
 * constructor invoked with…"), and a case-sensitive filter dropped every one of
 * them while reporting success.
 */
class FatalCallGuardTest extends TestCase
{
    /**
     * Identifiers whose findings are always a runtime fatal, never noise.
     */
    private const REQUIRED_IDENTIFIERS = [
        'class.notFound',
        'method.notFound',
        'staticMethod.notFound',
        'classConstant.notFound',
        'arguments.count',
    ];

    private function script(): string
    {
        $path = base_path('bin/check-fatal-classes');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_every_fatal_identifier_is_still_on_the_list(): void
    {
        $this->assertSame(
            1,
            preg_match('/\$fatalIdentifiers = \[(.*?)\];/s', $this->script(), $matches),
            'check-fatal-classes no longer declares a $fatalIdentifiers array.'
        );

        preg_match_all("/'([a-zA-Z.]+)'/", $matches[1], $identifiers);

        foreach (self::REQUIRED_IDENTIFIERS as $identifier) {
            $this->assertContains(
                $identifier,
                $identifiers[1],
                "{$identifier} is a runtime fatal and must fail the build, not be baselined."
            );
        }
    }

    public function test_the_app_namespace_filter_matches_the_messages_phpstan_emits(): void
    {
        $pattern = $this->appNamespacePattern();

        $fatal = [
            'class.notFound' => 'Instantiated class App\Services\Missing not found.',
            'method.notFound' => 'Call to an undefined method App\Models\Campaign::nope().',
            'staticMethod.notFound' => 'Call to an undefined static method App\Models\Campaign::factory().',
            'constructor arity' => 'Class App\Services\GoogleAds\CommonServices\GetAdStatus constructor invoked with 2 parameters, 1 required.',
            'static method arity' => 'Static method App\Services\Agents\RecoveryPlan::fromJson() invoked with 1 parameter, 2 required.',
            'instance method arity' => 'Method App\Services\MicrosoftAds\AdGroupService::createAdGroup() invoked with 1 parameter, 2 required.',
        ];

        foreach ($fatal as $shape => $message) {
            $this->assertSame(
                1,
                preg_match($pattern, $message),
                "check-fatal-classes silently drops the {$shape} message shape."
            );
        }
    }

    public function test_the_filter_still_ignores_third_party_namespaces(): void
    {
        // Eloquent, Socialite, Mockery and the Google Ads SDK all resolve through
        // magic PHPStan cannot follow; only App\ reports are reliably real.
        $this->assertSame(
            0,
            preg_match(
                $this->appNamespacePattern(),
                'Method Illuminate\Database\Schema\Blueprint::float() invoked with 3 parameters, 1-2 required.'
            )
        );
    }

    public function test_the_strict_config_never_reads_the_baseline(): void
    {
        $config = (string) file_get_contents(base_path('phpstan-strict.neon'));

        // The `includes:` block only — the file's comments name the baseline
        // repeatedly, explaining why it is deliberately absent from that block.
        preg_match('/^includes:\n((?:[ \t]+-.*\n)+)/m', $config, $block);
        preg_match_all('/-\s*(\S+)/', $block[1] ?? '', $includes);

        $this->assertNotEmpty($includes[1], 'phpstan-strict.neon includes nothing at all.');

        $this->assertNotContains(
            'phpstan-baseline.neon',
            $includes[1],
            'Including the baseline here would let a regeneration silence every fatal-call finding.'
        );
    }

    /**
     * The live filter out of the script, so this test cannot drift from it.
     */
    private function appNamespacePattern(): string
    {
        $this->assertSame(
            1,
            preg_match('#preg_match\(\'(/\\\\b\(\?:class[^\']*)\'#', $this->script(), $matches),
            'check-fatal-classes no longer filters messages down to the App namespace.'
        );

        // Un-escape the single-quoted PHP literal back into the pattern PHP compiles.
        return str_replace('\\\\', '\\', $matches[1]);
    }
}
