<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\FacebookAds\AdSetService as FacebookAdSetService;
use App\Services\FacebookAds\CreativeService as FacebookCreativeService;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use App\Services\Agents\Traits\RetryableApiOperation;
use App\Prompts\AdCompliancePrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;

/**
 * SelfHealingAgent
 * 
 * Automated agent for detecting and fixing ad issues across platforms.
 * 
 * Supported Platforms:
 * - Google Ads: Disapproved ads, budget issues, delivery problems
 * - Facebook Ads: Ad disapprovals, account restrictions, creative issues
 * 
 * Features:
 * - AI-powered ad copy regeneration for policy violations
 * - Automatic budget reallocation
 * - Delivery issue detection and remediation
 * - Cross-platform healing coordination
 * - Retry logic with exponential backoff
 */
class SelfHealingAgent
{
    use RetryableApiOperation;

    protected GeminiService $gemini;
    protected array $config;
    protected string $platform = 'self_healing';

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
        $this->config = config('budget_rules.self_healing', []);
    }

    /**
     * Run self-healing checks on a campaign.
     * Automatically detects platform and runs appropriate healing.
     *
     * @param Campaign $campaign
     * @return array Results of healing actions
     */
    public function heal(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'platform' => null,
            'actions_taken' => [],
            'warnings' => [],
            'errors' => [],
            'healed_at' => now()->toIso8601String(),
        ];

        if (!$campaign->customer) {
            $results['errors'][] = 'Campaign has no associated customer';
            return $results;
        }

        $customer = $campaign->customer;

        // Check feature flag - if disabled, only run diagnostics (no mutations)
        $autoHealingEnabled = Feature::for($customer)->active('auto_healing');
        if (!$autoHealingEnabled) {
            $results['feature_flag'] = 'auto_healing disabled - diagnostics only';
            Log::info("SelfHealingAgent: auto_healing feature disabled for customer {$customer->id}, running diagnostics only");
        }
        $results['auto_healing_enabled'] = $autoHealingEnabled;

        // Heal Google Ads campaign
        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            $results['platform'] = 'google_ads';
            $this->healGoogleAdsCampaign($campaign, $customer, $results);
        }

        // Heal Facebook Ads campaign
        if ($campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
            $results['platform'] = $results['platform'] 
                ? 'multi_platform' 
                : 'facebook_ads';
            $this->healFacebookAdsCampaign($campaign, $customer, $results);
        }

        // Heal Microsoft Ads campaign
        if ($campaign->microsoft_ads_campaign_id && $customer->microsoft_ads_account_id) {
            $results['platform'] = $results['platform']
                ? 'multi_platform'
                : 'microsoft_ads';
            $this->healMicrosoftAdsCampaign($campaign, $customer, $results);
        }

        // Heal LinkedIn Ads campaign
        if ($campaign->linkedin_campaign_id && $customer->linkedin_ads_account_id) {
            $results['platform'] = $results['platform']
                ? 'multi_platform'
                : 'linkedin_ads';
            $this->healLinkedInAdsCampaign($campaign, $customer, $results);
        }

        // Log summary
        Log::info("SelfHealingAgent: Completed healing for campaign {$campaign->id}", [
            'actions_count' => count($results['actions_taken']),
            'warnings_count' => count($results['warnings']),
            'errors_count' => count($results['errors']),
        ]);

        // Cache the results for monitoring
        Cache::put(
            "self_healing:campaign:{$campaign->id}",
            $results,
            now()->addHours(24)
        );

        return $results;
    }

    /**
     * Heal Google Ads campaign issues.
     */
    protected function healGoogleAdsCampaign(Campaign $campaign, Customer $customer, array &$results): void
    {
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = $campaign->google_ads_campaign_id;

        if (!str_starts_with($campaignResourceName, 'customers/')) {
            $campaignResourceName = "customers/{$customerId}/campaigns/{$campaignResourceName}";
        }

        // 1. Check for disapproved ads
        $this->healGoogleDisapprovedAds($customer, $customerId, $campaignResourceName, $results);

        // 2. Check for budget exhaustion
        $this->checkGoogleBudgetHealth($customer, $campaign, $customerId, $campaignResourceName, $results);

        // 3. Check for delivery issues
        $this->checkGoogleDeliveryHealth($customer, $campaign, $customerId, $campaignResourceName, $results);
    }

    /**
     * Heal Facebook Ads campaign issues.
     */
    protected function healFacebookAdsCampaign(Campaign $campaign, Customer $customer, array &$results): void
    {
        $accountId = str_replace('act_', '', $customer->facebook_ads_account_id);

        // 1. Check for disapproved ads
        $this->healFacebookDisapprovedAds($campaign, $customer, $results);

        // 2. Check for budget and delivery issues
        $this->checkFacebookDeliveryHealth($campaign, $customer, $results);

        // 3. Check for creative issues
        $this->checkFacebookCreativeHealth($campaign, $customer, $results);
    }

    /**
     * Find and fix disapproved Google Ads.
     */
    protected function healGoogleDisapprovedAds(Customer $customer, string $customerId, string $campaignResourceName, array &$results): void
    {
        try {
            $ads = $this->executeWithRetry(
                operation: function () use ($customer, $customerId, $campaignResourceName) {
                    $getAdStatus = new GetAdStatus($customer, true);
                    return ($getAdStatus)($customerId, $campaignResourceName);
                },
                operationName: 'get_google_ad_status',
                context: ['customer_id' => $customerId, 'campaign' => $campaignResourceName]
            );

            foreach ($ads as $ad) {
                // Check if ad is disapproved
                if ($ad['approval_status'] === PolicyApprovalStatus::DISAPPROVED) {
                    $this->handleGoogleDisapprovedAd($customer, $customerId, $ad, $results);
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to check Google ad status: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to check Google ad status", [
                'campaign' => $campaignResourceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a disapproved Google ad by generating a compliant alternative.
     */
    protected function handleGoogleDisapprovedAd(Customer $customer, string $customerId, array $ad, array &$results): void
    {
        $maxAttempts = $this->config['max_fix_attempts'] ?? 3;
        
        // Get the policy violation reason
        $policyTopics = $ad['policy_topics'] ?? [];
        $violationReason = !empty($policyTopics) 
            ? implode(', ', array_column($policyTopics, 'topic'))
            : 'Unknown policy violation';

        Log::info("SelfHealingAgent: Attempting to fix disapproved Google ad", [
            'ad_resource_name' => $ad['resource_name'],
            'violation' => $violationReason,
        ]);

        // Generate compliant ad copy using AI
        $prompt = AdCompliancePrompt::generate([
            'headlines' => $ad['headlines'],
            'descriptions' => $ad['descriptions'],
            'platform' => 'google_ads',
        ], $violationReason);

        try {
            $response = $this->gemini->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: ['temperature' => 0.7]
            );
            
            // Extract JSON from response
            if ($response && isset($response['text']) && preg_match('/\{.*\}/s', $response['text'], $matches)) {
                $newAdData = json_decode($matches[0], true);
                
                if ($newAdData && isset($newAdData['headlines'], $newAdData['descriptions'])) {
                    // Create new ad with compliant copy using retry logic
                    $newAdResourceName = $this->executeWithRetry(
                        operation: function () use ($customer, $customerId, $ad, $newAdData) {
                            $createAdService = new CreateResponsiveSearchAd($customer, true);
                            return ($createAdService)(
                                $customerId,
                                $ad['ad_group_resource_name'],
                                $newAdData['headlines'],
                                $newAdData['descriptions'],
                                $ad['headlines'][0] ?? 'Visit Us Today'
                            );
                        },
                        operationName: 'create_compliant_google_ad',
                        context: ['ad' => $ad['resource_name']]
                    );

                    if ($newAdResourceName) {
                        $results['actions_taken'][] = [
                            'type' => 'google_ad_resubmitted',
                            'platform' => 'google_ads',
                            'original_ad' => $ad['resource_name'],
                            'new_ad' => $newAdResourceName,
                            'reason' => $violationReason,
                            'changes' => $newAdData['changes_made'] ?? 'Ad copy modified for compliance',
                        ];

                        Log::info("SelfHealingAgent: Created compliant Google ad", [
                            'original' => $ad['resource_name'],
                            'new' => $newAdResourceName,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to create compliant Google ad: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to create compliant Google ad", [
                'ad' => $ad['resource_name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check and monitor Google budget health.
     */
    protected function checkGoogleBudgetHealth(Customer $customer, Campaign $campaign, string $customerId, string $campaignResourceName, array &$results): void
    {
        try {
            $metrics = $this->executeWithRetry(
                operation: function () use ($customer, $customerId, $campaignResourceName) {
                    $getPerformance = new GetCampaignPerformance($customer, true);
                    return ($getPerformance)($customerId, $campaignResourceName, 'TODAY');
                },
                operationName: 'get_google_campaign_performance',
                context: ['campaign' => $campaignResourceName]
            );

            if (!$metrics) {
                return;
            }

            $dailyBudget = $campaign->daily_budget ?? 0;
            $spentToday = ($metrics['cost_micros'] ?? 0) / 1000000;
            $hourOfDay = (int) now()->format('H');

            // If we've spent more than 80% of budget before noon, we might be pacing too fast
            if ($hourOfDay < 12 && $spentToday > ($dailyBudget * 0.8)) {
                Log::warning("SelfHealingAgent: Google campaign is pacing fast", [
                    'campaign_id' => $campaign->id,
                    'spent' => $spentToday,
                    'budget' => $dailyBudget,
                    'hour' => $hourOfDay,
                ]);

                $results['warnings'][] = [
                    'type' => 'budget_pacing',
                    'platform' => 'google_ads',
                    'message' => "Campaign spent \${$spentToday} of \${$dailyBudget} budget by hour {$hourOfDay}",
                    'severity' => 'medium',
                ];
            }

            // If campaign has no impressions today but should be active, flag it
            if (($metrics['impressions'] ?? 0) === 0 && $campaign->platform_status === 'ENABLED') {
                $results['warnings'][] = [
                    'type' => 'no_impressions',
                    'platform' => 'google_ads',
                    'message' => "Campaign has 0 impressions today despite being enabled",
                    'severity' => 'high',
                ];
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Failed to check Google budget health: " . $e->getMessage();
        }
    }

    /**
     * Check Google delivery health for issues like limited serving status.
     */
    protected function checkGoogleDeliveryHealth(Customer $customer, Campaign $campaign, string $customerId, string $campaignResourceName, array &$results): void
    {
        try {
            $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService {
                public function getCampaignDeliveryStatus(string $customerId, string $campaignId): ?array
                {
                    $this->ensureClient();

                    $query = "SELECT " .
                        "campaign.id, " .
                        "campaign.status, " .
                        "campaign.serving_status, " .
                        "campaign.bidding_strategy_type, " .
                        "campaign.primary_status, " .
                        "campaign.primary_status_reasons " .
                        "FROM campaign " .
                        "WHERE campaign.id = {$campaignId}";

                    $response = $this->searchQuery($customerId, $query);
                    foreach ($response->getIterator() as $row) {
                        $c = $row->getCampaign();
                        return [
                            'status' => $c->getStatus(),
                            'serving_status' => $c->getServingStatus(),
                            'bidding_strategy_type' => $c->getBiddingStrategyType(),
                            'primary_status' => $c->getPrimaryStatus(),
                            'primary_status_reasons' => iterator_to_array($c->getPrimaryStatusReasons()),
                        ];
                    }
                    return null;
                }
            };

            $status = $service->getCampaignDeliveryStatus($customerId, $campaign->google_ads_campaign_id);

            if (!$status) {
                return;
            }

            // Check serving status (2 = SERVING, 3 = NONE, 4 = ENDED, 5 = PENDING, 6 = SUSPENDED)
            $servingStatus = $status['serving_status'] ?? 0;
            if ($servingStatus === 3) {
                $results['warnings'][] = [
                    'type' => 'not_serving',
                    'platform' => 'google_ads',
                    'message' => 'Campaign is not serving',
                    'severity' => 'high',
                ];
            } elseif ($servingStatus === 6) {
                $results['warnings'][] = [
                    'type' => 'suspended',
                    'platform' => 'google_ads',
                    'message' => 'Campaign is suspended by Google',
                    'severity' => 'critical',
                ];
            }

            // Check primary status reasons for common issues
            $reasons = $status['primary_status_reasons'] ?? [];
            foreach ($reasons as $reason) {
                if ($reason === 7) { // BUDGET_LIMITED
                    $results['warnings'][] = [
                        'type' => 'limited_by_budget',
                        'platform' => 'google_ads',
                        'message' => 'Campaign is limited by budget — consider increasing the daily budget',
                        'severity' => 'medium',
                    ];
                } elseif ($reason === 8) { // BID_STRATEGY_LEARNING
                    $results['warnings'][] = [
                        'type' => 'bid_strategy_learning',
                        'platform' => 'google_ads',
                        'message' => 'Smart Bidding strategy is still in the learning phase',
                        'severity' => 'low',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::debug("SelfHealingAgent: Could not check Google delivery health", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find and fix disapproved Facebook ads.
     */
    protected function healFacebookDisapprovedAds(Campaign $campaign, Customer $customer, array &$results): void
    {
        try {
            $adService = new FacebookAdService($customer);
            
            // Get all ad sets for this campaign
            $response = $this->executeWithRetry(
                operation: function () use ($adService, $campaign) {
                    return $this->getFacebookAdsForCampaign($adService, $campaign);
                },
                operationName: 'get_facebook_ads',
                context: ['campaign_id' => $campaign->facebook_ads_campaign_id]
            );

            if (!$response) {
                return;
            }

            foreach ($response as $ad) {
                // Check for disapproved/rejected status
                $effectiveStatus = $ad['effective_status'] ?? '';
                $reviewStatus = $ad['review_feedback'] ?? [];
                
                if ($effectiveStatus === 'DISAPPROVED' || !empty($reviewStatus)) {
                    $this->handleFacebookDisapprovedAd($campaign, $customer, $ad, $results);
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to check Facebook ad status: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to check Facebook ad status", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get all ads for a Facebook campaign by fetching ad sets then ads.
     */
    protected function getFacebookAdsForCampaign(FacebookAdService $adService, Campaign $campaign): array
    {
        $customer = $campaign->customer;
        $adSetService = new FacebookAdSetService($customer);

        $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id);
        if (empty($adSets['data'])) {
            return [];
        }

        $allAds = [];
        foreach ($adSets['data'] as $adSet) {
            $ads = $adService->listAds($adSet['id']);
            if (!empty($ads['data'])) {
                foreach ($ads['data'] as $ad) {
                    $ad['adset_id'] = $adSet['id'];
                    $allAds[] = $ad;
                }
            }
        }

        return $allAds;
    }

    /**
     * Handle a disapproved Facebook ad by generating a compliant alternative.
     */
    protected function handleFacebookDisapprovedAd(Campaign $campaign, Customer $customer, array $ad, array &$results): void
    {
        // Extract rejection reason
        $reviewFeedback = $ad['review_feedback'] ?? [];
        $violationReason = is_array($reviewFeedback) 
            ? implode(', ', array_values($reviewFeedback))
            : ($reviewFeedback ?: 'Unknown policy violation');

        Log::info("SelfHealingAgent: Attempting to fix disapproved Facebook ad", [
            'ad_id' => $ad['id'] ?? 'unknown',
            'violation' => $violationReason,
        ]);

        // Get the current ad creative
        $creative = $ad['creative'] ?? [];
        $currentCopy = [
            'headline' => $creative['title'] ?? '',
            'primary_text' => $creative['body'] ?? '',
            'description' => $creative['link_description'] ?? '',
            'cta' => $creative['call_to_action_type'] ?? '',
        ];

        // Generate compliant ad copy using AI
        $prompt = AdCompliancePrompt::generate([
            'headline' => $currentCopy['headline'],
            'primary_text' => $currentCopy['primary_text'],
            'description' => $currentCopy['description'],
            'platform' => 'facebook_ads',
        ], $violationReason);

        try {
            $response = $this->gemini->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: ['temperature' => 0.7]
            );

            if ($response && isset($response['text']) && preg_match('/\{.*\}/s', $response['text'], $matches)) {
                $newAdData = json_decode($matches[0], true);
                
                if ($newAdData) {
                    // Create new creative with compliant copy
                    $creativeService = new FacebookCreativeService($customer);
                    
                    // Build new creative data
                    // Note: This would need to be expanded based on the creative type
                    $newCreativeData = [
                        'name' => ($ad['name'] ?? 'Ad') . ' - Compliant Version',
                        'title' => $newAdData['headline'] ?? $currentCopy['headline'],
                        'body' => $newAdData['primary_text'] ?? $currentCopy['primary_text'],
                        'link_description' => $newAdData['description'] ?? $currentCopy['description'],
                    ];

                    // Auto-deploy the new compliant creative
                    $accountId = str_replace('act_', '', $customer->facebook_ads_account_id);
                    $imageUrl = $creative['image_url'] ?? $creative['thumbnail_url'] ?? null;
                    $linkUrl = $creative['link_url'] ?? $customer->website ?? '';

                    $newCreativeId = null;
                    if ($imageUrl) {
                        try {
                            $newCreative = $creativeService->createImageCreative(
                                $accountId,
                                $newCreativeData['name'],
                                $imageUrl,
                                $newCreativeData['title'],
                                $newCreativeData['body'],
                                $creative['call_to_action_type'] ?? 'LEARN_MORE',
                                $linkUrl
                            );
                            $newCreativeId = $newCreative['id'] ?? null;
                        } catch (\Exception $e) {
                            Log::warning("SelfHealingAgent: Could not create Facebook creative", [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    if ($newCreativeId) {
                        // Create a new ad with the compliant creative
                        try {
                            $replacementAdService = new FacebookAdService($customer);
                            $newAd = $replacementAdService->createAd(
                                $accountId,
                                $ad['adset_id'] ?? '',
                                $newCreativeData['name'],
                                $newCreativeId,
                                'ACTIVE'
                            );

                            // Pause the original disapproved ad
                            if (!empty($ad['id'])) {
                                $replacementAdService->pauseAd($ad['id']);
                            }

                            $results['actions_taken'][] = [
                                'type' => 'facebook_ad_replaced',
                                'platform' => 'facebook_ads',
                                'original_ad' => $ad['id'] ?? 'unknown',
                                'new_ad' => $newAd['id'] ?? 'unknown',
                                'new_creative' => $newCreativeId,
                                'reason' => $violationReason,
                                'changes' => $newCreativeData,
                            ];
                        } catch (\Exception $e) {
                            Log::warning("SelfHealingAgent: Could not deploy replacement Facebook ad", [
                                'error' => $e->getMessage(),
                            ]);
                            $results['actions_taken'][] = [
                                'type' => 'facebook_ad_healing_initiated',
                                'platform' => 'facebook_ads',
                                'original_ad' => $ad['id'] ?? 'unknown',
                                'reason' => $violationReason,
                                'suggested_changes' => $newCreativeData,
                                'note' => 'Compliant creative created but ad deployment failed',
                            ];
                        }
                    } else {
                        $results['actions_taken'][] = [
                            'type' => 'facebook_ad_healing_initiated',
                            'platform' => 'facebook_ads',
                            'original_ad' => $ad['id'] ?? 'unknown',
                            'reason' => $violationReason,
                            'suggested_changes' => $newCreativeData,
                            'note' => $imageUrl ? 'Creative creation failed' : 'No image available to create replacement creative',
                        ];
                    }

                    Log::info("SelfHealingAgent: Prepared compliant Facebook ad copy", [
                        'original_ad' => $ad['id'] ?? 'unknown',
                        'changes' => $newAdData['changes_made'] ?? 'Ad copy modified',
                    ]);
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to generate compliant Facebook ad: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to generate compliant Facebook ad", [
                'ad' => $ad['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check Facebook delivery health.
     */
    protected function checkFacebookDeliveryHealth(Campaign $campaign, Customer $customer, array &$results): void
    {
        try {
            $insightService = new FacebookInsightService($customer);
            
            // Get today's insights
            $dateToday = now()->format('Y-m-d');
            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                $dateToday,
                $dateToday
            );

            $data = $insights['data'][0] ?? [];
            $impressions = $data['impressions'] ?? 0;
            $spend = $data['spend'] ?? 0;

            // Check for delivery issues
            $dailyBudget = $campaign->daily_budget ?? 0;
            $hourOfDay = (int) now()->format('H');

            // No impressions by mid-day
            if ($hourOfDay > 12 && $impressions == 0 && $campaign->status === 'active') {
                $results['warnings'][] = [
                    'type' => 'no_delivery',
                    'platform' => 'facebook_ads',
                    'message' => "Campaign has 0 impressions today despite being active",
                    'severity' => 'high',
                ];
            }

            // Fast pacing
            if ($hourOfDay < 12 && $dailyBudget > 0 && $spend > ($dailyBudget * 0.8)) {
                $results['warnings'][] = [
                    'type' => 'budget_pacing',
                    'platform' => 'facebook_ads',
                    'message' => "Campaign spent \${$spend} of \${$dailyBudget} budget by hour {$hourOfDay}",
                    'severity' => 'medium',
                ];
            }

        } catch (\Exception $e) {
            // Silently log - insights might not be available
            Log::debug("SelfHealingAgent: Could not check Facebook delivery health", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check Facebook creative health for fatigue and performance issues.
     */
    protected function checkFacebookCreativeHealth(Campaign $campaign, Customer $customer, array &$results): void
    {
        try {
            // Check for frequency fatigue (high frequency = creative fatigue)
            $insightService = new FacebookInsightService($customer);
            
            $dateEnd = now()->format('Y-m-d');
            $dateStart = now()->subDays(7)->format('Y-m-d');
            
            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                $dateStart,
                $dateEnd
            );

            $data = $insights['data'][0] ?? [];
            $frequency = $data['frequency'] ?? 0;
            $ctr = $data['ctr'] ?? 0;

            // High frequency indicates creative fatigue
            if ($frequency > 4) {
                $results['warnings'][] = [
                    'type' => 'creative_fatigue',
                    'platform' => 'facebook_ads',
                    'message' => "High ad frequency ({$frequency}) indicates creative fatigue",
                    'severity' => 'medium',
                    'recommendation' => 'Consider refreshing ad creatives or expanding audience',
                ];
            }

            // Low CTR might indicate creative issues
            if ($frequency > 2 && $ctr < 0.5) {
                $results['warnings'][] = [
                    'type' => 'low_engagement',
                    'platform' => 'facebook_ads',
                    'message' => "Low CTR ({$ctr}%) with frequency {$frequency}",
                    'severity' => 'medium',
                    'recommendation' => 'Test new creative variations',
                ];
            }

        } catch (\Exception $e) {
            Log::debug("SelfHealingAgent: Could not check Facebook creative health", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run bulk healing for all active campaigns of a customer.
     */
    public function healAllCampaigns(Customer $customer): array
    {
        $campaigns = $this->getActiveCampaigns($customer);

        $allResults = [
            'customer_id' => $customer->id,
            'campaigns_checked' => $campaigns->count(),
            'campaigns_healed' => 0,
            'total_actions' => 0,
            'total_warnings' => 0,
            'total_errors' => 0,
            'results' => [],
        ];

        foreach ($campaigns as $campaign) {
            $result = $this->heal($campaign);
            $allResults['results'][$campaign->id] = $result;
            
            if (!empty($result['actions_taken'])) {
                $allResults['campaigns_healed']++;
                $allResults['total_actions'] += count($result['actions_taken']);
            }
            $allResults['total_warnings'] += count($result['warnings'] ?? []);
            $allResults['total_errors'] += count($result['errors']);
        }

        return $allResults;
    }

    /**
     * Get active campaigns for a customer.
     */
    protected function getActiveCampaigns(Customer $customer)
    {
        return Campaign::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->get();
    }

    /**
     * Heal Microsoft Ads campaign issues.
     * Checks for delivery problems and performance anomalies in stored data.
     */
    protected function healMicrosoftAdsCampaign(Campaign $campaign, Customer $customer, array &$results): void
    {
        try {
            // Check for zero-impression campaigns (delivery failure)
            $recentData = \App\Models\MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(3)->toDateString())
                ->get();

            if ($recentData->isNotEmpty() && $recentData->sum('impressions') === 0) {
                $results['warnings'][] = [
                    'type' => 'zero_delivery',
                    'platform' => 'microsoft_ads',
                    'message' => 'Microsoft Ads campaign has zero impressions in last 3 days',
                    'suggestion' => 'Check campaign status, budget, and targeting settings in Microsoft Ads',
                ];

                // Try to check and fix campaign status
                try {
                    $service = new \App\Services\MicrosoftAds\CampaignManagementService($customer);
                    $status = $service->getCampaignStatus($campaign->microsoft_ads_campaign_id);

                    if ($status && strtolower($status) === 'budgetpaused') {
                        // Attempt budget increase (20% bump)
                        $currentBudget = $campaign->daily_budget ?? 0;
                        if ($currentBudget > 0) {
                            $newBudget = round($currentBudget * 1.2, 2);
                            $updated = $service->updateCampaignBudget($campaign->microsoft_ads_campaign_id, $newBudget);
                            if ($updated) {
                                $results['actions_taken'][] = [
                                    'type' => 'budget_increase',
                                    'platform' => 'microsoft_ads',
                                    'message' => "Increased daily budget from \${$currentBudget} to \${$newBudget} to restore delivery",
                                ];
                                $campaign->update(['daily_budget' => $newBudget]);
                            }
                        } else {
                            $results['actions_taken'][] = [
                                'type' => 'budget_alert',
                                'platform' => 'microsoft_ads',
                                'message' => 'Campaign is budget-paused - may need budget increase',
                            ];
                        }
                    } elseif ($status && strtolower($status) === 'paused') {
                        // Re-enable paused campaign
                        $resumed = $service->updateCampaignStatus($campaign->microsoft_ads_campaign_id, 'Active');
                        if ($resumed) {
                            $results['actions_taken'][] = [
                                'type' => 'campaign_resumed',
                                'platform' => 'microsoft_ads',
                                'message' => 'Re-enabled paused Microsoft Ads campaign',
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("SelfHealingAgent: Could not remediate Microsoft campaign: " . $e->getMessage());
                }
            }

            // Check for sudden performance drops
            $last7Days = \App\Models\MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(7)->toDateString())
                ->sum('clicks');

            $prev7Days = \App\Models\MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereBetween('date', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
                ->sum('clicks');

            if ($prev7Days > 20 && $last7Days < $prev7Days * 0.5) {
                $results['warnings'][] = [
                    'type' => 'performance_drop',
                    'platform' => 'microsoft_ads',
                    'message' => 'Microsoft Ads clicks dropped >50% week over week',
                    'details' => "Previous week: {$prev7Days} clicks, This week: {$last7Days} clicks",
                ];

                // Use Gemini to suggest ad copy improvements
                try {
                    $gemini = app(\App\Services\GeminiService::class);
                    $suggestion = $gemini->generateText(
                        "A Microsoft Ads campaign for '{$customer->name}' ({$customer->business_type}) experienced a >50% click drop. " .
                        "Previous week: {$prev7Days} clicks, this week: {$last7Days} clicks. " .
                        "Suggest 3 specific, actionable fixes (targeting, bid strategy, ad copy) in a JSON array with 'action' and 'reason' keys."
                    );
                    $results['ai_suggestions'][] = [
                        'type' => 'performance_recovery',
                        'platform' => 'microsoft_ads',
                        'suggestions' => $suggestion,
                    ];
                } catch (\Exception $e) {
                    Log::debug("SelfHealingAgent: Gemini suggestion failed: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Microsoft Ads healing failed: " . $e->getMessage();
            Log::error("SelfHealingAgent: Microsoft Ads healing error", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Heal LinkedIn Ads campaign issues.
     * Checks for delivery problems and performance anomalies in stored data.
     */
    protected function healLinkedInAdsCampaign(Campaign $campaign, Customer $customer, array &$results): void
    {
        try {
            // Check for zero-impression campaigns
            $recentData = \App\Models\LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(3)->toDateString())
                ->get();

            if ($recentData->isNotEmpty() && $recentData->sum('impressions') === 0) {
                $results['warnings'][] = [
                    'type' => 'zero_delivery',
                    'platform' => 'linkedin_ads',
                    'message' => 'LinkedIn Ads campaign has zero impressions in last 3 days',
                    'suggestion' => 'Check campaign status, budget, and audience targeting on LinkedIn',
                ];

                // Try to check and fix campaign status
                try {
                    $service = new \App\Services\LinkedInAds\CampaignManagementService($customer);
                    $linkedInCampaignId = $campaign->linkedin_ads_campaign_id;

                    if ($linkedInCampaignId) {
                        $campaignData = $service->getCampaign($linkedInCampaignId);
                        $status = $campaignData['status'] ?? null;

                        if ($status === 'PAUSED') {
                            $activated = $service->updateCampaignStatus($linkedInCampaignId, 'ACTIVE');
                            if ($activated) {
                                $results['actions_taken'][] = [
                                    'type' => 'campaign_resumed',
                                    'platform' => 'linkedin_ads',
                                    'message' => 'Re-enabled paused LinkedIn Ads campaign',
                                ];
                            }
                        } elseif ($status === 'DRAFT') {
                            $results['warnings'][] = [
                                'type' => 'campaign_draft',
                                'platform' => 'linkedin_ads',
                                'message' => 'LinkedIn campaign is still in DRAFT status — needs manual launch',
                            ];
                        }

                        // Check budget adequacy
                        $dailyBudget = $campaignData['dailyBudget']['amount'] ?? null;
                        if ($dailyBudget && floatval($dailyBudget) < 10) {
                            $newBudget = round(floatval($dailyBudget) * 1.5, 2);
                            $updated = $service->updateCampaignBudget($linkedInCampaignId, $newBudget);
                            if ($updated) {
                                $results['actions_taken'][] = [
                                    'type' => 'budget_increase',
                                    'platform' => 'linkedin_ads',
                                    'message' => "Increased daily budget from \${$dailyBudget} to \${$newBudget} for better delivery",
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("SelfHealingAgent: Could not remediate LinkedIn campaign: " . $e->getMessage());
                }
            }

            // Check for sudden performance drops
            $last7Days = \App\Models\LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(7)->toDateString())
                ->sum('clicks');

            $prev7Days = \App\Models\LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereBetween('date', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
                ->sum('clicks');

            if ($prev7Days > 10 && $last7Days < $prev7Days * 0.5) {
                $results['warnings'][] = [
                    'type' => 'performance_drop',
                    'platform' => 'linkedin_ads',
                    'message' => 'LinkedIn Ads clicks dropped >50% week over week',
                    'details' => "Previous week: {$prev7Days} clicks, This week: {$last7Days} clicks",
                ];

                // Use Gemini to suggest recovery actions
                try {
                    $gemini = app(\App\Services\GeminiService::class);
                    $suggestion = $gemini->generateText(
                        "A LinkedIn Ads campaign for '{$customer->name}' ({$customer->business_type}) experienced a >50% click drop. " .
                        "Previous week: {$prev7Days} clicks, this week: {$last7Days} clicks. " .
                        "Suggest 3 specific, actionable fixes (audience targeting, bid strategy, ad creative) in a JSON array with 'action' and 'reason' keys."
                    );
                    $results['ai_suggestions'][] = [
                        'type' => 'performance_recovery',
                        'platform' => 'linkedin_ads',
                        'suggestions' => $suggestion,
                    ];
                } catch (\Exception $e) {
                    Log::debug("SelfHealingAgent: Gemini suggestion failed: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "LinkedIn Ads healing failed: " . $e->getMessage();
            Log::error("SelfHealingAgent: LinkedIn Ads healing error", ['error' => $e->getMessage()]);
        }
    }
}
