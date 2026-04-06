<?php

namespace App\Services\MicrosoftAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base service for Microsoft Advertising (Bing Ads) API calls.
 * Uses the REST API v13 endpoint.
 *
 * All Microsoft Ads services should extend this class.
 */
abstract class BaseMicrosoftAdsService
{
    protected ?string $accessToken = null;
    protected Customer $customer;
    protected array $config;
    protected string $baseUrl = 'https://campaign.api.bingads.microsoft.com/Api/Advertiser/CampaignManagement/v13';
    protected string $reportingUrl = 'https://reporting.api.bingads.microsoft.com/Api/Advertiser/Reporting/v13';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->config = config('microsoftads', []);
        $this->authenticate();
    }

    protected function authenticate(): void
    {
        try {
            // Use 'organizations' endpoint for work/school accounts (Azure AD)
            $response = Http::asForm()->post("https://login.microsoftonline.com/organizations/oauth2/v2.0/token", [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $this->config['refresh_token'],
                'grant_type' => 'refresh_token',
                'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
            } else {
                Log::error('Microsoft Ads authentication failed', ['status' => $response->status()]);
            }
        } catch (\Exception $e) {
            Log::error('Microsoft Ads authentication error', ['error' => $e->getMessage()]);
        }
    }

    protected function ensureAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    /**
     * Make a SOAP-style API call to Microsoft Ads Campaign Management.
     */
    protected function apiCall(string $operation, array $body): ?array
    {
        if (!$this->ensureAuthenticated()) return null;

        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'DeveloperToken' => $this->config['developer_token'] ?? '',
            'CustomerId' => $this->customer->microsoft_ads_customer_id ?? '',
            'CustomerAccountId' => $this->customer->microsoft_ads_account_id ?? '',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post("{$this->baseUrl}/{$operation}", $body);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Microsoft Ads API error: {$operation}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Microsoft Ads API exception: {$operation}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Make a reporting API call.
     */
    protected function reportingCall(string $operation, array $body): ?array
    {
        if (!$this->ensureAuthenticated()) return null;

        $headers = [
            'Authorization' => "Bearer {$this->accessToken}",
            'DeveloperToken' => $this->config['developer_token'] ?? '',
            'CustomerId' => $this->customer->microsoft_ads_customer_id ?? '',
            'CustomerAccountId' => $this->customer->microsoft_ads_account_id ?? '',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->post("{$this->reportingUrl}/{$operation}", $body);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error("Microsoft Ads Reporting error: {$operation}", ['error' => $e->getMessage()]);
            return null;
        }
    }
}
