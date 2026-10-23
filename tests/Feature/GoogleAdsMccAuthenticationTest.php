<?php

namespace Tests\Feature;

use App\Features\PerUserGoogleToken;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\User;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/** Real SDK construction with synthetic credentials; no Google requests. */
class GoogleAdsMccAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalStorage;

    private ?string $temporaryStorage = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStorage = storage_path();
        $this->temporaryStorage = sys_get_temp_dir().'/spectra-google-ads-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->temporaryStorage.'/app', 0755, true);
        $this->app->useStoragePath($this->temporaryStorage);
        $this->writeConfig('fixture-developer-token');

        MccAccount::query()->update(['is_active' => false]);
        MccAccount::create([
            'name' => 'Synthetic platform manager',
            'google_customer_id' => '987-654-3210',
            'refresh_token' => Crypt::encryptString('platform-mcc-refresh-token'),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->temporaryStorage !== null) {
            $this->app->useStoragePath($this->originalStorage);
            File::deleteDirectory($this->temporaryStorage);
        }

        parent::tearDown();
    }

    public function test_real_client_uses_the_platform_refresh_token_and_normalized_manager_id(): void
    {
        $service = new GoogleAdsMccProbe(Customer::factory()->create(['website' => null, 'google_ads_customer_id' => '1234567890']));
        $client = $service->getClient();

        $this->assertInstanceOf(GoogleAdsClient::class, $client);
        $this->assertSame('fixture-developer-token', $client->getDeveloperToken());
        $this->assertSame(9876543210, $client->getLoginCustomerId());
        $this->assertPlatformRefreshToken($client);
    }

    public function test_a_per_user_feature_flag_and_connection_cannot_replace_the_platform_credentials(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['website' => null, 'google_ads_customer_id' => '1234567890']);
        $customer->users()->attach($user);
        $user->connections()->create([
            'platform' => 'google_ads',
            'access_token' => 'synthetic-user-access-token',
            'refresh_token' => 'user-refresh-token-must-not-be-used',
        ]);
        Feature::for($user)->activate(PerUserGoogleToken::class);
        $this->assertTrue(Feature::for($user)->active(PerUserGoogleToken::class));

        $client = (new GoogleAdsMccProbe($customer))->getClient();

        $this->assertInstanceOf(GoogleAdsClient::class, $client);
        $this->assertSame(9876543210, $client->getLoginCustomerId());
        $this->assertPlatformRefreshToken($client);
    }

    public function test_missing_developer_token_is_still_rejected_before_any_api_call(): void
    {
        // SDK 35 removed this validation; the platform must retain the guard.
        $this->writeConfig('');
        $service = new GoogleAdsMccProbe(Customer::factory()->create(['website' => null]));

        $this->assertNull($service->getClient());
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('platform MCC account, developer token and OAuth credentials');
        $service->requireClient();
    }

    public function test_search_targets_the_customer_without_replacing_the_manager_login(): void
    {
        $customer = Customer::factory()->create(['website' => null, 'google_ads_customer_id' => '1234567890']);
        $service = new GoogleAdsMccProbe($customer);
        $manager = $service->getClient();
        $this->assertInstanceOf(GoogleAdsClient::class, $manager);
        $this->assertSame(9876543210, $manager->getLoginCustomerId());

        $result = $this->createMock(PagedListResponse::class);
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->expects($this->once())->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request, array $options) use ($customer, $result) {
            $this->assertSame($customer->google_ads_customer_id, $request->getCustomerId());
            $this->assertSame('SELECT customer.id FROM customer', $request->getQuery());
            $this->assertFalse($options['retrySettings']['retriesEnabled']);

            return $result;
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $service->useClient($client);

        $this->assertSame($result, $service->read($customer->google_ads_customer_id));
        $this->assertSame(9876543210, $manager->getLoginCustomerId());
    }

    private function writeConfig(string $developerToken): void
    {
        File::put(storage_path('app/google_ads_php.ini'), <<<INI
[GOOGLE_ADS]
developerToken = "$developerToken"
loginCustomerId = "1112223333"

[OAUTH2]
clientId = "synthetic-client-id"
clientSecret = "synthetic-client-secret"
refreshToken = "ini-refresh-token-must-not-be-used"
INI);
    }

    private function assertPlatformRefreshToken(GoogleAdsClient $client): void
    {
        $calls = 0;
        $token = $client->getOAuth2Credential()->fetchAuthToken(function (RequestInterface $request) use (&$calls) {
            $calls++;
            parse_str((string) $request->getBody(), $body);
            $this->assertSame('platform-mcc-refresh-token', $body['refresh_token']);
            $this->assertSame('synthetic-client-id', $body['client_id']);

            return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"synthetic-access-token","expires_in":3600,"token_type":"Bearer"}');
        });

        $this->assertSame(1, $calls);
        $this->assertSame('synthetic-access-token', $token['access_token']);
    }
}

class GoogleAdsMccProbe extends BaseGoogleAdsService
{
    public function requireClient(): void
    {
        $this->ensureClient();
    }

    public function useClient(GoogleAdsClient $client): void
    {
        $this->client = $client;
    }

    public function read(string $customerId): PagedListResponse
    {
        return $this->searchQuery($customerId, 'SELECT customer.id FROM customer');
    }
}
