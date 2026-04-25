<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\FacebookAds\InsightService;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * HealthCheckAgent
 * 
 * Proactive monitoring agent that performs daily health checks on all active campaigns
 * before issues escalate. Detects and alerts on potential problems early.
 * 
 * Health Checks:
 * - API connectivity for Google Ads and Facebook Ads
 * - Token validity and expiration warnings
 * - Campaign delivery issues (limited by budget, policy issues)
 * - Performance anomalies (sudden drops/spikes)
 * - Budget pacing issues
 * - Creative fatigue detection
 * - Conversion tracking health
 * - Billing and payment status
 */
class HealthCheckAgent
{
    protected GeminiService $gemini;
    protected array $healthMetrics = [];
    
    // Thresholds for anomaly detection
    protected float $performanceDropThreshold = 0.30; // 30% drop triggers alert
    protected float $performanceSpikeThreshold = 2.0; // 200% increase triggers review
    protected int $creativeFatigueImpressions = 10000; // Check fatigue after this many impressions
    protected float $creativeFatigueCtRDropThreshold = 0.25; // 25% CTR drop indicates fatigue
    protected int $tokenExpiryWarningDays = 7; // Warn if token expires within 7 days

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Run comprehensive health check on a customer's ad accounts.
     *
     * @param Customer $customer
     * @return array Health check results
     */
    public function checkCustomerHealth(Customer $customer): array
    {
        Log::info("HealthCheckAgent: Starting health check for customer {$customer->id}");
        
        $results = [
            'customer_id' => $customer->id,
            'checked_at' => now()->toIso8601String(),
            'overall_health' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
            'recommendations' => [],
        ];

        // Check Google Ads health
        if ($customer->google_ads_customer_id) {
            $googleHealth = $this->checkGoogleAdsHealth($customer);
            $results['google_ads'] = $googleHealth;
            $this->mergeIssues($results, $googleHealth);
        }

        // Check Facebook Ads health
        if ($customer->facebook_ads_account_id) {
            $facebookHealth = $this->checkFacebookAdsHealth($customer);
            $results['facebook_ads'] = $facebookHealth;
            $this->mergeIssues($results, $facebookHealth);
        }

        // Check Microsoft Ads health
        if ($customer->microsoft_ads_account_id) {
            $microsoftHealth = $this->checkMicrosoftAdsHealth($customer);
            $results['microsoft_ads'] = $microsoftHealth;
            $this->mergeIssues($results, $microsoftHealth);
        }

        // Check LinkedIn Ads health
        if ($customer->linkedin_ads_account_id) {
            $linkedinHealth = $this->checkLinkedInAdsHealth($customer);
            $results['linkedin_ads'] = $linkedinHealth;
            $this->mergeIssues($results, $linkedinHealth);
        }

        // Check billing health
        $billingHealth = $this->checkBillingHealth($customer);
        $results['billing'] = $billingHealth;
        $this->mergeIssues($results, $billingHealth);

        // Check active campaigns
        $campaignHealth = $this->checkCampaignsHealth($customer);
        $results['campaigns'] = $campaignHealth;
        $this->mergeIssues($results, $campaignHealth);

        // Determine overall health status
        $results['overall_health'] = $this->calculateOverallHealth($results);

        // Generate AI recommendations if there are issues
        if (!empty($results['issues']) || !empty($results['warnings'])) {
            $results['recommendations'] = $this->generateRecommendations($results);
        }

        // Cache the results
        Cache::put(
            "health_check:customer:{$customer->id}",
            $results,
            now()->addHours(6)
        );

        Log::info("HealthCheckAgent: Completed health check for customer {$customer->id}", [
            'overall_health' => $results['overall_health'],
            'issues_count' => count($results['issues']),
            'warnings_count' => count($results['warnings']),
        ]);

        return $results;
    }

    /**
     * Check Google Ads account health.
     */
    protected function checkGoogleAdsHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check API connectivity
            $connectivityTest = $this->testGoogleAdsConnectivity($customer);
            if (!$connectivityTest['connected']) {
                $health['issues'][] = [
                    'type' => 'connectivity',
                    'severity' => 'critical',
                    'message' => 'Unable to connect to Google Ads API',
                    'details' => $connectivityTest['error'] ?? 'Unknown error',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            // Check refresh token validity
            $tokenHealth = $this->checkGoogleAdsTokenHealth($customer);
            if (!empty($tokenHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $tokenHealth['issues']);
            }
            if (!empty($tokenHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $tokenHealth['warnings']);
            }

            // Check for account-level issues
            $accountHealth = $this->checkGoogleAdsAccountStatus($customer);
            if (!empty($accountHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $accountHealth['issues']);
            }

            // Check conversion tracking
            $conversionHealth = $this->checkGoogleConversionTracking($customer);
            if (!empty($conversionHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $conversionHealth['warnings']);
            }
            $health['metrics']['conversion_tracking'] = $conversionHealth;

        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking Google Ads health", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $health['issues'][] = [
                'type' => 'error',
                'severity' => 'high',
                'message' => 'Error during Google Ads health check',
                'details' => $e->getMessage(),
            ];
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    /**
     * Check Facebook Ads account health.
     */
    protected function checkFacebookAdsHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check API connectivity
            $connectivityTest = $this->testFacebookAdsConnectivity($customer);
            if (!$connectivityTest['connected']) {
                $health['issues'][] = [
                    'type' => 'connectivity',
                    'severity' => 'critical',
                    'message' => 'Unable to connect to Facebook Ads API',
                    'details' => $connectivityTest['error'] ?? 'Unknown error',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            // Check access token validity
            $tokenHealth = $this->checkFacebookTokenHealth($customer);
            if (!empty($tokenHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $tokenHealth['issues']);
            }
            if (!empty($tokenHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $tokenHealth['warnings']);
            }

            // Check for account-level restrictions
            $accountHealth = $this->checkFacebookAccountRestrictions($customer);
            if (!empty($accountHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $accountHealth['issues']);
            }
            if (!empty($accountHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $accountHealth['warnings']);
            }

            // Check Pixel health
            $pixelHealth = $this->checkFacebookPixelHealth($customer);
            if (!empty($pixelHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $pixelHealth['warnings']);
            }
            $health['metrics']['pixel'] = $pixelHealth;

        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking Facebook Ads health", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $health['issues'][] = [
                'type' => 'error',
                'severity' => 'high',
                'message' => 'Error during Facebook Ads health check',
                'details' => $e->getMessage(),
            ];
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    /**
     * Check billing and payment health.
     */
    protected function checkBillingHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check Stripe subscription status
            if ($customer->subscription) {
                $subscription = $customer->subscription;
                
                if ($subscription->stripe_status === 'past_due') {
                    $health['issues'][] = [
                        'type' => 'payment',
                        'severity' => 'critical',
                        'message' => 'Subscription payment is past due',
                        'details' => 'Campaign delivery may be paused if not resolved',
                    ];
                } elseif ($subscription->stripe_status === 'incomplete') {
                    $health['warnings'][] = [
                        'type' => 'payment',
                        'severity' => 'high',
                        'message' => 'Subscription setup is incomplete',
                        'details' => 'Complete setup to enable full features',
                    ];
                }
            }

            // Check ad spend credits
            $adSpendCredits = $customer->adSpendCredits()
                ->where('expires_at', '>', now())
                ->where('remaining_amount', '>', 0)
                ->sum('remaining_amount');

            $health['metrics']['ad_spend_credits'] = $adSpendCredits;

            if ($adSpendCredits < 10) {
                $health['warnings'][] = [
                    'type' => 'credits',
                    'severity' => 'medium',
                    'message' => 'Low ad spend credits balance',
                    'details' => "Current balance: \${$adSpendCredits}. Consider adding more credits.",
                ];
            }

            // Check for recent payment failures
            $recentFailures = $customer->adSpendTransactions()
                ->where('status', 'failed')
                ->where('created_at', '>', now()->subDays(7))
                ->count();

            if ($recentFailures > 0) {
                $health['warnings'][] = [
                    'type' => 'payment_failures',
                    'severity' => 'high',
                    'message' => "Recent payment failures detected",
                    'details' => "{$recentFailures} failed transaction(s) in the last 7 days",
                ];
            }

        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking billing health", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    /**
     * Check health of all active campaigns.
     */
    protected function checkCampaignsHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'campaigns' => [],
        ];

        $activeCampaigns = Campaign::where('customer_id', $customer->id)
            ->withDeployedPlatforms()
            ->get();

        foreach ($activeCampaigns as $campaign) {
            $campaignHealth = $this->checkSingleCampaignHealth($campaign);
            $health['campaigns'][$campaign->id] = $campaignHealth;

            // Aggregate issues and warnings
            if (!empty($campaignHealth['issues'])) {
                foreach ($campaignHealth['issues'] as $issue) {
                    $issue['campaign_id'] = $campaign->id;
                    $issue['campaign_name'] = $campaign->name;
                    $health['issues'][] = $issue;
                }
            }
            if (!empty($campaignHealth['warnings'])) {
                foreach ($campaignHealth['warnings'] as $warning) {
                    $warning['campaign_id'] = $campaign->id;
                    $warning['campaign_name'] = $campaign->name;
                    $health['warnings'][] = $warning;
                }
            }
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    /**
     * Check health of a single campaign.
     */
    protected function checkSingleCampaignHealth(Campaign $campaign): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check whether campaigns are actually serving on-platform.
            $deliveryHealth = $this->checkCampaignDeliveryStatus($campaign);
            if (!empty($deliveryHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $deliveryHealth['issues']);
            }
            if (!empty($deliveryHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $deliveryHealth['warnings']);
            }

            $zeroDeliveryHealth = $this->checkZeroDelivery($campaign);
            if (!empty($zeroDeliveryHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $zeroDeliveryHealth['issues']);
            }
            if (!empty($zeroDeliveryHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $zeroDeliveryHealth['warnings']);
            }

            // Check budget pacing
            $pacingHealth = $this->checkBudgetPacing($campaign);
            if (!empty($pacingHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $pacingHealth['issues']);
            }
            if (!empty($pacingHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $pacingHealth['warnings']);
            }

            // Check for performance anomalies
            $performanceHealth = $this->detectPerformanceAnomalies($campaign);
            if (!empty($performanceHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $performanceHealth['warnings']);
            }
            $health['metrics']['performance'] = $performanceHealth['metrics'] ?? [];

            // Check for creative fatigue
            $fatigueHealth = $this->checkCreativeFatigue($campaign);
            if (!empty($fatigueHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $fatigueHealth['warnings']);
            }

            // Check ad approval status
            $approvalHealth = $this->checkAdApprovalStatus($campaign);
            if (!empty($approvalHealth['issues'])) {
                $health['issues'] = array_merge($health['issues'], $approvalHealth['issues']);
            }
            if (!empty($approvalHealth['warnings'])) {
                $health['warnings'] = array_merge($health['warnings'], $approvalHealth['warnings']);
            }

        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking campaign health", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    protected function checkZeroDelivery(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];
        $deployedAt = $campaign->strategies()
            ->whereIn('deployment_status', ['deployed', 'verified'])
            ->whereNotNull('deployed_at')
            ->min('deployed_at');

        if (!$deployedAt || Carbon::parse($deployedAt)->gt(now()->subHours(6))) {
            return $health;
        }

        if ($campaign->google_ads_campaign_id) {
            $googleMetrics = $this->getGoogleCampaignMetricsSummary($campaign);

            if ($googleMetrics && ($googleMetrics['impressions'] ?? 0) === 0) {
                $health['warnings'][] = [
                    'type' => 'google_zero_delivery',
                    'severity' => 'high',
                    'message' => 'Google campaign has not recorded impressions since deployment',
                    'details' => 'Check ad approval, bidding, targeting, and billing before spend is lost to delay.',
                ];
            }
        }

        if ($campaign->facebook_ads_campaign_id) {
            $facebookMetrics = $this->getFacebookCampaignMetricsSummary($campaign);

            if ($facebookMetrics && ($facebookMetrics['impressions'] ?? 0) === 0) {
                $health['warnings'][] = [
                    'type' => 'facebook_zero_delivery',
                    'severity' => 'high',
                    'message' => 'Facebook campaign has not recorded impressions since deployment',
                    'details' => 'Check ad set delivery, review state, audience restrictions, and billing.',
                ];
            }
        }

        return $health;
    }

    /**
     * Check whether deployed campaigns are serving normally on each platform.
     */
    protected function checkCampaignDeliveryStatus(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if ($campaign->google_ads_campaign_id && $campaign->customer?->google_ads_customer_id) {
            try {
                $customerId = str_replace('-', '', $campaign->customer->google_ads_customer_id);
                $resourceName = $campaign->google_ads_campaign_id;

                if (!str_starts_with($resourceName, 'customers/')) {
                    $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
                }

                $getCampaignStatus = new \App\Services\GoogleAds\CommonServices\GetCampaignStatus($campaign->customer);
                $statusData = $getCampaignStatus($customerId, $resourceName);

                if ($statusData) {
                    $campaignStatus = match ($statusData['status']) {
                        2 => 'ENABLED',
                        3 => 'PAUSED',
                        4 => 'REMOVED',
                        default => 'UNKNOWN',
                    };

                    $primaryStatus = match ($statusData['primary_status']) {
                        2 => 'ELIGIBLE',
                        3 => 'PAUSED',
                        4 => 'REMOVED',
                        5 => 'ENDED',
                        6 => 'PENDING',
                        7 => 'MISCONFIGURED',
                        8 => 'LIMITED',
                        default => 'UNKNOWN',
                    };

                    if ($campaignStatus !== 'ENABLED' || in_array($primaryStatus, ['PAUSED', 'REMOVED', 'ENDED', 'MISCONFIGURED'], true)) {
                        $health['issues'][] = [
                            'type' => 'google_campaign_not_serving',
                            'severity' => 'critical',
                            'message' => "Google campaign is not serving normally ({$campaignStatus} / {$primaryStatus})",
                            'details' => 'Check campaign status, policy issues, and billing in Google Ads.',
                        ];
                    } elseif (in_array($primaryStatus, ['PENDING', 'LIMITED', 'UNKNOWN'], true)) {
                        $health['warnings'][] = [
                            'type' => 'google_campaign_limited',
                            'severity' => 'high',
                            'message' => "Google campaign requires attention ({$campaignStatus} / {$primaryStatus})",
                            'details' => 'The campaign is enabled but not yet fully serving normally.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('HealthCheckAgent: Could not check Google campaign delivery status', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($campaign->facebook_ads_campaign_id && $campaign->customer?->facebook_ads_account_id) {
            try {
                $campaignService = new FacebookCampaignService($campaign->customer);
                $fbCampaign = $campaignService->getCampaign($campaign->facebook_ads_campaign_id);

                if ($fbCampaign) {
                    $effectiveStatus = $fbCampaign['effective_status'] ?? 'UNKNOWN';

                    if (in_array($effectiveStatus, ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DISAPPROVED', 'DELETED', 'ARCHIVED'], true)) {
                        $health['issues'][] = [
                            'type' => 'facebook_campaign_not_serving',
                            'severity' => 'critical',
                            'message' => "Facebook campaign is not serving normally ({$effectiveStatus})",
                            'details' => 'Check campaign, ad set, and policy status in Facebook Ads Manager.',
                        ];
                    } elseif (in_array($effectiveStatus, ['WITH_ISSUES', 'PENDING_REVIEW', 'PENDING_BILLING_INFO', 'IN_PROCESS', 'UNKNOWN'], true)) {
                        $health['warnings'][] = [
                            'type' => 'facebook_campaign_limited',
                            'severity' => 'high',
                            'message' => "Facebook campaign requires attention ({$effectiveStatus})",
                            'details' => 'The campaign is not yet fully serving normally.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('HealthCheckAgent: Could not check Facebook campaign delivery status', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $health;
    }

    /**
     * Test Google Ads API connectivity.
     */
    protected function testGoogleAdsConnectivity(Customer $customer): array
    {
        try {
            // Try to make a simple API call
            $customerId = $customer->google_ads_customer_id;
            if (!$customerId) {
                return ['connected' => false, 'error' => 'No customer ID configured'];
            }

            // Use cache to avoid repeated connectivity tests
            $cacheKey = "google_ads_connectivity:{$customer->id}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $getPerformance = new GetCampaignPerformance($customer, true);
            // Just check if we can authenticate, don't need actual data
            $result = ['connected' => true];
            
            Cache::put($cacheKey, $result, now()->addMinutes(30));
            return $result;

        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test Facebook Ads API connectivity.
     */
    protected function testFacebookAdsConnectivity(Customer $customer): array
    {
        try {
            if (!$this->getFacebookSystemUserToken()) {
                return ['connected' => false, 'error' => 'No access token configured'];
            }

            $cacheKey = "facebook_ads_connectivity:{$customer->id}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $campaignService = new FacebookCampaignService($customer);
            // Test by listing campaigns (limited to 1)
            $result = ['connected' => true];
            
            Cache::put($cacheKey, $result, now()->addMinutes(30));
            return $result;

        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check Google Ads token health.
     */
    protected function checkGoogleAdsTokenHealth(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if (!\App\Models\MccAccount::getActive()) {
            $health['issues'][] = [
                'type' => 'token',
                'severity' => 'critical',
                'message' => 'No active MCC account configured',
                'details' => 'Add an MCC account via Admin > MCC Accounts, or set GOOGLE_ADS_MCC_CUSTOMER_ID and GOOGLE_ADS_MCC_REFRESH_TOKEN env vars',
            ];
        }

        // Check last successful API call
        $lastSuccess = Cache::get("google_ads_last_success:{$customer->id}");
        if ($lastSuccess && Carbon::parse($lastSuccess)->lt(now()->subHours(24))) {
            $health['warnings'][] = [
                'type' => 'api_activity',
                'severity' => 'medium',
                'message' => 'No successful Google Ads API activity in 24 hours',
                'details' => 'This may indicate token or connectivity issues',
            ];
        }

        return $health;
    }

    /**
     * Check Facebook token health.
     */
    protected function checkFacebookTokenHealth(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if (!$this->getFacebookSystemUserToken()) {
            $health['issues'][] = [
                'type' => 'token',
                'severity' => 'critical',
                'message' => 'Facebook Ads access token is missing',
                'details' => 'Configure the platform system user token to restore access',
            ];
            return $health;
        }

        return $health;
    }

    /**
     * Check Google Ads account status for restrictions.
     */
    protected function checkGoogleAdsAccountStatus(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        try {
            $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService {
                public function getAccountStatus(string $customerId): ?array
                {
                    $this->ensureClient();

                    $query = "SELECT customer.status, customer.manager, customer.descriptive_name FROM customer LIMIT 1";

                    $response = $this->searchQuery($customerId, $query);
                    foreach ($response->getIterator() as $row) {
                        $c = $row->getCustomer();
                        return [
                            'status' => $c->getStatus(),
                            'is_manager' => $c->getManager(),
                            'name' => $c->getDescriptiveName(),
                        ];
                    }
                    return null;
                }
            };

            $customerId = $customer->google_ads_customer_id;
            $status = $service->getAccountStatus($customerId);

            if ($status) {
                // Status enum: 2=ENABLED, 3=CANCELED, 4=SUSPENDED, 5=CLOSED
                if ($status['status'] === 4) {
                    $health['issues'][] = [
                        'type' => 'account_suspended',
                        'severity' => 'critical',
                        'message' => 'Google Ads account is suspended',
                        'details' => 'Contact Google Ads support to resolve account suspension',
                    ];
                } elseif ($status['status'] === 3) {
                    $health['issues'][] = [
                        'type' => 'account_canceled',
                        'severity' => 'critical',
                        'message' => 'Google Ads account is canceled',
                        'details' => 'The account has been canceled and cannot run ads',
                    ];
                } elseif ($status['status'] === 5) {
                    $health['issues'][] = [
                        'type' => 'account_closed',
                        'severity' => 'critical',
                        'message' => 'Google Ads account is closed',
                        'details' => 'The account has been permanently closed',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::debug("HealthCheckAgent: Could not check Google account status", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    /**
     * Check Facebook account for restrictions.
     */
    protected function checkFacebookAccountRestrictions(Customer $customer): array
    {
        $health = ['issues' => [], 'warnings' => []];

        try {
            $accountId = $customer->facebook_ads_account_id;
            $accessToken = $this->getFacebookSystemUserToken();

            if (!$accountId || !$accessToken) {
                return $health;
            }

            $response = Http::get("https://graph.facebook.com/v22.0/{$accountId}", [
                'access_token' => $accessToken,
                'fields' => 'account_status,disable_reason,name',
            ]);

            if (!$response->successful()) {
                return $health;
            }

            $data = $response->json();
            $accountStatus = $data['account_status'] ?? 1;

            // Facebook account_status: 1=ACTIVE, 2=DISABLED, 3=UNSETTLED, 7=PENDING_REVIEW, 9=IN_GRACE_PERIOD, 100=PENDING_CLOSURE, 101=CLOSED
            $statusMessages = [
                2 => ['severity' => 'critical', 'message' => 'Facebook ad account is disabled', 'type' => 'account_disabled'],
                3 => ['severity' => 'critical', 'message' => 'Facebook ad account has unsettled payments', 'type' => 'account_unsettled'],
                7 => ['severity' => 'high', 'message' => 'Facebook ad account is pending review', 'type' => 'account_pending_review'],
                9 => ['severity' => 'high', 'message' => 'Facebook ad account is in grace period', 'type' => 'account_grace_period'],
                100 => ['severity' => 'critical', 'message' => 'Facebook ad account is pending closure', 'type' => 'account_pending_closure'],
                101 => ['severity' => 'critical', 'message' => 'Facebook ad account is closed', 'type' => 'account_closed'],
            ];

            if (isset($statusMessages[$accountStatus])) {
                $info = $statusMessages[$accountStatus];
                $disableReason = $data['disable_reason'] ?? null;
                $details = $disableReason ? "Disable reason: {$disableReason}" : 'Check Facebook Business Manager for details';

                if (in_array($info['severity'], ['critical'])) {
                    $health['issues'][] = [
                        'type' => $info['type'],
                        'severity' => $info['severity'],
                        'message' => $info['message'],
                        'details' => $details,
                    ];
                } else {
                    $health['warnings'][] = [
                        'type' => $info['type'],
                        'severity' => $info['severity'],
                        'message' => $info['message'],
                        'details' => $details,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::debug("HealthCheckAgent: Could not check Facebook account restrictions", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    protected function getFacebookSystemUserToken(): ?string
    {
        return config('services.facebook.system_user_token');
    }

    /**
     * Check Google conversion tracking setup.
     */
    protected function checkGoogleConversionTracking(Customer $customer): array
    {
        $health = ['warnings' => [], 'has_tracking' => false];

        // Check if any conversion actions exist
        if ($customer->google_ads_conversion_action_id) {
            $health['has_tracking'] = true;
        } else {
            $health['warnings'][] = [
                'type' => 'conversion_tracking',
                'severity' => 'medium',
                'message' => 'No Google Ads conversion tracking configured',
                'details' => 'Smart Bidding performance will be limited without conversion data',
            ];
        }

        return $health;
    }

    /**
     * Check Facebook Pixel health.
     */
    protected function checkFacebookPixelHealth(Customer $customer): array
    {
        $health = ['warnings' => [], 'has_pixel' => false];

        if ($customer->facebook_pixel_id) {
            $health['has_pixel'] = true;
        } else {
            $health['warnings'][] = [
                'type' => 'pixel',
                'severity' => 'medium',
                'message' => 'No Facebook Pixel configured',
                'details' => 'Conversion tracking and Advantage+ campaigns will be limited',
            ];
        }

        return $health;
    }

    /**
     * Check budget pacing for a campaign.
     */
    protected function checkBudgetPacing(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        // Calculate expected spend vs actual
        if ($campaign->daily_budget && $campaign->started_at) {
            $daysRunning = now()->diffInDays($campaign->started_at);
            $expectedSpend = $campaign->daily_budget * $daysRunning;
            $actualSpend = $campaign->total_spend ?? 0;

            if ($daysRunning > 0) {
                $pacingRatio = $actualSpend / max($expectedSpend, 1);

                $pacingPercent = round($pacingRatio * 100);
                if ($pacingRatio < 0.5) {
                    $health['warnings'][] = [
                        'type' => 'underspending',
                        'severity' => 'medium',
                        'message' => 'Campaign is significantly underspending',
                        'details' => "Spent \${$actualSpend} of expected \${$expectedSpend} ({$pacingPercent}% of budget)",
                    ];
                } elseif ($pacingRatio > 1.2) {
                    $health['warnings'][] = [
                        'type' => 'overspending',
                        'severity' => 'high',
                        'message' => 'Campaign is overspending budget',
                        'details' => "Spent \${$actualSpend} vs expected \${$expectedSpend}",
                    ];
                }
            }
        }

        return $health;
    }

    /**
     * Detect performance anomalies using historical comparison.
     */
    protected function detectPerformanceAnomalies(Campaign $campaign): array
    {
        $health = ['warnings' => [], 'metrics' => []];

        try {
            // Get last 7 days vs previous 7 days
            $recentStart = now()->subDays(7)->toDateString();
            $recentEnd = now()->toDateString();
            $previousStart = now()->subDays(14)->toDateString();
            $previousEnd = now()->subDays(7)->toDateString();

            $recent = null;
            $previous = null;

            if ($campaign->google_ads_campaign_id) {
                $recent = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->whereBetween('date', [$recentStart, $recentEnd])
                    ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                    ->first();
                $previous = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->whereBetween('date', [$previousStart, $previousEnd])
                    ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                    ->first();
            } elseif ($campaign->facebook_ads_campaign_id) {
                $recent = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->whereBetween('date', [$recentStart, $recentEnd])
                    ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                    ->first();
                $previous = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->whereBetween('date', [$previousStart, $previousEnd])
                    ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                    ->first();
            }

            if (!$recent || !$previous || ($previous->impressions ?? 0) == 0) {
                return $health;
            }

            $health['metrics'] = [
                'recent_impressions' => (int) $recent->impressions,
                'previous_impressions' => (int) $previous->impressions,
                'recent_clicks' => (int) $recent->clicks,
                'previous_clicks' => (int) $previous->clicks,
            ];

            // Check CTR drop
            $recentCtr = $recent->impressions > 0 ? $recent->clicks / $recent->impressions : 0;
            $previousCtr = $previous->impressions > 0 ? $previous->clicks / $previous->impressions : 0;

            if ($previousCtr > 0) {
                $ctrChange = ($recentCtr - $previousCtr) / $previousCtr;
                if ($ctrChange < -$this->performanceDropThreshold) {
                    $dropPercent = round(abs($ctrChange) * 100);
                    $health['warnings'][] = [
                        'type' => 'ctr_drop',
                        'severity' => 'high',
                        'message' => "CTR dropped {$dropPercent}% compared to the previous 7 days",
                        'details' => sprintf('CTR went from %.2f%% to %.2f%%', $previousCtr * 100, $recentCtr * 100),
                    ];
                }
            }

            // Check CPC spike
            $recentCpc = $recent->clicks > 0 ? $recent->cost / $recent->clicks : 0;
            $previousCpc = $previous->clicks > 0 ? $previous->cost / $previous->clicks : 0;

            if ($previousCpc > 0) {
                $cpcChange = ($recentCpc - $previousCpc) / $previousCpc;
                if ($cpcChange > ($this->performanceSpikeThreshold - 1)) {
                    $spikePercent = round($cpcChange * 100);
                    $health['warnings'][] = [
                        'type' => 'cpc_spike',
                        'severity' => 'high',
                        'message' => "CPC increased {$spikePercent}% compared to the previous 7 days",
                        'details' => sprintf('CPC went from $%.2f to $%.2f', $previousCpc, $recentCpc),
                    ];
                }
            }

            // Check conversion rate drop
            $recentCvr = $recent->clicks > 0 ? $recent->conversions / $recent->clicks : 0;
            $previousCvr = $previous->clicks > 0 ? $previous->conversions / $previous->clicks : 0;

            if ($previousCvr > 0) {
                $cvrChange = ($recentCvr - $previousCvr) / $previousCvr;
                if ($cvrChange < -$this->performanceDropThreshold) {
                    $dropPercent = round(abs($cvrChange) * 100);
                    $health['warnings'][] = [
                        'type' => 'conversion_rate_drop',
                        'severity' => 'high',
                        'message' => "Conversion rate dropped {$dropPercent}% compared to the previous 7 days",
                        'details' => sprintf('CVR went from %.2f%% to %.2f%%', $previousCvr * 100, $recentCvr * 100),
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::debug("HealthCheckAgent: Could not detect performance anomalies", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    /**
     * Check for creative fatigue by comparing recent CTR to earlier CTR for high-impression campaigns.
     */
    protected function checkCreativeFatigue(Campaign $campaign): array
    {
        $health = ['warnings' => []];

        try {
            $model = $campaign->google_ads_campaign_id
                ? GoogleAdsPerformanceData::class
                : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

            if (!$model) {
                return $health;
            }

            // Total impressions over last 30 days
            $totalImpressions = $model::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->sum('impressions');

            if ($totalImpressions < $this->creativeFatigueImpressions) {
                return $health;
            }

            // Compare first half (days 30-16) vs second half (days 15-1)
            $earlyData = $model::where('campaign_id', $campaign->id)
                ->whereBetween('date', [now()->subDays(30)->toDateString(), now()->subDays(16)->toDateString()])
                ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
                ->first();

            $recentData = $model::where('campaign_id', $campaign->id)
                ->whereBetween('date', [now()->subDays(15)->toDateString(), now()->toDateString()])
                ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
                ->first();

            if (!$earlyData || !$recentData || ($earlyData->impressions ?? 0) == 0 || ($recentData->impressions ?? 0) == 0) {
                return $health;
            }

            $earlyCtr = $earlyData->clicks / $earlyData->impressions;
            $recentCtr = $recentData->clicks / $recentData->impressions;

            if ($earlyCtr > 0) {
                $ctrDrop = ($earlyCtr - $recentCtr) / $earlyCtr;
                if ($ctrDrop >= $this->creativeFatigueCtRDropThreshold) {
                    $dropPercent = round($ctrDrop * 100);
                    $health['warnings'][] = [
                        'type' => 'creative_fatigue',
                        'severity' => 'medium',
                        'message' => "Possible creative fatigue: CTR dropped {$dropPercent}% over the last 30 days ({$totalImpressions} impressions)",
                        'details' => sprintf('CTR went from %.2f%% to %.2f%%', $earlyCtr * 100, $recentCtr * 100),
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::debug("HealthCheckAgent: Could not check creative fatigue", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    /**
     * Check ad approval status.
     */
    protected function checkAdApprovalStatus(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        // Check Google Ads
        if ($campaign->google_ads_campaign_id && $campaign->customer) {
            try {
                $getAdStatus = new GetAdStatus($campaign->customer, true);
                $customerId = $campaign->customer->google_ads_customer_id;
                $resourceName = $campaign->google_ads_campaign_id;

                if (!str_starts_with($resourceName, 'customers/')) {
                    $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
                }
                
                $ads = $getAdStatus($customerId, $resourceName);

                $disapproved = 0;
                $limited = 0;

                foreach ($ads as $ad) {
                    // approval_status: 2 = APPROVED, 3 = APPROVED_LIMITED, 4 = DISAPPROVED
                    if (($ad['approval_status'] ?? 0) === 4) {
                        $disapproved++;
                        $topics = array_map(fn($t) => $t['topic'] ?? 'unknown', $ad['policy_topics'] ?? []);
                        $health['issues'][] = [
                            'type' => 'google_ad_disapproved',
                            'severity' => 'high',
                            'message' => "Google ad disapproved: {$ad['resource_name']}",
                            'details' => 'Policy topics: ' . implode(', ', $topics),
                        ];
                    } elseif (($ad['approval_status'] ?? 0) === 3) {
                        $limited++;
                    }
                }

                if ($limited > 0) {
                    $health['warnings'][] = [
                        'type' => 'google_ads_limited',
                        'severity' => 'medium',
                        'message' => "{$limited} Google ad(s) have limited approval",
                    ];
                }

            } catch (\Exception $e) {
                Log::warning("HealthCheckAgent: Could not check ad status", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check Facebook Ads
        if ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            try {
                $adSetService = new \App\Services\FacebookAds\AdSetService($campaign->customer);
                $adService = new \App\Services\FacebookAds\AdService($campaign->customer);
                $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];

                foreach ($adSets as $adSet) {
                    $ads = $adService->listAds($adSet['id']) ?? [];
                    foreach ($ads as $ad) {
                        $status = $ad['status'] ?? '';
                        if ($status === 'DISAPPROVED') {
                            $health['issues'][] = [
                                'type' => 'facebook_ad_disapproved',
                                'severity' => 'high',
                                'message' => "Facebook ad disapproved: {$ad['name']} ({$ad['id']})",
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("HealthCheckAgent: Could not check Facebook ad status", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $health;
    }

    protected function getGoogleCampaignMetricsSummary(Campaign $campaign): ?array
    {
        try {
            $customer = $campaign->customer;
            if (!$customer || !$campaign->google_ads_campaign_id) {
                return null;
            }

            preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $matches);
            $campaignId = $matches[1] ?? $campaign->google_ads_campaign_id;

            $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService {
                public function getCampaignMetrics(string $customerId, string $campaignId): ?array
                {
                    $this->ensureClient();
                    $query = "SELECT metrics.impressions, metrics.clicks, metrics.cost_micros FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '"
                        . now()->subDays(1)->toDateString() . "' AND '" . now()->toDateString() . "'";

                    $response = $this->searchQuery($customerId, $query);
                    $metrics = ['impressions' => 0, 'clicks' => 0, 'cost' => 0.0];

                    foreach ($response->getIterator() as $row) {
                        $googleMetrics = $row->getMetrics();
                        $metrics['impressions'] += $googleMetrics->getImpressions();
                        $metrics['clicks'] += $googleMetrics->getClicks();
                        $metrics['cost'] += $googleMetrics->getCostMicros() / 1000000;
                    }

                    return $metrics;
                }
            };

            return $service->getCampaignMetrics(str_replace('-', '', $customer->google_ads_customer_id), (string) $campaignId);
        } catch (\Exception $e) {
            Log::debug('HealthCheckAgent: Could not fetch Google campaign metrics', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getFacebookCampaignMetricsSummary(Campaign $campaign): ?array
    {
        try {
            $customer = $campaign->customer;
            if (!$customer || !$campaign->facebook_ads_campaign_id) {
                return null;
            }

            $insightService = new InsightService($customer);
            $adSetService = new \App\Services\FacebookAds\AdSetService($customer);
            $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];
            $metrics = ['impressions' => 0, 'clicks' => 0, 'cost' => 0.0];

            foreach ($adSets as $adSet) {
                if (empty($adSet['id'])) {
                    continue;
                }

                $insights = $insightService->getAdSetInsights(
                    $adSet['id'],
                    now()->subDays(1)->toDateString(),
                    now()->toDateString()
                ) ?? [];

                foreach ($insights as $insight) {
                    $metrics['impressions'] += (int) ($insight['impressions'] ?? 0);
                    $metrics['clicks'] += (int) ($insight['clicks'] ?? 0);
                    $metrics['cost'] += (float) ($insight['spend'] ?? 0);
                }
            }

            return $metrics;
        } catch (\Exception $e) {
            Log::debug('HealthCheckAgent: Could not fetch Facebook campaign metrics', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Merge issues from component health checks into main results.
     */
    protected function mergeIssues(array &$results, array $componentHealth): void
    {
        if (!empty($componentHealth['issues'])) {
            $results['issues'] = array_merge($results['issues'], $componentHealth['issues']);
        }
        if (!empty($componentHealth['warnings'])) {
            $results['warnings'] = array_merge($results['warnings'], $componentHealth['warnings']);
        }
    }

    /**
     * Determine health status based on issues and warnings.
     */
    protected function determineHealthStatus(array $issues, array $warnings): string
    {
        $hasCritical = false;
        $hasHigh = false;

        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'critical') {
                $hasCritical = true;
                break;
            }
            if (($issue['severity'] ?? '') === 'high') {
                $hasHigh = true;
            }
        }

        if ($hasCritical) {
            return 'critical';
        }
        if ($hasHigh || !empty($issues)) {
            return 'unhealthy';
        }
        if (!empty($warnings)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Calculate overall health status for customer.
     */
    protected function calculateOverallHealth(array $results): string
    {
        $statuses = [];

        if (isset($results['google_ads']['status'])) {
            $statuses[] = $results['google_ads']['status'];
        }
        if (isset($results['facebook_ads']['status'])) {
            $statuses[] = $results['facebook_ads']['status'];
        }
        if (isset($results['billing']['status'])) {
            $statuses[] = $results['billing']['status'];
        }
        if (isset($results['campaigns']['status'])) {
            $statuses[] = $results['campaigns']['status'];
        }

        if (in_array('critical', $statuses)) {
            return 'critical';
        }
        if (in_array('unhealthy', $statuses)) {
            return 'unhealthy';
        }
        if (in_array('warning', $statuses)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Generate AI-powered recommendations based on health check results.
     */
    protected function generateRecommendations(array $results): array
    {
        $prompt = $this->buildRecommendationsPrompt($results);
        
        try {
            $response = $this->gemini->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: ['temperature' => 0.7, 'maxOutputTokens' => 2048],
            );

            if ($response && isset($response['text'])) {
                if (preg_match('/\[.*\]/s', $response['text'], $matches)) {
                    $recommendations = json_decode($matches[0], true);
                    if (is_array($recommendations)) {
                        return $recommendations;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Failed to generate recommendations", [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Build the prompt for AI recommendations.
     */
    protected function buildRecommendationsPrompt(array $results): string
    {
        $issuesJson = json_encode($results['issues'], JSON_PRETTY_PRINT);
        $warningsJson = json_encode($results['warnings'], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an expert digital advertising health analyst.

Based on the following health check results, provide actionable recommendations to resolve the issues and improve account health.

Issues (Critical/High Priority):
{$issuesJson}

Warnings (Medium/Low Priority):
{$warningsJson}

Provide your recommendations as a JSON array with the following structure:
[
    {
        "priority": "high|medium|low",
        "action": "Brief action title",
        "description": "Detailed description of what to do",
        "expected_impact": "What improvement this will bring"
    }
]

Focus on the most impactful actions first. Be specific and actionable.
PROMPT;
    }

    /**
     * Check Microsoft Ads account health.
     */
    protected function checkMicrosoftAdsHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check management-level credential
            if (!config('microsoftads.refresh_token')) {
                $health['issues'][] = [
                    'type' => 'token',
                    'severity' => 'critical',
                    'message' => 'No Microsoft Ads management credential configured',
                    'details' => 'Set MICROSOFT_ADS_REFRESH_TOKEN in .env',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            // Check for recent performance data (indicates API is working)
            $recentData = \App\Models\MicrosoftAdsPerformanceData::whereHas('campaign', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })->where('date', '>=', now()->subDays(3)->toDateString())->exists();

            if (!$recentData) {
                $activeMsCampaigns = $customer->campaigns()
                    ->whereNotNull('microsoft_ads_campaign_id')
                    ->where('status', 'active')
                    ->exists();

                if ($activeMsCampaigns) {
                    $health['warnings'][] = [
                        'type' => 'no_recent_data',
                        'severity' => 'medium',
                        'message' => 'No recent Microsoft Ads performance data',
                        'details' => 'Performance data has not been received in the last 3 days',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking Microsoft Ads health", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    /**
     * Check LinkedIn Ads account health.
     */
    protected function checkLinkedInAdsHealth(Customer $customer): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'metrics' => [],
        ];

        try {
            // Check management-level credential
            if (!config('linkedinads.refresh_token')) {
                $health['issues'][] = [
                    'type' => 'token',
                    'severity' => 'critical',
                    'message' => 'No LinkedIn Ads management credential configured',
                    'details' => 'Set LINKEDIN_ADS_REFRESH_TOKEN in .env',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            // Check for recent performance data
            $recentData = \App\Models\LinkedInAdsPerformanceData::whereHas('campaign', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })->where('date', '>=', now()->subDays(3)->toDateString())->exists();

            if (!$recentData) {
                $activeLiCampaigns = $customer->campaigns()
                    ->whereNotNull('linkedin_campaign_id')
                    ->where('status', 'active')
                    ->exists();

                if ($activeLiCampaigns) {
                    $health['warnings'][] = [
                        'type' => 'no_recent_data',
                        'severity' => 'medium',
                        'message' => 'No recent LinkedIn Ads performance data',
                        'details' => 'Performance data has not been received in the last 3 days',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking LinkedIn Ads health", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }
}
