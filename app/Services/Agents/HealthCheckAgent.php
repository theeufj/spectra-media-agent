<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\Health\BillingHealthChecker;
use App\Services\Health\CampaignHealthChecker;
use App\Services\Health\FacebookAdsHealthChecker;
use App\Services\Health\GoogleAdsHealthChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates daily health checks across all platforms and campaigns.
 * Delegates platform-specific checks to focused health checker classes.
 *
 * Platform health:   GoogleAdsHealthChecker, FacebookAdsHealthChecker
 * Billing health:    BillingHealthChecker
 * Campaign health:   CampaignHealthChecker (delivery, anomalies, fatigue)
 * AI recommendations: generated here using GeminiService
 */
class HealthCheckAgent
{
    public function __construct(
        private GeminiService $gemini,
        private GoogleAdsHealthChecker $googleChecker,
        private FacebookAdsHealthChecker $facebookChecker,
        private BillingHealthChecker $billingChecker,
        private CampaignHealthChecker $campaignChecker,
    ) {}

    public function checkCustomerHealth(Customer $customer): array
    {
        Log::info("HealthCheckAgent: Starting health check for customer {$customer->id}");

        $results = [
            'customer_id'    => $customer->id,
            'checked_at'     => now()->toIso8601String(),
            'overall_health' => 'healthy',
            'issues'         => [],
            'warnings'       => [],
            'metrics'        => [],
            'recommendations' => [],
        ];

        if ($customer->google_ads_customer_id) {
            $results['google_ads'] = $this->googleChecker->check($customer);
            $this->mergeIssues($results, $results['google_ads']);
        }

        if ($customer->facebook_ads_account_id) {
            $results['facebook_ads'] = $this->facebookChecker->check($customer);
            $this->mergeIssues($results, $results['facebook_ads']);
        }

        if ($customer->microsoft_ads_account_id) {
            $results['microsoft_ads'] = $this->checkMicrosoftAdsHealth($customer);
            $this->mergeIssues($results, $results['microsoft_ads']);
        }

        if ($customer->linkedin_ads_account_id) {
            $results['linkedin_ads'] = $this->checkLinkedInAdsHealth($customer);
            $this->mergeIssues($results, $results['linkedin_ads']);
        }

        $results['billing'] = $this->billingChecker->check($customer);
        $this->mergeIssues($results, $results['billing']);

        $results['campaigns'] = $this->campaignChecker->checkAll($customer);
        $this->mergeIssues($results, $results['campaigns']);

        $results['overall_health'] = $this->calculateOverallHealth($results);

        if (!empty($results['issues']) || !empty($results['warnings'])) {
            $results['recommendations'] = $this->generateRecommendations($results);
        }

        Cache::put("health_check:customer:{$customer->id}", $results, now()->addHours(6));

        Log::info("HealthCheckAgent: Completed health check for customer {$customer->id}", [
            'overall_health' => $results['overall_health'],
            'issues_count'   => count($results['issues']),
            'warnings_count' => count($results['warnings']),
        ]);

        return $results;
    }

    private function checkMicrosoftAdsHealth(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            if (!config('microsoftads.refresh_token')) {
                $health['issues'][] = [
                    'type'     => 'token',
                    'severity' => 'critical',
                    'message'  => 'No Microsoft Ads management credential configured',
                    'details'  => 'Set MICROSOFT_ADS_REFRESH_TOKEN in .env',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            $recentData = \App\Models\MicrosoftAdsPerformanceData::whereHas('campaign', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })->where('date', '>=', now()->subDays(3)->toDateString())->exists();

            if (!$recentData && $customer->campaigns()->whereNotNull('microsoft_ads_campaign_id')->where('status', 'active')->exists()) {
                $health['warnings'][] = [
                    'type'     => 'no_recent_data',
                    'severity' => 'medium',
                    'message'  => 'No recent Microsoft Ads performance data',
                    'details'  => 'Performance data has not been received in the last 3 days',
                ];
            }
        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking Microsoft Ads health", ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    private function checkLinkedInAdsHealth(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            if (!config('linkedinads.refresh_token')) {
                $health['issues'][] = [
                    'type'     => 'token',
                    'severity' => 'critical',
                    'message'  => 'No LinkedIn Ads management credential configured',
                    'details'  => 'Set LINKEDIN_ADS_REFRESH_TOKEN in .env',
                ];
                $health['status'] = 'critical';
                return $health;
            }

            $recentData = \App\Models\LinkedInAdsPerformanceData::whereHas('campaign', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })->where('date', '>=', now()->subDays(3)->toDateString())->exists();

            if (!$recentData && $customer->campaigns()->whereNotNull('linkedin_campaign_id')->where('status', 'active')->exists()) {
                $health['warnings'][] = [
                    'type'     => 'no_recent_data',
                    'severity' => 'medium',
                    'message'  => 'No recent LinkedIn Ads performance data',
                    'details'  => 'Performance data has not been received in the last 3 days',
                ];
            }
        } catch (\Exception $e) {
            Log::error("HealthCheckAgent: Error checking LinkedIn Ads health", ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    private function mergeIssues(array &$results, array $componentHealth): void
    {
        if (!empty($componentHealth['issues'])) {
            $results['issues'] = array_merge($results['issues'], $componentHealth['issues']);
        }
        if (!empty($componentHealth['warnings'])) {
            $results['warnings'] = array_merge($results['warnings'], $componentHealth['warnings']);
        }
    }

    private function determineHealthStatus(array $issues, array $warnings): string
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'critical') return 'critical';
        }
        if (!empty($issues)) return 'unhealthy';
        if (!empty($warnings)) return 'warning';
        return 'healthy';
    }

    private function calculateOverallHealth(array $results): string
    {
        $statuses = array_filter([
            $results['google_ads']['status']   ?? null,
            $results['facebook_ads']['status'] ?? null,
            $results['billing']['status']      ?? null,
            $results['campaigns']['status']    ?? null,
            $results['microsoft_ads']['status'] ?? null,
            $results['linkedin_ads']['status']  ?? null,
        ]);

        if (in_array('critical', $statuses))  return 'critical';
        if (in_array('unhealthy', $statuses)) return 'unhealthy';
        if (in_array('warning', $statuses))   return 'warning';
        return 'healthy';
    }

    private function generateRecommendations(array $results): array
    {
        $issuesJson   = json_encode($results['issues'],   JSON_PRETTY_PRINT);
        $warningsJson = json_encode($results['warnings'], JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
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
            Log::error("HealthCheckAgent: Failed to generate recommendations", ['error' => $e->getMessage()]);
        }

        return [];
    }
}
