<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;
use Google\Ads\GoogleAds\V22\Services\CustomerServiceClient;
use Google\Ads\GoogleAds\V22\Resources\Customer;
use Google\ApiCore\ApiException;
use Google\Ads\GoogleAds\V22\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest;

class TestGoogleAdsConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'googleads:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tests the connection to the Google Ads API and creates a test account.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Google Ads API connection...');

        try {
            // Create a credential builder for OAuth2.
            $oAuth2Credential = (new OAuth2TokenBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'))
                ->build();

            // Create a GoogleAdsClient.
            $googleAdsClient = (new \Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'))
                ->withOAuth2Credential($oAuth2Credential)
                ->build();

            // Get the CustomerService client.
            $customerServiceClient = $googleAdsClient->getCustomerServiceClient();

            // List accessible customers.
            $this->info('Listing accessible customers:');
            $accessibleCustomers = $customerServiceClient->listAccessibleCustomers(
                new ListAccessibleCustomersRequest()
            );
            foreach ($accessibleCustomers->getResourceNames() as $resourceName) {
                $this->line('- ' . $resourceName);
            }

            $this->info('Successfully connected to the Google Ads API.');

            $this->info('Attempting to create a test customer account...');

            // This is the manager account ID that the API has confirmed we have access to.
            $managerCustomerId = '6221985542';

            // Create a new customer.
            $customer = new Customer([
                'descriptive_name' => 'Test Account ' . uniqid(),
                'currency_code' => 'USD',
                'time_zone' => 'America/New_York',
            ]);

            // Creates the new customer account.
            $createCustomerRequest = new CreateCustomerClientRequest([
                'customer_id' => $managerCustomerId,
                'customer_client' => $customer,
            ]);
            $response = $customerServiceClient->createCustomerClient($createCustomerRequest);

            $this->info('Created a new test customer with resource name: ' . $response->getResourceName());


        } catch (GoogleAdsException $e) {
            $this->error('Google Ads API request failed with status: ' . $e->getStatus());
            $this->error('Request ID: ' . $e->getRequestId());
            foreach ($e->getFailure()->getErrors() as $error) {
                $this->error(sprintf(
                    "\tCode: %s, Message: %s",
                    $error->getErrorCode()->getErrorCode(),
                    $error->getMessage()
                ));
            }
        } catch (ApiException $e) {
            $this->error('Google Ads API request failed with status: ' . $e->getStatus());
            $this->error('Failure message: ' . $e);
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }
}
