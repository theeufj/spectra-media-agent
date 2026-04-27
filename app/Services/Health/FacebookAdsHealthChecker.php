<?php

namespace App\Services\Health;

use App\Models\Customer;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookAdsHealthChecker
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
                    'message'  => 'Unable to connect to Facebook Ads API',
                    'details'  => $connectivity['error'] ?? 'Unknown error',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            $tokenHealth = $this->checkTokenHealth($customer);
            $health['issues']   = array_merge($health['issues'], $tokenHealth['issues']);
            $health['warnings'] = array_merge($health['warnings'], $tokenHealth['warnings']);

            $accountHealth = $this->checkAccountRestrictions($customer);
            $health['issues']   = array_merge($health['issues'], $accountHealth['issues']);
            $health['warnings'] = array_merge($health['warnings'], $accountHealth['warnings']);

            $pixelHealth = $this->checkPixelHealth($customer);
            $health['warnings'] = array_merge($health['warnings'], $pixelHealth['warnings']);
            $health['metrics']['pixel'] = $pixelHealth;

        } catch (\Exception $e) {
            Log::error("FacebookAdsHealthChecker: Error checking Facebook Ads health", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            $health['issues'][] = [
                'type'     => 'error',
                'severity' => 'high',
                'message'  => 'Error during Facebook Ads health check',
                'details'  => $e->getMessage(),
            ];
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    private function testConnectivity(Customer $customer): array
    {
        if (!$this->systemUserToken()) {
            return ['connected' => false, 'error' => 'No access token configured'];
        }

        $cacheKey = "facebook_ads_connectivity:{$customer->id}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            new FacebookCampaignService($customer);
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

        if (!$this->systemUserToken()) {
            $health['issues'][] = [
                'type'     => 'token',
                'severity' => 'critical',
                'message'  => 'Facebook Ads access token is missing',
                'details'  => 'Configure the platform system user token to restore access',
            ];
        }

        return $health;
    }

    private function checkAccountRestrictions(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        $accountId   = $customer->facebook_ads_account_id;
        $accessToken = $this->systemUserToken();

        if (!$accountId || !$accessToken) {
            return $health;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v22.0/{$accountId}", [
                'access_token' => $accessToken,
                'fields'       => 'account_status,disable_reason,name',
            ]);

            if (!$response->successful()) {
                return $health;
            }

            $data          = $response->json();
            $accountStatus = $data['account_status'] ?? 1;

            // 1=ACTIVE, 2=DISABLED, 3=UNSETTLED, 7=PENDING_REVIEW, 9=IN_GRACE_PERIOD, 100=PENDING_CLOSURE, 101=CLOSED
            $statusMap = [
                2   => ['severity' => 'critical', 'message' => 'Facebook ad account is disabled',         'type' => 'account_disabled'],
                3   => ['severity' => 'critical', 'message' => 'Facebook ad account has unsettled payments', 'type' => 'account_unsettled'],
                7   => ['severity' => 'high',     'message' => 'Facebook ad account is pending review',   'type' => 'account_pending_review'],
                9   => ['severity' => 'high',     'message' => 'Facebook ad account is in grace period',  'type' => 'account_grace_period'],
                100 => ['severity' => 'critical', 'message' => 'Facebook ad account is pending closure',  'type' => 'account_pending_closure'],
                101 => ['severity' => 'critical', 'message' => 'Facebook ad account is closed',           'type' => 'account_closed'],
            ];

            if (isset($statusMap[$accountStatus])) {
                $info    = $statusMap[$accountStatus];
                $details = ($data['disable_reason'] ?? null)
                    ? "Disable reason: {$data['disable_reason']}"
                    : 'Check Facebook Business Manager for details';

                $entry = ['type' => $info['type'], 'severity' => $info['severity'], 'message' => $info['message'], 'details' => $details];
                if ($info['severity'] === 'critical') {
                    $health['issues'][] = $entry;
                } else {
                    $health['warnings'][] = $entry;
                }
            }
        } catch (\Exception $e) {
            Log::debug("FacebookAdsHealthChecker: Could not check Facebook account restrictions", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $health;
    }

    private function checkPixelHealth(Customer $customer): array
    {
        $health = ['warnings' => [], 'has_pixel' => false];

        if ($customer->facebook_pixel_id) {
            $health['has_pixel'] = true;
        } else {
            $health['warnings'][] = [
                'type'     => 'pixel',
                'severity' => 'medium',
                'message'  => 'No Facebook Pixel configured',
                'details'  => 'Conversion tracking and Advantage+ campaigns will be limited',
            ];
        }

        return $health;
    }

    private function systemUserToken(): ?string
    {
        return config('services.facebook.system_user_token');
    }
}
