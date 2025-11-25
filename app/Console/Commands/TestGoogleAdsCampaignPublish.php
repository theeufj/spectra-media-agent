<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V22\Common\ManualCpc;
use Google\Ads\GoogleAds\V22\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;
use Google\Ads\GoogleAds\V22\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;

class TestGoogleAdsCampaignPublish extends Command
{
    protected $signature = 'googleads:test-campaign-publish 
                            {--customer-id= : The customer ID to create the campaign under (without dashes)}
                            {--login-customer-id= : The manager account ID to login with (required for sub-accounts)}
                            {--create-test-account : Create a new test account first}';

    protected $description = 'Test publishing a campaign to Google Ads MCC account';

    private $googleAdsClient;

    public function handle()
    {
        $this->info('🚀 Testing Google Ads Campaign Publishing');
        $this->newLine();

        try {
            // Step 1: Initialize the Google Ads Client
            $this->info('Step 1: Initializing Google Ads Client...');
            
            // Determine login customer ID
            $loginCustomerId = $this->option('login-customer-id');
            if (!$loginCustomerId && $this->option('create-test-account')) {
                // If creating a test account, we might need to login as the MCC
                // But we don't know the MCC ID yet if it's not provided.
                // For now, let's rely on the user providing it or defaulting in initializeClient if needed.
            }
            
            $this->initializeClient($loginCustomerId);
            $this->info('✅ Client initialized successfully');
            $this->newLine();

            // Step 2: List accessible customers
            $this->info('Step 2: Listing accessible customers...');
            $accessibleCustomers = $this->listAccessibleCustomers();
            $this->newLine();

            // Step 3: Determine which customer ID to use
            $customerId = $this->determineCustomerId($accessibleCustomers);
            
            if (!$customerId) {
                $this->error('❌ No customer ID available. Please specify --customer-id or use --create-test-account');
                return 1;
            }

            $this->info("Selected Customer ID: {$customerId}");
            $this->newLine();

            // Step 4: Verify account access and get account info
            $this->info('Step 4: Verifying account access...');
            $accountInfo = $this->getAccountInfo($customerId);
            $this->table(
                ['Property', 'Value'],
                [
                    ['Customer ID', $accountInfo['id'] ?? 'N/A'],
                    ['Name', $accountInfo['name'] ?? 'N/A'],
                    ['Currency', $accountInfo['currency'] ?? 'N/A'],
                    ['Time Zone', $accountInfo['timezone'] ?? 'N/A'],
                    ['Manager', $accountInfo['is_manager'] ? 'Yes' : 'No'],
                    ['Test Account', $accountInfo['is_test'] ? 'Yes' : 'No'],
                ]
            );
            $this->newLine();

            // Step 5: Create a test campaign budget
            $this->info('Step 5: Creating test campaign budget...');
            $budgetResourceName = $this->createCampaignBudget($customerId);
            $this->info("✅ Budget created: {$budgetResourceName}");
            $this->newLine();

            // Step 6: Create a test campaign
            $this->info('Step 6: Creating test campaign...');
            $campaignResourceName = $this->createCampaign($customerId, $budgetResourceName);
            $this->info("✅ Campaign created: {$campaignResourceName}");
            $this->newLine();

            // Step 7: Verify campaign was created
            $this->info('Step 7: Verifying campaign...');
            $campaignDetails = $this->verifyCampaign($customerId, $campaignResourceName);
            $this->table(
                ['Property', 'Value'],
                [
                    ['Resource Name', $campaignDetails['resource_name'] ?? 'N/A'],
                    ['Campaign ID', $campaignDetails['id'] ?? 'N/A'],
                    ['Name', $campaignDetails['name'] ?? 'N/A'],
                    ['Status', $campaignDetails['status'] ?? 'N/A'],
                    ['Channel Type', $campaignDetails['channel_type'] ?? 'N/A'],
                    ['Budget', $campaignDetails['budget'] ?? 'N/A'],
                ]
            );
            $this->newLine();

            // Success summary
            $this->info('✅ SUCCESS! Campaign published successfully!');
            $this->newLine();
            $this->info('📊 Summary:');
            $this->line("   • MCC Access: Confirmed");
            $this->line("   • Account Access: Verified");
            $this->line("   • Budget Created: {$budgetResourceName}");
            $this->line("   • Campaign Created: {$campaignResourceName}");
            $this->line("   • Campaign Status: PAUSED (ready for configuration)");
            $this->newLine();
            $this->info('💡 Next Steps:');
            $this->line('   1. Login to Google Ads (https://ads.google.com)');
            $this->line('   2. Navigate to the account: ' . $customerId);
            $this->line('   3. View the test campaign that was just created');
            $this->line('   4. Delete the test campaign if not needed');
            $this->newLine();
            $this->info('🎉 Your Google Ads API integration is working correctly!');

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
            return 1;
        } catch (\Exception $e) {
            $this->error('❌ Unexpected Error: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return 1;
        }
    }

    private function initializeClient(?string $loginCustomerId = null): void
    {
        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->fromFile(storage_path('app/google_ads_php.ini'))
            ->build();

        $builder = (new GoogleAdsClientBuilder())
            ->fromFile(storage_path('app/google_ads_php.ini'))
            ->withOAuth2Credential($oAuth2Credential);

        if ($loginCustomerId) {
            $this->info("   Using Login Customer ID: {$loginCustomerId}");
            $builder->withLoginCustomerId(str_replace('-', '', $loginCustomerId));
        }

        $this->googleAdsClient = $builder->build();
    }

    private function listAccessibleCustomers(): array
    {
        $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
        $accessibleCustomers = $customerServiceClient->listAccessibleCustomers(
            new ListAccessibleCustomersRequest()
        );

        $customerIds = [];
        $this->info('Accessible Customers:');
        foreach ($accessibleCustomers->getResourceNames() as $resourceName) {
            // Extract customer ID from resource name (format: customers/123456789)
            $customerId = str_replace('customers/', '', $resourceName);
            $customerIds[] = $customerId;
            $this->line("  • {$customerId}");
        }

        return $customerIds;
    }

    private function determineCustomerId(array $accessibleCustomers): ?string
    {
        // If customer ID provided via option
        if ($customerId = $this->option('customer-id')) {
            $customerId = str_replace('-', '', $customerId);
            
            // Check if we need to create a test account under this ID (assuming it's an MCC)
            if ($this->option('create-test-account')) {
                $this->info("Creating a new test client account under MCC: {$customerId}...");
                return $this->createTestClientAccount($customerId);
            }
            
            return $customerId;
        }

        // If create test account option but no ID provided
        if ($this->option('create-test-account')) {
            $this->info('Creating test account...');
            return $this->createTestAccount();
        }

        // If only one accessible customer, use it
        if (count($accessibleCustomers) === 1) {
            return $accessibleCustomers[0];
        }

        // If multiple customers, let user choose
        if (count($accessibleCustomers) > 1) {
            $choice = $this->choice(
                'Multiple customers found. Which one would you like to use?',
                $accessibleCustomers,
                0
            );
            return $choice;
        }

        return null;
    }

    private function createTestClientAccount(string $mccCustomerId): string
    {
        $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
        
        $customer = new \Google\Ads\GoogleAds\V22\Resources\Customer([
            'descriptive_name' => 'Test Client Account - ' . date('Y-m-d H:i:s'),
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
        ]);

        // Important: When creating a client account under a test MCC, 
        // we must NOT set test_account=true explicitly if the MCC is already a test account.
        // The sub-account inherits the test status.

        $request = new \Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest([
            'customer_id' => $mccCustomerId,
            'customer_client' => $customer,
        ]);

        $response = $customerServiceClient->createCustomerClient($request);
        
        // Extract customer ID from resource name
        $resourceName = $response->getResourceName();
        $customerId = str_replace('customers/', '', $resourceName);
        
        $this->info("✅ Test client account created: {$customerId}");
        
        return $customerId;
    }

    private function getAccountInfo(string $customerId): array
    {
        $googleAdsServiceClient = $this->googleAdsClient->getGoogleAdsServiceClient();

        $query = "SELECT 
                    customer.id,
                    customer.descriptive_name,
                    customer.currency_code,
                    customer.time_zone,
                    customer.manager,
                    customer.test_account
                  FROM customer 
                  LIMIT 1";

        $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
            'customer_id' => $customerId,
            'query' => $query,
        ]);

        $response = $googleAdsServiceClient->search($request);

        $row = $response->getIterator()->current();
        $customer = $row->getCustomer();

        return [
            'id' => $customer->getId(),
            'name' => $customer->getDescriptiveName(),
            'currency' => $customer->getCurrencyCode(),
            'timezone' => $customer->getTimeZone(),
            'is_manager' => $customer->getManager(),
            'is_test' => $customer->getTestAccount(),
        ];
    }

    private function createCampaignBudget(string $customerId): string
    {
        $campaignBudget = new CampaignBudget([
            'name' => 'Test Budget - ' . date('Y-m-d H:i:s'),
            'amount_micros' => 5000000, // $5.00 daily budget
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->setCreate($campaignBudget);

        $campaignBudgetServiceClient = $this->googleAdsClient->getCampaignBudgetServiceClient();
        
        $request = new \Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest([
            'customer_id' => $customerId,
            'operations' => [$campaignBudgetOperation],
        ]);

        $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);

        return $response->getResults()[0]->getResourceName();
    }

    private function createCampaign(string $customerId, string $budgetResourceName): string
    {
        $campaign = new Campaign([
            'name' => 'Test Campaign - ' . date('Y-m-d H:i:s'),
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'status' => CampaignStatus::PAUSED, // Start paused for safety
            'campaign_budget' => $budgetResourceName,
            'start_date' => date('Ymd'),
            'end_date' => date('Ymd', strtotime('+30 days')),
            'manual_cpc' => new ManualCpc(),
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);

        $campaignServiceClient = $this->googleAdsClient->getCampaignServiceClient();
        
        $request = new \Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest([
            'customer_id' => $customerId,
            'operations' => [$campaignOperation],
        ]);

        $response = $campaignServiceClient->mutateCampaigns($request);

        return $response->getResults()[0]->getResourceName();
    }

    private function verifyCampaign(string $customerId, string $campaignResourceName): array
    {
        $googleAdsServiceClient = $this->googleAdsClient->getGoogleAdsServiceClient();

        // Extract campaign ID from resource name
        $campaignId = str_replace('customers/' . $customerId . '/campaigns/', '', $campaignResourceName);

        $query = "SELECT 
                    campaign.id,
                    campaign.name,
                    campaign.status,
                    campaign.advertising_channel_type,
                    campaign.campaign_budget,
                    campaign.start_date,
                    campaign.end_date
                  FROM campaign 
                  WHERE campaign.id = {$campaignId}";

        $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
            'customer_id' => $customerId,
            'query' => $query,
        ]);

        $response = $googleAdsServiceClient->search($request);
        $row = $response->getIterator()->current();
        $campaign = $row->getCampaign();

        return [
            'resource_name' => $campaignResourceName,
            'id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'status' => CampaignStatus::name($campaign->getStatus()),
            'channel_type' => AdvertisingChannelType::name($campaign->getAdvertisingChannelType()),
            'budget' => $campaign->getCampaignBudget(),
        ];
    }

    private function createTestAccount(): string
    {
        $this->warn('Creating test accounts requires MCC (Manager) account access.');
        
        $customerServiceClient = $this->googleAdsClient->getCustomerServiceClient();
        
        // You need to specify the MCC customer ID here
        $mccCustomerId = $this->ask('Enter your MCC Customer ID (without dashes)', '7542073739');
        
        $customer = new \Google\Ads\GoogleAds\V22\Resources\Customer([
            'descriptive_name' => 'Test Account - ' . date('Y-m-d H:i:s'),
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
        ]);

        $request = new \Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest([
            'customer_id' => str_replace('-', '', $mccCustomerId),
            'customer_client' => $customer,
        ]);

        $response = $customerServiceClient->createCustomerClient($request);
        
        // Extract customer ID from resource name
        $resourceName = $response->getResourceName();
        $customerId = str_replace('customers/', '', $resourceName);
        
        $this->info("✅ Test account created: {$customerId}");
        
        return $customerId;
    }
}
