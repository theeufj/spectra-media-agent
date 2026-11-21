<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use App\Services\Agents\Traits\RetryableApiOperation;
use Illuminate\Support\Facades\Log;

abstract class BaseFacebookAdsService
{
    use RetryableApiOperation;

    protected string $platform = 'facebook';

    protected ?string $accessToken = null;

    protected ?Customer $customer = null;

    protected string $apiVersion;

    protected string $graphApiUrl = 'https://graph.facebook.com';

    public function __construct(Customer $customer)
    {
        $this->apiVersion = config('services.facebook.graph_version', 'v22.0');
        $this->customer = $customer;
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Create a service instance with a raw access token (e.g. for audit sessions).
     */
    public static function fromAccessToken(string $accessToken): static
    {
        $instance = new static(new Customer);
        $instance->accessToken = $accessToken;

        return $instance;
    }

    /** All managed campaign calls use the agency system user. */
    protected function getAccessToken(): ?string
    {
        return config('services.facebook.system_user_token');
    }

    /**
     * Make an HTTP GET request to the Facebook Graph API.
     *
     * @param  string  $endpoint  The API endpoint (e.g., '/me/adaccounts')
     * @param  array  $params  Query parameters
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        try {
            if (! $this->accessToken) {
                Log::error('No access token available for Facebook API request', [
                    'customer_id' => $this->customer->id,
                ]);

                return null;
            }

            $params['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl.'/'.$this->apiVersion.$endpoint;

            $response = \Http::get($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Facebook API GET request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
                'customer_id' => $this->customer->id,
            ]);

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Exception during Facebook API GET request: '.$e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'customer_id' => $this->customer->id,
            ]);

            return null;
        }
    }

    /**
     * Every row of a paged edge, not just the first page.
     *
     * The Graph API returns 25 rows by default and hands back a cursor for the
     * rest. Callers that read $response['data'] see only that first page, and a
     * short list looks exactly like a complete one — so an account's 40th ad set
     * simply did not exist as far as this platform was concerned. That reached
     * billing: FetchFacebookAdsPerformanceData sums spend per ad set, so spend
     * on anything past the first page was never collected and never charged.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    protected function getAllPages(string $endpoint, array $params = [], int $maxPages = 25, ?bool &$complete = null): array
    {
        $complete = false;
        $rows = [];
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $pageParams = $params;

            if ($after !== null) {
                $pageParams['after'] = $after;
            }

            $response = $this->get($endpoint, $pageParams);

            if (! is_array($response) || ! isset($response['data']) || ! is_array($response['data'])) {
                break;
            }

            foreach ($response['data'] as $row) {
                $rows[] = $row;
            }

            // A next link is the only reliable "there is more": the cursor is
            // present on the last page too.
            if (empty($response['paging']['next'])) {
                $complete = true;

                return $rows;
            }
            $next = $response['paging']['cursors']['after'] ?? null;
            if (! is_string($next) || $next === '' || $next === $after) {
                break;
            }
            $after = $next;
        }

        Log::warning('Facebook paging stopped before a complete response', [
            'endpoint' => $endpoint,
            'pages' => $maxPages,
            'rows' => count($rows),
            'customer_id' => $this->customer->id,
        ]);

        return $rows;
    }

    /**
     * Make an HTTP POST request to the Facebook Graph API.
     *
     * @param  string  $endpoint  The API endpoint
     * @param  array  $data  Request body data
     */
    protected function post(string $endpoint, array $data = []): ?array
    {
        try {
            if (! $this->accessToken) {
                Log::error('No access token available for Facebook API request', [
                    'customer_id' => $this->customer->id,
                ]);

                return null;
            }

            $data['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl.'/'.$this->apiVersion.$endpoint;

            $response = \Http::post($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Facebook API POST request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
                'customer_id' => $this->customer->id,
            ]);

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Exception during Facebook API POST request: '.$e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'customer_id' => $this->customer->id,
            ]);

            return null;
        }
    }

    /*
     * There is deliberately no put() helper here. The Graph API exposes
     * GET/POST/DELETE only — a node update is a POST to the node — and every
     * caller this class ever had was updating a node. The PUTs came back 400,
     * this class logged and returned null, and each caller read that as "the
     * update failed" and returned false, so ad-set and ad mutations were a
     * silent no-op for as long as the helper existed. Removing it is what
     * stops the next caller reaching for it.
     */

    /**
     * Get the base URL for API requests.
     */
    protected function getBaseUrl(): string
    {
        return $this->graphApiUrl.'/'.$this->apiVersion;
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
