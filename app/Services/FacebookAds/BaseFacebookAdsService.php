<?php

namespace App\Services\FacebookAds;

use App\Features\PerUserFacebookToken;
use App\Models\Customer;
use App\Services\Agents\Traits\RetryableApiOperation;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

abstract class BaseFacebookAdsService
{
    use RetryableApiOperation;

    protected string $platform = 'facebook';
    protected ?string $accessToken = null;
    protected ?Customer $customer = null;
    protected string $apiVersion = 'v22.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Create a service instance with a raw access token (e.g. for audit sessions).
     */
    public static function fromAccessToken(string $accessToken): static
    {
        $instance = new static(new Customer());
        $instance->accessToken = $accessToken;
        return $instance;
    }

    /**
     * Get the Facebook access token.
     *
     * When the PerUserFacebookToken feature flag is active for this customer's
     * user, resolves a non-expired token from the connections table. Falls back
     * to the platform System User token in all other cases.
     */
    protected function getAccessToken(): ?string
    {
        $user = $this->customer?->users()->first();

        if ($user && Feature::for($user)->active(PerUserFacebookToken::class)) {
            $connection = $user->connections()
                ->where('platform', 'facebook_api')
                ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();

            if ($connection?->access_token) {
                Log::info('[FacebookAds] Using per-user OAuth token', [
                    'customer_id'   => $this->customer->id,
                    'user_id'       => $user->id,
                    'connection_id' => $connection->id,
                ]);
                return $connection->access_token;
            }

            Log::warning('[FacebookAds] PerUserFacebookToken flag active but no valid connection found, falling back to system token', [
                'customer_id' => $this->customer->id,
                'user_id'     => $user->id,
            ]);
        }

        return config('services.facebook.system_user_token');
    }

    /**
     * Make an HTTP GET request to the Facebook Graph API.
     *
     * @param string $endpoint The API endpoint (e.g., '/me/adaccounts')
     * @param array $params Query parameters
     * @return ?array
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        try {
            if (!$this->accessToken) {
                Log::error("No access token available for Facebook API request", [
                    'customer_id' => $this->customer->id,
                ]);
                return null;
            }

            $params['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl . '/' . $this->apiVersion . $endpoint;

            $response = \Http::get($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Facebook API GET request failed", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
                'customer_id' => $this->customer->id,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Exception during Facebook API GET request: " . $e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Make an HTTP POST request to the Facebook Graph API.
     *
     * @param string $endpoint The API endpoint
     * @param array $data Request body data
     * @return ?array
     */
    protected function post(string $endpoint, array $data = []): ?array
    {
        try {
            if (!$this->accessToken) {
                Log::error("No access token available for Facebook API request", [
                    'customer_id' => $this->customer->id,
                ]);
                return null;
            }

            $data['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl . '/' . $this->apiVersion . $endpoint;

            $response = \Http::post($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Facebook API POST request failed", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
                'customer_id' => $this->customer->id,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Exception during Facebook API POST request: " . $e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Make an HTTP PUT request to the Facebook Graph API.
     *
     * @param string $endpoint The API endpoint
     * @param array $data Request body data
     * @return ?array
     */
    protected function put(string $endpoint, array $data = []): ?array
    {
        try {
            if (!$this->accessToken) {
                Log::error("No access token available for Facebook API request", [
                    'customer_id' => $this->customer->id,
                ]);
                return null;
            }

            $data['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl . '/' . $this->apiVersion . $endpoint;

            $response = \Http::put($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Facebook API PUT request failed", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
                'customer_id' => $this->customer->id,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Exception during Facebook API PUT request: " . $e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get the base URL for API requests.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return $this->graphApiUrl . '/' . $this->apiVersion;
    }

    /**
     * Make a GET request with automatic retry and circuit breaker protection.
     */
    protected function getWithRetry(string $endpoint, array $params = [], string $operationName = 'graph_get'): ?array
    {
        return $this->executeWithRetry(
            fn () => $this->get($endpoint, $params),
            $operationName,
            ['endpoint' => $endpoint, 'customer_id' => $this->customer->id ?? null]
        );
    }

    /**
     * Make a POST request with automatic retry and circuit breaker protection.
     */
    protected function postWithRetry(string $endpoint, array $data = [], string $operationName = 'graph_post'): ?array
    {
        return $this->executeWithRetry(
            fn () => $this->post($endpoint, $data),
            $operationName,
            ['endpoint' => $endpoint, 'customer_id' => $this->customer->id ?? null]
        );
    }
}
