<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Prompts\AdCompliancePrompt;
use Illuminate\Support\Facades\Log;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;

class SelfHealingAgent
{
    protected GeminiService $gemini;
    protected array $config;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
        $this->config = config('budget_rules.self_healing', []);
    }

    /**
     * Run self-healing checks on a campaign.
     *
     * @param Campaign $campaign
     * @return array Results of healing actions
     */
    public function heal(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'actions_taken' => [],
            'errors' => [],
        ];

        if (!$campaign->google_ads_campaign_id || !$campaign->customer) {
            return $results;
        }

        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        // 1. Check for disapproved ads
        $this->healDisapprovedAds($customer, $customerId, $campaignResourceName, $results);

        // 2. Check for budget exhaustion
        $this->checkBudgetHealth($customer, $campaign, $customerId, $campaignResourceName, $results);

        return $results;
    }

    /**
     * Find and fix disapproved ads.
     */
    protected function healDisapprovedAds(Customer $customer, string $customerId, string $campaignResourceName, array &$results): void
    {
        try {
            $getAdStatus = new GetAdStatus($customer, true);
            $ads = ($getAdStatus)($customerId, $campaignResourceName);

            foreach ($ads as $ad) {
                // Check if ad is disapproved
                if ($ad['approval_status'] === PolicyApprovalStatus::DISAPPROVED) {
                    $this->handleDisapprovedAd($customer, $customerId, $ad, $results);
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to check ad status: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to check ad status", [
                'campaign' => $campaignResourceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a disapproved ad by generating a compliant alternative.
     */
    protected function handleDisapprovedAd(Customer $customer, string $customerId, array $ad, array &$results): void
    {
        $maxAttempts = $this->config['max_fix_attempts'] ?? 3;
        
        // Get the policy violation reason
        $policyTopics = $ad['policy_topics'] ?? [];
        $violationReason = !empty($policyTopics) 
            ? implode(', ', array_column($policyTopics, 'topic'))
            : 'Unknown policy violation';

        Log::info("SelfHealingAgent: Attempting to fix disapproved ad", [
            'ad_resource_name' => $ad['resource_name'],
            'violation' => $violationReason,
        ]);

        // Generate compliant ad copy using AI
        $prompt = AdCompliancePrompt::generate([
            'headlines' => $ad['headlines'],
            'descriptions' => $ad['descriptions'],
        ], $violationReason);

        try {
            $response = $this->gemini->generateContent($prompt);
            
            // Extract JSON from response
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $newAdData = json_decode($matches[0], true);
                
                if ($newAdData && isset($newAdData['headlines'], $newAdData['descriptions'])) {
                    // Create new ad with compliant copy
                    $createAdService = new CreateResponsiveSearchAd($customer, true);
                    
                    $newAdResourceName = ($createAdService)(
                        $customerId,
                        $ad['ad_group_resource_name'],
                        $newAdData['headlines'],
                        $newAdData['descriptions'],
                        $ad['headlines'][0] ?? 'Visit Us Today' // Final URL path
                    );

                    if ($newAdResourceName) {
                        $results['actions_taken'][] = [
                            'type' => 'ad_resubmitted',
                            'original_ad' => $ad['resource_name'],
                            'new_ad' => $newAdResourceName,
                            'reason' => $violationReason,
                            'changes' => $newAdData['changes_made'] ?? 'Ad copy modified for compliance',
                        ];

                        Log::info("SelfHealingAgent: Created compliant ad", [
                            'original' => $ad['resource_name'],
                            'new' => $newAdResourceName,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to create compliant ad: " . $e->getMessage();
            Log::error("SelfHealingAgent: Failed to create compliant ad", [
                'ad' => $ad['resource_name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check and adjust budget if needed.
     */
    protected function checkBudgetHealth(Customer $customer, Campaign $campaign, string $customerId, string $campaignResourceName, array &$results): void
    {
        try {
            $getPerformance = new GetCampaignPerformance($customer, true);
            $metrics = ($getPerformance)($customerId, $campaignResourceName, 'TODAY');

            if (!$metrics) {
                return;
            }

            $dailyBudget = $campaign->daily_budget ?? 0;
            $spentToday = ($metrics['cost_micros'] ?? 0) / 1000000;
            $hourOfDay = (int) now()->format('H');

            // If we've spent more than 80% of budget before noon, we might be pacing too fast
            if ($hourOfDay < 12 && $spentToday > ($dailyBudget * 0.8)) {
                Log::warning("SelfHealingAgent: Campaign is pacing fast", [
                    'campaign_id' => $campaign->id,
                    'spent' => $spentToday,
                    'budget' => $dailyBudget,
                    'hour' => $hourOfDay,
                ]);

                $results['actions_taken'][] = [
                    'type' => 'budget_warning',
                    'message' => "Campaign spent $spentToday of $dailyBudget budget by hour $hourOfDay",
                ];
            }

            // If campaign has no impressions today but should be active, flag it
            if ($metrics['impressions'] === 0 && $campaign->platform_status === 'ENABLED') {
                $results['actions_taken'][] = [
                    'type' => 'no_impressions_warning',
                    'message' => "Campaign has 0 impressions today despite being enabled",
                ];
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Failed to check budget health: " . $e->getMessage();
        }
    }
}
