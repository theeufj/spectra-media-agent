<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Google Ads SDK enum classes referenced by the linking code must exist.
 *
 * CreateCustomerClientLink imported CustomerClientLinkStatusEnum, which the SDK
 * has no such class for — the enum is ManagerLinkStatus. PHP resolves a class
 * constant only at the moment it is evaluated, so the file parsed, passed lint,
 * and raised a fatal Error the first time a link was actually requested. PHPStan
 * did flag it, and the finding had been written into the baseline rather than
 * fixed, which is what let it survive to production.
 *
 * A class-existence assertion catches this at test time instead of at the one
 * moment it matters, which is mid-onboarding for a real customer.
 */
class GoogleAdsSdkClassesExistTest extends TestCase
{
    private const API_VERSION = '26';

    public static function sdkClasses(): array
    {
        return [
            'manager link status' => [\Google\Ads\GoogleAds\V26\Enums\ManagerLinkStatusEnum\ManagerLinkStatus::class],
            'customer client link' => [\Google\Ads\GoogleAds\V26\Resources\CustomerClientLink::class],
            'customer client link operation' => [\Google\Ads\GoogleAds\V26\Services\CustomerClientLinkOperation::class],

            // Singular. The link service takes one operation, unlike most Google
            // Ads services — MutateCustomerClientLinksRequest does not exist.
            'mutate request (singular)' => [\Google\Ads\GoogleAds\V26\Services\MutateCustomerClientLinkRequest::class],

            // Service clients live under Services\Client, not Services.
            'link service client' => [\Google\Ads\GoogleAds\V26\Services\Client\CustomerClientLinkServiceClient::class],
            'campaign service client' => [\Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient::class],
            'client builder' => [\Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder::class],
            'search request' => [\Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest::class],
            'search service client' => [\Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient::class],
            'keyword planner client' => [\Google\Ads\GoogleAds\V26\Services\Client\KeywordPlanIdeaServiceClient::class],
        ];
    }

    public function test_the_link_service_takes_a_single_operation(): void
    {
        // Every plural spelling of this call names something the SDK does not
        // define, and PHP only finds out at the moment of the call.
        // get_class_methods() rather than method_exists(): the latter folds to a
        // constant at analysis time, so the assertion would stop describing the
        // installed SDK and start describing what PHPStan already believes.
        $clientMethods = get_class_methods(\Google\Ads\GoogleAds\V26\Services\Client\CustomerClientLinkServiceClient::class);

        $this->assertContains('mutateCustomerClientLink', $clientMethods);
        $this->assertNotContains('mutateCustomerClientLinks', $clientMethods, 'the plural method does not exist');

        $requestMethods = get_class_methods(\Google\Ads\GoogleAds\V26\Services\MutateCustomerClientLinkRequest::class);

        $this->assertContains('setOperation', $requestMethods);
        $this->assertNotContains('setOperations', $requestMethods);
    }

    /** @dataProvider sdkClasses */
    public function test_the_sdk_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), $class.' is not a real class in the installed SDK');
    }

    public function test_the_pending_status_constant_resolves(): void
    {
        // The specific expression that fataled in production.
        $this->assertSame(4, \Google\Ads\GoogleAds\V26\Enums\ManagerLinkStatusEnum\ManagerLinkStatus::PENDING);
    }

    public function test_configuration_and_the_example_environment_match_the_compiled_api_version(): void
    {
        $expected = 'V'.self::API_VERSION;
        $this->assertSame($expected, config('services.google.ads_api_version'), 'GOOGLE_ADS_API_VERSION must match the compiled SDK namespaces.');
        preg_match('/^GOOGLE_ADS_API_VERSION=(.+)$/m', file_get_contents(base_path('.env.example')), $example);
        $this->assertSame($expected, $example[1] ?? null, 'The example environment must document the same Google Ads API version.');
    }

    public function test_an_explicit_admin_review_never_reports_an_invitation_as_sent_even_with_a_resource_name(): void
    {
        $api = $this->createMock(\Google\Ads\GoogleAds\V26\Services\Client\CustomerUserAccessInvitationServiceClient::class);
        $api->expects($this->once())->method('mutateCustomerUserAccessInvitation')->willReturn(
            new \Google\Ads\GoogleAds\V26\Services\MutateCustomerUserAccessInvitationResponse([
                'result' => new \Google\Ads\GoogleAds\V26\Services\MutateCustomerUserAccessInvitationResult([
                    'resource_name' => 'customers/1234567890/customerUserAccessInvitations/2',
                    'multi_party_auth_review' => 'customers/1234567890/multiPartyAuthReviews/3',
                ]),
            ])
        );
        $client = $this->createMock(\Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient::class);
        $client->method('getCustomerUserAccessInvitationServiceClient')->willReturn($api);
        $inviter = new class($client) extends \App\Services\GoogleAds\CommonServices\InviteCustomerUser
        {
            public function __construct(\Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient $client)
            {
                $this->client = $client;
            }
        };

        $this->assertSame(['success' => true, 'resource_name' => null, 'approval_pending' => true], $inviter->execute('1234567890', 'owner@example.test'));
    }

    public function test_production_calls_cannot_keep_a_retired_google_ads_api_version(): void
    {
        // The SDK ships several API versions. Updating Composer alone therefore
        // leaves retired namespaces loadable and can pass every class check.
        $stale = [];

        foreach ($this->productionSources() as $path => $source) {
            foreach (self::unsupportedApiReferences($source) as $reference) {
                $stale[] = $path.': '.$reference;
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    public function test_every_google_ads_class_referenced_by_production_code_exists_in_the_installed_sdk(): void
    {
        $missing = [];
        $references = 0;

        foreach ($this->productionSources() as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $class = ltrim($token[1], '\\');
                if (! str_starts_with($class, 'Google\\Ads\\GoogleAds\\')) {
                    continue;
                }

                $references++;
                if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
                    $missing[] = $path.':'.$token[2].' '.$class;
                }
            }
        }

        $this->assertGreaterThan(0, $references, 'The production SDK reference scan did not inspect any classes.');
        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_the_version_guard_covers_qualified_imports_dynamic_class_strings_and_rest_urls(): void
    {
        $retired = <<<'PHP'
<?php
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
$resource = new \Google\Ads\GoogleAds\V22\Resources\Campaign;
$class = 'Google\\Ads\\GoogleAds\\Util\\V22\\ResourceNames';
$endpoint = 'https://googleads.googleapis.com/v22/customers:listAccessibleCustomers';
PHP;
        $this->assertCount(4, self::unsupportedApiReferences($retired));

        $current = str_replace(['V22', '/v22/'], ['V26', '/v26/'], $retired);
        $this->assertSame([], self::unsupportedApiReferences($current));
        $this->assertSame([], self::unsupportedApiReferences("<?php\n/* ".$retired.' */'));
    }

    /** @return list<string> */
    private static function unsupportedApiReferences(string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // Class names can be imports, qualified expressions, or escaped strings.
        $code = str_replace('\\\\', '\\', $code);
        $stale = [];
        foreach (['~Google\\\\Ads\\\\GoogleAds\\\\(?:(?:Lib|Util)\\\\)?V(\d+)\\\\~', '~googleads\.googleapis\.com/v(\d+)/~'] as $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if ($match[1] !== self::API_VERSION) {
                    $stale[] = $match[0];
                }
            }
        }

        return $stale;
    }

    /** @return array<string, string> */
    private function productionSources(): array
    {
        $sources = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'scripts'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory)));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $sources[substr($path, strlen(base_path()) + 1)] = file_get_contents($path);
                }
            }
        }

        return $sources;
    }
}
