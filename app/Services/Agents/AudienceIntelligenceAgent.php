<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CustomerMatchService;
use Illuminate\Support\Facades\Log;

/**
 * AudienceIntelligenceAgent
 * 
 * Manages audience creation and optimization:
 * - Customer Match list creation and upload
 * - Audience segmentation recommendations
 * - Lookalike audience suggestions
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
     */
    public function createCustomerMatchAudience(
        Customer $customer,
        string $listName,
        array $emails,
        string $description = ''
    ): array {
        $result = [
            'success' => false,
            'list_created' => false,
            'emails_uploaded' => 0,
            'user_list_resource_name' => null,
            'error' => null,
        ];

        if (!$customer->google_ads_customer_id) {
            $result['error'] = 'Google Ads not connected';
            return $result;
        }

        try {
            $customerMatchService = new CustomerMatchService($customer, true);
            $customerId = $customer->google_ads_customer_id;

            // Step 1: Create the user list
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

            // Step 2: Upload emails
            $uploadResult = $customerMatchService->uploadEmails(
                $customerId,
                $userListResourceName,
                $emails
            );

            $result['emails_uploaded'] = $uploadResult['uploaded'];
            $result['upload_job'] = $uploadResult['job_resource_name'];
            $result['success'] = $uploadResult['success'];

            Log::info('AudienceIntelligenceAgent: Customer Match audience created', [
                'customer_id' => $customer->id,
                'list_name' => $listName,
                'emails_uploaded' => $result['emails_uploaded'],
            ]);

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('AudienceIntelligenceAgent: Failed to create audience', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get existing Customer Match audiences.
     */
    public function getCustomerMatchAudiences(Customer $customer): array
    {
        if (!$customer->google_ads_customer_id) {
            return [];
        }

        try {
            $customerMatchService = new CustomerMatchService($customer, true);
            return $customerMatchService->getUserLists($customer->google_ads_customer_id);
        } catch (\Exception $e) {
            Log::error('AudienceIntelligenceAgent: Failed to get audiences', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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
                'gemini-2.5-pro',
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
     */
    public function analyzeAudiencePerformance(Customer $customer): array
    {
        $audiences = $this->getCustomerMatchAudiences($customer);
        
        $analysis = [
            'audiences' => $audiences,
            'recommendations' => [],
        ];

        foreach ($audiences as $audience) {
            // Check if audience is too small
            $displaySize = $audience['size_display'] ?? 0;
            $searchSize = $audience['size_search'] ?? 0;

            if ($displaySize < 1000 && $searchSize < 1000) {
                $analysis['recommendations'][] = [
                    'audience' => $audience['name'],
                    'issue' => 'Audience too small for effective targeting',
                    'action' => 'Add more customer emails or expand criteria',
                    'priority' => 'high',
                ];
            }

            // Check for search vs display discrepancy
            if ($displaySize > 0 && $searchSize > 0) {
                $ratio = $searchSize / $displaySize;
                if ($ratio < 0.5) {
                    $analysis['recommendations'][] = [
                        'audience' => $audience['name'],
                        'issue' => 'Low match rate for Search Network',
                        'action' => 'Consider using this audience primarily for Display/YouTube',
                        'priority' => 'medium',
                    ];
                }
            }
        }

        return $analysis;
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
