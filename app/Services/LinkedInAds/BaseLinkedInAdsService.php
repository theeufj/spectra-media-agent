<?php

namespace App\Services\LinkedInAds;

use App\Models\Customer;
use App\Services\Agents\Traits\RetryableApiOperation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base service for LinkedIn Marketing API calls.
 *
 * All LinkedIn Ads services should extend this class.
 * Uses the LinkedIn Marketing API v2 (versioned headers).
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/
 */
abstract class BaseLinkedInAdsService
{
    use RetryableApiOperation;

    protected string $platform = 'linkedin_ads';
    protected ?string $accessToken = null;
    protected Customer $customer;
    protected array $config;
    protected string $baseUrl = 'https://api.linkedin.com/rest';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->config = config('linkedinads', []);
        $this->authenticate();
    }

    /**
     * Authenticate using platform-level management credential.
     * Never uses per-customer tokens — all accounts are managed under Spectra's organization.
     */
    protected function authenticate(): void
    {
        try {
            $refreshToken = $this->config['refresh_token'] ?? null;

            if (!$refreshToken) {
                Log::error('LinkedIn Ads: No management refresh token configured. Set LINKEDIN_ADS_REFRESH_TOKEN in .env');
                return;
            }

            $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
            } else {
                Log::error('LinkedIn Ads authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('LinkedIn Ads authentication error', ['error' => $e->getMessage()]);
        }
    }

    protected function ensureAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    /**
     * Make a REST API call to the LinkedIn Marketing API.
     */
    protected function apiCall(string $path, string $method = 'GET', ?array $body = null, array $params = []): ?array
    {
        if (!$this->ensureAuthenticated()) {
            Log::error('LinkedIn Ads: Not authenticated');
            return null;
        }

        try {
            $url = "{$this->baseUrl}/{$path}";
            $apiVersion = $this->config['api_version'] ?? '202404';

            $request = Http::withToken($this->accessToken)
                ->withHeaders([
                    'LinkedIn-Version' => $apiVersion,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->timeout(30);

            $response = match (strtoupper($method)) {
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->asJson()->patch($url, $body),
                'DELETE' => $request->delete($url),
                default => $request->get($url, $params),
            };

            if ($response->successful()) {
                return $response->json() ?: ['success' => true];
            }

            Log::warning('LinkedIn Ads API error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('LinkedIn Ads API exception', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Make an API call with automatic retry and circuit breaker protection.
     */
    protected function apiCallWithRetry(string $path, string $method = 'GET', ?array $body = null, array $params = []): ?array
    {
        return $this->executeWithRetry(
            fn () => $this->apiCall($path, $method, $body, $params),
            $path,
            ['customer_id' => $this->customer->id]
        );
    }
}
