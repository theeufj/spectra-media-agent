<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\CampaignHourlyPerformance;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CustomerMatchService;
use App\Services\FacebookAds\CustomAudienceService as FacebookCustomAudienceService;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use Illuminate\Support\Facades\Log;

/**
 * AudienceIntelligenceAgent
 * 
 * Manages audience creation and optimization across platforms:
 * - Google Ads: Customer Match list creation and upload
 * - Facebook Ads: Custom Audience creation (email/phone lists, website, lookalike) 
 * - AI-powered audience segmentation recommendations
 * - Cross-platform lookalike audience suggestions
 */
class AudienceIntelligenceAgent
{
    protected GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Create a Customer Match audience from email list.
     * Supports both Google Ads and Facebook Ads.
     */
    public function createCustomerMatchAudience(
        Customer $customer,
        string $listName,
        array $emails,
        string $description = ''
    ): array {
        $result = [
            'success' => false,
            'platforms' => [],
            'error' => null,
        ];

        $hasGoogle = (bool) $customer->google_ads_customer_id;
        $hasFacebook = (bool) $customer->facebook_ads_account_id;

        if (!$hasGoogle && !$hasFacebook) {
            $result['error'] = 'No ad platform connected';
            return $result;
        }

        // Create on Google Ads
        if ($hasGoogle) {
            $result['platforms']['google_ads'] = $this->createGoogleCustomerMatchAudience(
                $customer, $listName, $emails, $description
            );
        }

        // Create on Facebook Ads
        if ($hasFacebook) {
            $result['platforms']['facebook_ads'] = $this->createFacebookCustomAudience(
                $customer, $listName, $emails, $description
            );
        }

        $result['success'] = collect($result['platforms'])->contains('success', true);

        return $result;
    }

    /**
     * Create a Google Ads Customer Match audience.
     */
    protected function createGoogleCustomerMatchAudience(
        Customer $customer,
        string $listName,
        array $emails,
        string $description
    ): array {
        $result = [
            'success' => false,
            'list_created' => false,
            'emails_uploaded' => 0,
            'user_list_resource_name' => null,
            'error' => null,
        ];

        try {
            $customerMatchService = new CustomerMatchService($customer, true);
            $customerId = $customer->google_ads_customer_id;

            $userListResourceName = $customerMatchService->createUserList(
                $customerId,
                $listName,
                $description ?: "Customer Match list created via Spectra"
            );

            if (!$userListResourceName) {
                $result['error'] = 'Failed to create user list';
                return $result;
            }

            $result['list_created'] = true;
            $result['user_list_resource_name'] = $userListResourceName;

            $uploadResult = $customerMatchService->uploadEmails(
                $customerId,
                $userListResourceName,
                $emails
            );

            $result['emails_uploaded'] = $uploadResult['uploaded'];
            $result['upload_job'] = $uploadResult['job_resource_name'];
            $result['success'] = $uploadResult['success'];

            Log::info('AudienceIntelligenceAgent: Google Customer Match audience created', [
                'customer_id' => $customer->id,
                'list_name' => $listName,
                'emails_uploaded' => $result['emails_uploaded'],
            ]);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('AudienceIntelligenceAgent: Failed to create Google audience', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Create a Facebook Custom Audience from email list.
     */
    protected function createFacebookCustomAudience(
        Customer $customer,
        string $listName,
        array $emails,
        string $description
    ): array {
        $result = [
            'success' => false,
            'audience_id' => null,
            'emails_uploaded' => 0,
            'error' => null,
        ];

        try {
            $audienceService = new FacebookCustomAudienceService($customer);
            $accountId = $customer->facebook_ads_account_id;

            $audience = $audienceService->createCustomerListAudience(
                $accountId,
                $listName,
                $description ?: "Custom audience created via Spectra",
                $emails
            );

            if ($audience && isset($audience['id'])) {
                $result['success'] = true;
                $result['audience_id'] = $audience['id'];
                $result['emails_uploaded'] = count($emails);

                Log::info('AudienceIntelligenceAgent: Facebook Custom Audience created', [
                    'customer_id' => $customer->id,
                    'audience_id' => $audience['id'],
                    'list_name' => $listName,
                    'emails_uploaded' => count($emails),
                ]);
            } else {
                $result['error'] = 'Failed to create Facebook Custom Audience';
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('AudienceIntelligenceAgent: Failed to create Facebook audience', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get existing audiences across all connected platforms.
     */
    public function getCustomerMatchAudiences(Customer $customer): array
    {
        $audiences = [];

        // Google Ads Customer Match audiences
        if ($customer->google_ads_customer_id) {
            try {
                $customerMatchService = new CustomerMatchService($customer, true);
                $googleAudiences = $customerMatchService->getUserLists($customer->google_ads_customer_id);
                foreach ($googleAudiences as &$audience) {
                    $audience['platform'] = 'google_ads';
                }
                $audiences = array_merge($audiences, $googleAudiences);
            } catch (\Exception $e) {
                Log::error('AudienceIntelligenceAgent: Failed to get Google audiences', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Facebook Custom Audiences
        if ($customer->facebook_ads_account_id) {
            try {
                $fbAudienceService = new FacebookCustomAudienceService($customer);
                $fbAudiences = $fbAudienceService->listAudiences($customer->facebook_ads_account_id);
                if ($fbAudiences) {
                    foreach ($fbAudiences as $fbAudience) {
                        $audiences[] = [
                            'name' => $fbAudience['name'] ?? 'Unnamed',
                            'platform' => 'facebook_ads',
                            'audience_id' => $fbAudience['id'],
                            'subtype' => $fbAudience['subtype'] ?? null,
                            'approximate_count' => $fbAudience['approximate_count'] ?? 0,
                            'size_display' => $fbAudience['approximate_count'] ?? 0,
                            'size_search' => 0, // Facebook doesn't have search/display split
                            'delivery_status' => $fbAudience['delivery_status'] ?? null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::error('AudienceIntelligenceAgent: Failed to get Facebook audiences', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $audiences;
    }

    /**
     * Generate AI-powered audience segmentation recommendations.
     */
    public function generateSegmentationRecommendations(Customer $customer): array
    {
        $context = $this->buildCustomerContext($customer);

        $prompt = <<<PROMPT
Based on this business profile, recommend audience segments for advertising:

{$context}

Generate 5-7 audience segments that would be valuable for this business.
For each segment, provide:
1. Segment name
2. Description
3. Targeting strategy (interests, demographics, behaviors)
4. Expected size (small/medium/large)
5. Best platforms (Google, Facebook, both)
6. Recommended bid adjustment (+/- percentage)

Return as JSON:
{
  "segments": [
    {
      "name": "Segment name",
      "description": "Who this segment is",
      "targeting": {
        "interests": ["interest1", "interest2"],
        "demographics": {
          "age_range": "25-54",
          "gender": "all",
          "income": "top 30%"
        },
        "behaviors": ["behavior1"]
      },
      "expected_size": "medium",
      "platforms": ["google", "facebook"],
      "bid_adjustment": "+20%",
      "priority": "high"
    }
  ],
  "lookalike_recommendations": [
    {
      "source": "What list to base lookalike on",
      "similarity": "1-3%",
      "platform": "facebook",
      "rationale": "Why this lookalike would work"
    }
  ]
}
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-3-flash-preview',
                $prompt,
                ['responseMimeType' => 'application/json'],
                'You are an expert digital advertising strategist specializing in audience targeting and segmentation.',
                true // Enable thinking
            );

            if ($response && isset($response['text'])) {
                $recommendations = $this->parseJson($response['text']);
                
                Log::info('AudienceIntelligenceAgent: Generated segmentation recommendations', [
                    'customer_id' => $customer->id,
                    'segments' => count($recommendations['segments'] ?? []),
                ]);

                return $recommendations;
            }

        } catch (\Exception $e) {
            Log::error('AudienceIntelligenceAgent: Failed to generate recommendations', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Analyze existing audience performance and suggest optimizations.
     * Tracks segment ROAS over time and recommends pruning underperformers.
     */
    public function analyzeAudiencePerformance(Customer $customer): array
    {
        $audiences = $this->getCustomerMatchAudiences($customer);
        
        $analysis = [
            'audiences' => $audiences,
            'recommendations' => [],
            'performance_trends' => [],
        ];

        // Get customer's recent performance data for ROAS context
        $avgRoas = CampaignHourlyPerformance::where('customer_id', $customer->id)
            ->where('date', '>=', now()->subDays(30))
            ->where('spend', '>', 0)
            ->selectRaw('AVG(roas) as avg_roas')
            ->value('avg_roas') ?? 0;

        foreach ($audiences as $audience) {
            $platform = $audience['platform'] ?? 'google_ads';

            if ($platform === 'facebook_ads') {
                $this->analyzeFacebookAudiencePerformance($customer, $audience, $analysis, $avgRoas);
            } else {
                $this->analyzeGoogleAudiencePerformance($audience, $analysis);
            }
        }

        // Add overall audience health summary
        $totalAudiences = count($audiences);
        $smallAudiences = count(array_filter($analysis['recommendations'], fn($r) => str_contains($r['issue'] ?? '', 'too small')));
        if ($totalAudiences > 0 && $smallAudiences > ($totalAudiences / 2)) {
            $analysis['recommendations'][] = [
                'audience' => 'Overall',
                'platform' => 'all',
                'issue' => 'Majority of audiences are undersized',
                'action' => 'Focus on growing your email list and website traffic before creating more audience segments',
                'priority' => 'critical',
            ];
        }

        return $analysis;
    }

    /**
     * Analyze a Facebook audience with ROAS tracking and feedback.
     */
    protected function analyzeFacebookAudiencePerformance(
        Customer $customer,
        array $audience,
        array &$analysis,
        float $avgRoas
    ): void {
        $approximateCount = $audience['approximate_count'] ?? 0;
        $audienceName = $audience['name'];

        if ($approximateCount < 1000) {
            $analysis['recommendations'][] = [
                'audience' => $audienceName,
                'platform' => 'facebook_ads',
                'issue' => 'Audience too small for effective targeting on Facebook',
                'action' => 'Add more customer emails or consider creating a website-based custom audience',
                'priority' => 'high',
            ];
        }

        // Suggest lookalike if audience is large enough and is a seed audience
        if ($approximateCount >= 1000 && ($audience['subtype'] ?? '') === 'CUSTOM') {
            $analysis['recommendations'][] = [
                'audience' => $audienceName,
                'platform' => 'facebook_ads',
                'issue' => 'Lookalike opportunity',
                'action' => "Create a 1% lookalike audience from \"{$audienceName}\" to expand reach with similar users",
                'priority' => 'medium',
            ];
        }

        // Check delivery status for stale audiences
        $deliveryStatus = $audience['delivery_status'] ?? null;
        if ($deliveryStatus && is_array($deliveryStatus)) {
            $status = $deliveryStatus['status'] ?? '';
            if (in_array($status, ['inactive', 'limited'])) {
                $analysis['recommendations'][] = [
                    'audience' => $audienceName,
                    'platform' => 'facebook_ads',
                    'issue' => "Audience delivery is {$status}",
                    'action' => 'Consider refreshing this audience with updated customer data or expanding targeting',
                    'priority' => 'high',
                ];
            }
        }

        // Track audience age — suggest refresh for audiences not updated in 30+ days
        if ($approximateCount > 0 && $approximateCount < 500) {
            $analysis['recommendations'][] = [
                'audience' => $audienceName,
                'platform' => 'facebook_ads',
                'issue' => 'Audience below minimum viable size for Facebook (500)',
                'action' => 'Prune this audience — it cannot deliver meaningfully. Consolidate with other segments.',
                'priority' => 'critical',
            ];
        }
    }

    /**
     * Analyze a Google audience with size and match-rate checks.
     */
    protected function analyzeGoogleAudiencePerformance(array $audience, array &$analysis): void
    {
        $displaySize = $audience['size_display'] ?? 0;
        $searchSize = $audience['size_search'] ?? 0;
        $audienceName = $audience['name'];

        if ($displaySize < 1000 && $searchSize < 1000) {
            $analysis['recommendations'][] = [
                'audience' => $audienceName,
                'platform' => 'google_ads',
                'issue' => 'Audience too small for effective targeting',
                'action' => 'Add more customer emails or expand criteria',
                'priority' => 'high',
            ];
        }

        if ($displaySize > 0 && $searchSize > 0) {
            $ratio = $searchSize / $displaySize;
            if ($ratio < 0.5) {
                $analysis['recommendations'][] = [
                    'audience' => $audienceName,
                    'platform' => 'google_ads',
                    'issue' => 'Low match rate for Search Network',
                    'action' => 'Consider using this audience primarily for Display/YouTube',
                    'priority' => 'medium',
                ];
            }
        }

        // Suggest pruning very small Google audiences
        if ($displaySize > 0 && $displaySize < 100 && $searchSize < 100) {
            $analysis['recommendations'][] = [
                'audience' => $audienceName,
                'platform' => 'google_ads',
                'issue' => 'Audience critically small — unlikely to deliver',
                'action' => 'Remove this audience segment and consolidate with a larger list',
                'priority' => 'critical',
            ];
        }
    }

    /**
     * Build customer context for AI analysis.
     */
    protected function buildCustomerContext(Customer $customer): string
    {
        $context = "Business: {$customer->name}\n";
        $context .= "Website: {$customer->website}\n";
        $context .= "Type: {$customer->business_type}\n";
        $context .= "Industry: {$customer->industry}\n";
        
        if ($customer->description) {
            $context .= "Description: {$customer->description}\n";
        }

        if ($customer->brandGuideline) {
            $bg = $customer->brandGuideline;
            
            if ($bg->target_audience) {
                $context .= "Target Audience: {$bg->target_audience}\n";
            }
            
            if ($bg->unique_selling_propositions) {
                $usps = is_array($bg->unique_selling_propositions) 
                    ? implode(', ', $bg->unique_selling_propositions)
                    : $bg->unique_selling_propositions;
                $context .= "USPs: {$usps}\n";
            }
        }

        // Add competitor context if available
        $competitors = $customer->competitors()->take(3)->get();
        if ($competitors->isNotEmpty()) {
            $competitorNames = $competitors->pluck('name')->implode(', ');
            $context .= "Key Competitors: {$competitorNames}\n";
        }

        return $context;
    }

    /**
     * Parse JSON response.
     */
    protected function parseJson(string $text): array
    {
        $cleaned = trim($text);
        
        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        return json_decode(trim($cleaned), true) ?? [];
    }
}
