<?php

namespace App\Services\MicrosoftAds;

use App\Models\Customer;
use App\Services\Agents\Traits\RetryableApiOperation;
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
    use RetryableApiOperation;

    protected string $platform = 'microsoft_ads';
    protected ?string $accessToken = null;
    protected Customer $customer;
    protected array $config;
    protected string $campaignWsdl = 'https://campaign.api.bingads.microsoft.com/Api/Advertiser/CampaignManagement/v13/CampaignManagementService.svc?singleWsdl';
    protected string $reportingWsdl = 'https://reporting.api.bingads.microsoft.com/Api/Advertiser/Reporting/v13/ReportingService.svc?singleWsdl';
    protected string $namespace = 'https://bingads.microsoft.com/CampaignManagement/v13';
    protected string $reportingNamespace = 'https://bingads.microsoft.com/Reporting/v13';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->config = config('microsoftads', []);
        $this->authenticate();
    }

    /**
     * Authenticate using platform-level management account credential.
     * Never uses per-customer tokens — all accounts are managed under Spectra's manager account.
     */
    protected function authenticate(): void
    {
        try {
            $refreshToken = $this->config['refresh_token'] ?? null;

            if (!$refreshToken) {
                Log::error('Microsoft Ads: No management refresh token configured. Set MICROSOFT_ADS_REFRESH_TOKEN in .env');
                return;
            }

            $tenantId = $this->config['tenant_id'] ?? 'common';

            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
            } else {
                Log::error('Microsoft Ads authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
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

        try {
            $client = new \SoapClient($this->campaignWsdl, [
                'trace' => 1,
                'exceptions' => 1,
            ]);

            $headers = [
                new \SoapHeader($this->namespace, 'AuthenticationToken', $this->accessToken),
                new \SoapHeader($this->namespace, 'DeveloperToken', $this->config['developer_token'] ?? ''),
                new \SoapHeader($this->namespace, 'CustomerId', $this->customer->microsoft_ads_customer_id ?? ''),
                new \SoapHeader($this->namespace, 'CustomerAccountId', $this->customer->microsoft_ads_account_id ?? ''),
            ];

            $client->__setSoapHeaders($headers);

            $response = $client->__soapCall($operation, [$body]);

            // Convert Microsoft's deep stdClass response into an associative array for our app
            return json_decode(json_encode($response), true);

        } catch (\SoapFault $e) {
            Log::error("Microsoft Ads API SoapFault: {$operation}", [
                'faultcode' => $e->faultcode ?? '',
                'faultstring' => $e->faultstring ?? '',
                'detail' => $e->detail ?? '',
                'message' => $e->getMessage()
            ]);
            throw new \Exception("Microsoft Ads API error: {$operation} - " . $e->getMessage());
        } catch (\Exception $e) {
            Log::error("Microsoft Ads API exception: {$operation}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Make a reporting API call.
     */
    protected function reportingCall(string $operation, array $body): ?array
    {
        if (!$this->ensureAuthenticated()) return null;

        try {
            $client = new \SoapClient($this->reportingWsdl, [
                'trace' => 1,
                'exceptions' => 1,
            ]);

            $headers = [
                new \SoapHeader($this->reportingNamespace, 'AuthenticationToken', $this->accessToken),
                new \SoapHeader($this->reportingNamespace, 'DeveloperToken', $this->config['developer_token'] ?? ''),
                new \SoapHeader($this->reportingNamespace, 'CustomerId', $this->customer->microsoft_ads_customer_id ?? ''),
                new \SoapHeader($this->reportingNamespace, 'CustomerAccountId', $this->customer->microsoft_ads_account_id ?? ''),
            ];

            $client->__setSoapHeaders($headers);

            $response = $client->__soapCall($operation, [$body]);

            return json_decode(json_encode($response), true);
        } catch (\Exception $e) {
            Log::error("Microsoft Ads Reporting error: {$operation}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Make an API call with automatic retry and circuit breaker protection.
     */
    protected function apiCallWithRetry(string $operation, array $body): ?array
    {
        return $this->executeWithRetry(
            fn () => $this->apiCall($operation, $body),
            $operation,
            ['customer_id' => $this->customer->id]
        );
    }

    /**
     * Make a reporting call with automatic retry and circuit breaker protection.
     */
    protected function reportingCallWithRetry(string $operation, array $body): ?array
    {
        return $this->executeWithRetry(
            fn () => $this->reportingCall($operation, $body),
            "reporting_{$operation}",
            ['customer_id' => $this->customer->id]
        );
    }
}
