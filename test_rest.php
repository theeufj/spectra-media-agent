<?php
require __DIR__ . '/vendor/autoload.php';

use Microsoft\MsAds\Rest\Api\CampaignManagementServiceApi;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\GetCampaignsByIdsRequest;
use Microsoft\MsAds\Rest\Auth\ApiEnvironment;
use Microsoft\MsAds\Rest\Auth\AuthorizationData;
use Microsoft\MsAds\Rest\Auth\OAuthWebAuthCodeGrant;
use Microsoft\MsAds\Rest\Configuration;
use GuzzleHttp\Client;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$configuration = Configuration::getDefaultConfiguration();

$authentication = (new OAuthWebAuthCodeGrant())
    ->withEnvironment(ApiEnvironment::PRODUCTION)
    ->withClientId(env('MICROSOFT_ADS_CLIENT_ID'))
    ->withClientSecret(env('MICROSOFT_ADS_CLIENT_SECRET'));

$configuration->setAuthorizationData(
    (new AuthorizationData())
        ->withAuthentication($authentication)
        ->withDeveloperToken(env('MICROSOFT_ADS_DEVELOPER_TOKEN'))
        ->withCustomerId(env('MICROSOFT_ADS_MANAGER_ACCOUNT_ID'))
        ->withAccountId(env('MICROSOFT_ADS_MANAGER_ACCOUNT_ID'))
);

$configuration->getAuthorizationData()->Authentication->RequestOAuthTokensByRefreshToken(env('MICROSOFT_ADS_REFRESH_TOKEN'));

$api = new CampaignManagementServiceApi(
    new Client(),
    $configuration,
    null,
    ApiEnvironment::PRODUCTION
);

try {
    $request = new GetCampaignsByIdsRequest([
        'AccountId' => env('MICROSOFT_ADS_MANAGER_ACCOUNT_ID'),
        'CampaignType' => 'Search',
        'CampaignIds' => [123]
    ]);
    
    $response = $api->getCampaignsByIds($request);
    print_r(get_class($response) . "\n");
    print_r($response);
} catch (\Exception $e) {
    if (method_exists($e, 'getResponseBody')) {
        echo "Body: " . $e->getResponseBody() . "\n";
    }
    echo "Error: " . $e->getMessage() . "\n";
}
