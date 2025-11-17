<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

abstract class BaseFacebookAdsService
{
    protected ?string $accessToken = null;
    protected ?Customer $customer = null;
    protected string $apiVersion = 'v18.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Get the Facebook access token from the customer record.
     *
     * @return ?string
     */
    protected function getAccessToken(): ?string
    {
        try {
            if ($this->customer->facebook_ads_access_token) {
                return Crypt::decryptString($this->customer->facebook_ads_access_token);
            }
        } catch (\Exception $e) {
            Log::error("Failed to decrypt Facebook access token for customer {$this->customer->id}: " . $e->getMessage());
        }

        return null;
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
}
