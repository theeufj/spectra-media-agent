<?php

namespace Tests\Feature;

use Google\Ads\GoogleAds\V22\Services\Client\BiddingSeasonalityAdjustmentServiceClient;
use Google\Ads\GoogleAds\V22\Services\Client\ConversionValueRuleServiceClient;
use Google\Ads\GoogleAds\V22\Services\Client\ConversionValueRuleSetServiceClient;
use Google\Ads\GoogleAds\V22\Services\Client\ExperimentServiceClient;
use Google\Ads\GoogleAds\V22\Services\Client\OfflineUserDataJobServiceClient;
use Google\Ads\GoogleAds\V22\Services\Client\UserListServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateBillingSetupRequest;
use Tests\TestCase;

/**
 * Two ways a Google Ads mutation goes wrong without anyone noticing.
 *
 * 1. It ignores dry run. `php artisan googleads:dry-run` prints "validate_only
 *    is set on every request — nothing will be created or changed" and then
 *    really rewrote the campaign budget, because twelve services never read
 *    $this->dryRun. GoogleAdsDryRunTest's sweep missed them: its regex matches
 *    `new MutateCampaignsRequest(` but not the fully-qualified
 *    `new \Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest(` these
 *    files use, and it skips a file entirely when no request object is built —
 *    which is exactly the shape bug 2 leaves behind.
 *
 * 2. It uses the removed positional-argument API. Every v22 client method takes
 *    one request object; `mutateUserLists($customerId, [$op])` is a TypeError,
 *    an \Error that `catch (GoogleAdsException|ApiException)` does not catch.
 *    Customer Match, experiments, seasonality adjustments and conversion-value
 *    rules had therefore never worked, and ApplyConversionValueRules runs
 *    inside a warn-and-continue, so deployments reported success anyway.
 *
 * Both are source-level checks because the failure is in the call itself:
 * nothing short of a live account exercises these paths.
 */
class GoogleAdsMutationCallShapeTest extends TestCase
{
    /**
     * Request classes that change something on the account and carry a
     * validate_only field.
     *
     * CreateCustomerClientRequest is deliberately absent: it also supports the
     * flag, but sub-account provisioning (CreateManagedAccount, MCCAccountManager)
     * does not read dry run yet, and that is a separate change.
     */
    private const MUTATING_REQUEST = '/new\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\)*'
        .'(Mutate[A-Za-z]*Request'
        .'|CreateOfflineUserDataJobRequest'
        .'|AddOfflineUserDataJobOperationsRequest'
        .'|RunOfflineUserDataJobRequest'
        .'|ScheduleExperimentRequest)\s*\(\s*\[/';

    /** Client methods that apply a change and must be handed a request object. */
    private const MUTATING_CALL = '/->(mutate[A-Za-z]+|createOfflineUserDataJob'
        .'|addOfflineUserDataJobOperations|runOfflineUserDataJob'
        .'|scheduleExperiment)\s*\(\s*/';

    /**
     * A handful of Google's mutate endpoints have no validate_only field at
     * all — CustomerUserAccessInvitation carries customer_id and operation and
     * nothing else. Asked by reflection rather than kept as a list, so the
     * exemption disappears by itself the day Google adds the field.
     *
     * The rule still binds: a service calling one of these must return before
     * the call when $this->dryRun, which the sibling assertion below checks.
     */
    private static function cannotValidateOnly(string $requestClass): bool
    {
        foreach (['Google\\Ads\\GoogleAds\\V22\\Services\\'.$requestClass, $requestClass] as $fqcn) {
            if (class_exists($fqcn)) {
                return ! method_exists($fqcn, 'setValidateOnly');
            }
        }

        return false;
    }

    public function test_a_mutation_without_a_validate_only_field_returns_early_on_dry_run(): void
    {
        $unguarded = [];

        foreach ($this->googleAdsSources() as $relative => $source) {
            preg_match_all(self::MUTATING_REQUEST, $source, $matches);

            foreach ($matches[1] as $requestClass) {
                if (! self::cannotValidateOnly($requestClass)) {
                    continue;
                }

                // Not a flag it can send, so it must not send the request.
                if (! str_contains($source, 'if ($this->dryRun)')) {
                    $unguarded[] = $relative.'  '.$requestClass;
                }
            }
        }

        $this->assertSame([], $unguarded, implode("\n", [
            'These call a Google endpoint that has no validate_only field, so the',
            'only way to honour a dry run is to return before the call. Add:',
            '',
            '    if ($this->dryRun) { return [...]; }',
        ]));
    }

    public function test_every_mutate_request_carries_the_dry_run_flag(): void
    {
        $missing = [];

        foreach ($this->googleAdsSources() as $relative => $source) {
            preg_match_all(self::MUTATING_REQUEST, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $i => [$match, $offset]) {
                $literal = $this->arrayLiteralAt($source, $offset + strlen($match) - 1);

                if (! str_contains($literal, "'validate_only' => \$this->dryRun")
                    && ! self::cannotValidateOnly($matches[1][$i][0])) {
                    $missing[] = sprintf(
                        '%s:%d  %s',
                        $relative,
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                        $matches[1][$i][0]
                    );
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            "Every mutate request needs 'validate_only' => \$this->dryRun as its",
            'first key. Without it the service applies the change even when the',
            'caller asked for a dry run, and googleads:dry-run spends real money',
            'while printing that it cannot.',
            '',
            ...$missing,
        ]));
    }

    public function test_no_mutation_is_called_with_positional_arguments(): void
    {
        $positional = [];

        foreach ($this->googleAdsSources() as $relative => $source) {
            preg_match_all(self::MUTATING_CALL, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $i => [$match, $offset]) {
                $firstArgument = ltrim(substr($source, $offset + strlen($match), 80));

                if (preg_match('/^(new\s|\$request\b|[A-Za-z\\\\]+Request::build\()/', $firstArgument)) {
                    continue;
                }

                $positional[] = sprintf(
                    '%s:%d  %s(%s…)',
                    $relative,
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    $matches[1][$i][0],
                    substr($firstArgument, 0, 30)
                );
            }
        }

        $this->assertSame([], $positional, implode("\n", [
            'The v22 client methods take a single request object. A positional',
            'call throws a TypeError — an \Error, which the GoogleAdsException',
            'catches around these calls do not catch — so the feature never runs',
            'and the failure surfaces as a fatal, not a logged API error.',
            '',
            ...$positional,
        ]));
    }

    public function test_the_v22_clients_reject_the_positional_argument_api(): void
    {
        // Pins the reason for the rule above against the vendored SDK, so a
        // reader does not have to take the previous test's word for it.
        $methods = [
            [UserListServiceClient::class, 'mutateUserLists'],
            [ExperimentServiceClient::class, 'mutateExperiments'],
            [ExperimentServiceClient::class, 'scheduleExperiment'],
            [OfflineUserDataJobServiceClient::class, 'createOfflineUserDataJob'],
            [OfflineUserDataJobServiceClient::class, 'addOfflineUserDataJobOperations'],
            [OfflineUserDataJobServiceClient::class, 'runOfflineUserDataJob'],
            [BiddingSeasonalityAdjustmentServiceClient::class, 'mutateBiddingSeasonalityAdjustments'],
            [ConversionValueRuleServiceClient::class, 'mutateConversionValueRules'],
            [ConversionValueRuleSetServiceClient::class, 'mutateConversionValueRuleSets'],
        ];

        foreach ($methods as [$class, $method]) {
            $first = (new \ReflectionMethod($class, $method))->getParameters()[0];

            $this->assertSame(
                'request',
                $first->getName(),
                "{$class}::{$method}() no longer takes a request object first."
            );
            $this->assertStringEndsWith(
                'Request',
                (string) $first->getType(),
                "{$class}::{$method}() first argument is not a request message."
            );
        }
    }

    public function test_billing_setup_skips_its_mutate_under_dry_run(): void
    {
        // MutateBillingSetupRequest is the one mutate request in v22 with no
        // validate_only field, so BillingSetupService cannot honour dry run by
        // setting a flag — it has to not send the request at all.
        $this->assertFalse(
            method_exists(MutateBillingSetupRequest::class, 'setValidateOnly'),
            'MutateBillingSetupRequest gained validate_only — set the flag instead of skipping.'
        );

        $source = file_get_contents(app_path('Services/GoogleAds/BillingSetupService.php'));

        $guard = strpos($source, '$this->isDryRun()');
        $mutate = strpos($source, 'mutateBillingSetup(');

        $this->assertNotFalse($guard, 'BillingSetupService does not check isDryRun().');
        $this->assertLessThan(
            $mutate,
            $guard,
            'The dry-run guard must return before the billing setup is mutated.'
        );
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function googleAdsSources(): array
    {
        $sources = [];
        $base = base_path().'/';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services/GoogleAds'))
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $sources[str_replace($base, '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }

    /**
     * Return the balanced `[ ... ]` literal starting at $start.
     *
     * A line-based read would stop at the first newline and miss the flag in
     * every multi-line request, which is most of them.
     */
    private function arrayLiteralAt(string $source, int $start): string
    {
        $depth = 0;

        for ($i = $start; $i < strlen($source); $i++) {
            if ($source[$i] === '[' || $source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ']' || $source[$i] === ')') {
                if (--$depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return substr($source, $start);
    }
}
