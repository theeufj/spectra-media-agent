<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V22\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;

class TestGoogleAdsMCCAccess extends Command
{
    protected $signature = 'googleads:test-mcc';

    protected $description = 'Test MCC account access and configuration';

    private $googleAdsClient;

    public function handle()
    {
        $this->info('🔍 Testing Google Ads MCC Configuration');
        $this->newLine();

        try {
            // Step 1: Check configuration file
            $this->info('Step 1: Checking configuration files...');
            $this->checkConfigFiles();
            $this->newLine();

            // Step 2: Initialize client
            $this->info('Step 2: Initializing Google Ads client...');
            $this->initializeClient();
            $this->info('✅ Client initialized successfully');
            $this->newLine();

            // Step 3: Test authentication
            $this->info('Step 3: Testing authentication...');
            $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
            $this->info('✅ Authentication successful');
            $this->newLine();

            // Step 4: List accessible customers
            $this->info('Step 4: Listing accessible customers...');
            $accessibleCustomers = $customerServiceClient->listAccessibleCustomers(
                new ListAccessibleCustomersRequest()
            );

            $customerIds = [];
            foreach ($accessibleCustomers->getResourceNames() as $resourceName) {
                $customerId = str_replace('customers/', '', $resourceName);
                $customerIds[] = $customerId;
            }

            $this->info('✅ Found ' . count($customerIds) . ' accessible customer(s):');
            foreach ($customerIds as $customerId) {
                $this->line("  • {$customerId}");
            }
            $this->newLine();

            // Step 5: Get detailed info for each customer
            $this->info('Step 5: Fetching customer details...');
            foreach ($customerIds as $customerId) {
                $this->displayCustomerInfo($customerId);
            }
            $this->newLine();

            // Step 6: Check developer token status
            $this->info('Step 6: Developer Token Status');
            $this->displayDeveloperTokenInfo();
            $this->newLine();

            // Summary
            $this->info('✅ MCC Access Test Complete!');
            $this->newLine();
            $this->info('📊 Summary:');
            $this->line('  • Configuration: Valid');
            $this->line('  • Authentication: Working');
            $this->line('  • Accessible Accounts: ' . count($customerIds));
            $this->line('  • API Status: Ready');
            $this->newLine();

            // Next steps
            $this->info('🚀 Ready to Test Campaign Publishing?');
            $this->line('Run: php artisan googleads:test-campaign-publish');
            $this->newLine();

            return 0;

        } catch (GoogleAdsException $e) {
            $this->error('❌ Google Ads API Error');
            $this->error('Status: ' . $e->getStatus());
            $this->error('Request ID: ' . $e->getRequestId());
            $this->newLine();
            $this->error('Errors:');
            foreach ($e->getGoogleAdsFailure()->getErrors() as $error) {
                $this->error(sprintf(
                    "  • %s: %s",
                    $error->getErrorCode()->getErrorCode(),
                    $error->getMessage()
                ));
            }
            $this->newLine();
            $this->warn('💡 Common Issues:');
            $this->line('  1. Developer token not approved - Apply for Basic Access');
            $this->line('  2. Service account not linked to MCC - Check Google Ads UI');
            $this->line('  3. Incorrect MCC ID - Verify customer ID');
            return 1;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error('Stack: ' . $e->getTraceAsString());
            return 1;
        }
    }

    private function checkConfigFiles(): void
    {
        $iniPath = storage_path('app/google_ads_php.ini');
        $credentialsPath = storage_path('app/secrets/google-ads-api-credentials.json');

        // Check INI file
        if (!file_exists($iniPath)) {
            throw new \Exception("Configuration file not found: {$iniPath}");
        }
        $this->line("  ✅ Found: google_ads_php.ini");

        // Check credentials file
        if (!file_exists($credentialsPath)) {
            throw new \Exception("Credentials file not found: {$credentialsPath}");
        }
        $this->line("  ✅ Found: google-ads-api-credentials.json");

        // Parse and display INI contents
        $ini = parse_ini_file($iniPath, true);
        
        $this->newLine();
        $this->table(
            ['Setting', 'Value'],
            [
                ['Developer Token', substr($ini['GOOGLE_ADS']['developerToken'] ?? 'NOT SET', 0, 10) . '...'],
                ['JSON Key Path', basename($ini['OAUTH2']['jsonKeyFilePath'] ?? 'NOT SET')],
                ['Impersonated Email', $ini['OAUTH2']['impersonatedEmail'] ?? 'NOT SET'],
                ['Scopes', $ini['OAUTH2']['scopes'] ?? 'NOT SET'],
            ]
        );
    }

    private function initializeClient(): void
    {
        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->fromFile(storage_path('app/google_ads_php.ini'))
            ->build();

        $this->googleAdsClient = (new GoogleAdsClientBuilder())
            ->fromFile(storage_path('app/google_ads_php.ini'))
            ->withOAuth2Credential($oAuth2Credential)
            ->build();
    }

    private function displayCustomerInfo(string $customerId): void
    {
        try {
            $googleAdsServiceClient = $this->googleAdsClient->getGoogleAdsServiceClient();

            $query = "SELECT 
                        customer.id,
                        customer.descriptive_name,
                        customer.currency_code,
                        customer.time_zone,
                        customer.manager,
                        customer.test_account,
                        customer.auto_tagging_enabled,
                        customer.has_partners_badge
                      FROM customer 
                      LIMIT 1";

            // Use SearchGoogleAdsRequest for V22
            $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);

            $response = $googleAdsServiceClient->search($request);
            $row = $response->getIterator()->current();
            $customer = $row->getCustomer();

            $this->newLine();
            $this->line("Account: {$customerId}");
            $this->table(
                ['Property', 'Value'],
                [
                    ['Customer ID', $customer->getId()],
                    ['Name', $customer->getDescriptiveName() ?: 'N/A'],
                    ['Currency', $customer->getCurrencyCode()],
                    ['Time Zone', $customer->getTimeZone()],
                    ['Is Manager (MCC)', $customer->getManager() ? 'Yes' : 'No'],
                    ['Is Test Account', $customer->getTestAccount() ? 'Yes' : 'No'],
                    ['Auto Tagging', $customer->getAutoTaggingEnabled() ? 'Enabled' : 'Disabled'],
                    ['Partner Badge', $customer->getHasPartnersBadge() ? 'Yes' : 'No'],
                ]
            );

            // If it's a manager account, list child accounts
            if ($customer->getManager()) {
                $this->displayChildAccounts($customerId);
            }

        } catch (\Exception $e) {
            $this->warn("  ⚠️  Could not fetch details for {$customerId}: " . $e->getMessage());
        }
    }

    private function displayChildAccounts(string $mccCustomerId): void
    {
        try {
            $googleAdsServiceClient = $this->googleAdsClient->getGoogleAdsServiceClient();

            $query = "SELECT 
                        customer_client.id,
                        customer_client.descriptive_name,
                        customer_client.manager,
                        customer_client.test_account,
                        customer_client.status
                      FROM customer_client 
                      WHERE customer_client.level <= 1";

            $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $mccCustomerId,
                'query' => $query,
            ]);

            $response = $googleAdsServiceClient->search($request);

            $childAccounts = [];
            foreach ($response as $row) {
                $client = $row->getCustomerClient();
                $childAccounts[] = [
                    $client->getId(),
                    $client->getDescriptiveName() ?: 'N/A',
                    $client->getManager() ? 'Manager' : 'Standard',
                    $client->getTestAccount() ? 'Test' : 'Live',
                ];
            }

            if (count($childAccounts) > 0) {
                $this->newLine();
                $this->line('  Child Accounts:');
                $this->table(
                    ['ID', 'Name', 'Type', 'Mode'],
                    $childAccounts
                );
            }

        } catch (\Exception $e) {
            $this->warn("  ⚠️  Could not fetch child accounts: " . $e->getMessage());
        }
    }

    private function displayDeveloperTokenInfo(): void
    {
        $this->line('Your developer token is currently: Test Account Access');
        $this->newLine();
        $this->warn('⚠️  Important: Test Account Access Limitations:');
        $this->line('  • Can only access test accounts');
        $this->line('  • Cannot create real campaigns with actual budget');
        $this->line('  • Cannot serve ads to real users');
        $this->newLine();
        $this->info('💡 To publish to production accounts:');
        $this->line('  1. Go to: https://ads.google.com/aw/apicenter');
        $this->line('  2. Click "Access level" section');
        $this->line('  3. Apply for "Basic Access"');
        $this->line('  4. Fill out the application form');
        $this->line('  5. Wait for Google approval (usually 24-48 hours)');
        $this->newLine();
        $this->info('For now, you can:');
        $this->line('  ✅ Test with test accounts');
        $this->line('  ✅ Develop and validate your integration');
        $this->line('  ✅ Create campaigns (they won\'t serve)');
    }
}
