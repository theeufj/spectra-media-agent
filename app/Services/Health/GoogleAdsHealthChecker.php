<?php

namespace App\Services\Health;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetAccountStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GoogleAdsHealthChecker
{
    use HealthCheckTrait;

    public function check(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            $connectivity = $this->testConnectivity($customer);
            if (!$connectivity['connected']) {
                $health['issues'][] = [
                    'type'     => 'connectivity',
                    'severity' => 'critical',
                    'message'  => 'Unable to connect to Google Ads API',
                    'details'  => $connectivity['error'] ?? 'Unknown error',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            $tokenHealth = $this->checkTokenHealth($customer);
            $health['issues']   = array_merge($health['issues'], $tokenHealth['issues']);
            $health['warnings'] = array_merge($health['warnings'], $tokenHealth['warnings']);

            $accountHealth = $this->checkAccountStatus($customer);
            $health['issues'] = array_merge($health['issues'], $accountHealth['issues']);

            $conversionHealth = $this->checkConversionTracking($customer);
            $health['warnings'] = array_merge($health['warnings'], $conversionHealth['warnings']);
            $health['metrics']['conversion_tracking'] = $conversionHealth;

        } catch (\Exception $e) {
            Log::error("GoogleAdsHealthChecker: Error checking Google Ads health", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            $health['issues'][] = [
                'type'     => 'error',
                'severity' => 'high',
                'message'  => 'Error during Google Ads health check',
                'details'  => $e->getMessage(),
            ];
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    private function testConnectivity(Customer $customer): array
    {
        if (!$customer->google_ads_customer_id) {
            return ['connected' => false, 'error' => 'No customer ID configured'];
        }

        $cacheKey = "google_ads_connectivity:{$customer->id}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            new GetCampaignPerformance($customer, true);
            $result = ['connected' => true];
            Cache::put($cacheKey, $result, now()->addMinutes(30));
            return $result;
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkTokenHealth(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if (!\App\Models\MccAccount::getActive()) {
            $health['issues'][] = [
                'type'     => 'token',
                'severity' => 'critical',
                'message'  => 'No active MCC account configured',
                'details'  => 'Add an MCC account via Admin > MCC Accounts, or set GOOGLE_ADS_MCC_CUSTOMER_ID and GOOGLE_ADS_MCC_REFRESH_TOKEN env vars',
            ];
        }

        $lastSuccess = Cache::get("google_ads_last_success:{$customer->id}");
        if ($lastSuccess && Carbon::parse($lastSuccess)->lt(now()->subHours(24))) {
            $health['warnings'][] = [
                'type'     => 'api_activity',
                'severity' => 'medium',
                'message'  => 'No successful Google Ads API activity in 24 hours',
                'details'  => 'This may indicate token or connectivity issues',
            ];
        }

        return $health;
    }

    private function checkAccountStatus(Customer $customer): array
    {
        $health = ['issues' => []];

        try {
            $service    = new GetAccountStatus($customer);
            $customerId = $customer->cleanGoogleCustomerId();
            $status     = ($service)($customerId);

            if ($status) {
                // Status enum: 2=ENABLED, 3=CANCELED, 4=SUSPENDED, 5=CLOSED
                $statusMap = [
                    4 => ['type' => 'account_suspended', 'message' => 'Google Ads account is suspended', 'details' => 'Contact Google Ads support to resolve account suspension'],
                    3 => ['type' => 'account_canceled',  'message' => 'Google Ads account is canceled',  'details' => 'The account has been canceled and cannot run ads'],
                    5 => ['type' => 'account_closed',    'message' => 'Google Ads account is closed',    'details' => 'The account has been permanently closed'],
                ];

                if (isset($statusMap[$status['status']])) {
                    $info = $statusMap[$status['status']];
                    $health['issues'][] = array_merge($info, ['severity' => 'critical']);
                }
            }
        } catch (\Exception $e) {
            Log::debug("GoogleAdsHealthChecker: Could not check Google account status", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $health;
    }

    private function checkConversionTracking(Customer $customer): array
    {
        $health = ['warnings' => [], 'has_tracking' => false];

        if ($customer->google_ads_conversion_action_id) {
            $health['has_tracking'] = true;
        } else {
            $health['warnings'][] = [
                'type'     => 'conversion_tracking',
                'severity' => 'medium',
                'message'  => 'No Google Ads conversion tracking configured',
                'details'  => 'Smart Bidding performance will be limited without conversion data',
            ];
        }

        return $health;
    }
}
