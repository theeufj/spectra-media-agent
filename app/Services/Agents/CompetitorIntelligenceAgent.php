<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAuctionInsights;
use Illuminate\Support\Facades\Log;

/**
 * CompetitorIntelligenceAgent
 * 
 * The main orchestrator for competitive intelligence. This agent:
 * 1. Discovers competitors via Google Search
 * 2. Analyzes competitor websites
 * 3. Fetches Auction Insights from Google Ads
 * 4. Generates counter-strategies for ad campaigns
 */
class CompetitorIntelligenceAgent
{
    protected GeminiService $gemini;
    protected CompetitorDiscoveryAgent $discoveryAgent;
    protected CompetitorAnalysisAgent $analysisAgent;

    public function __construct(
        GeminiService $gemini,
        CompetitorDiscoveryAgent $discoveryAgent,
        CompetitorAnalysisAgent $analysisAgent
    ) {
        $this->gemini = $gemini;
        $this->discoveryAgent = $discoveryAgent;
        $this->analysisAgent = $analysisAgent;
    }

    /**
     * Run full competitive intelligence analysis for a customer.
     */
    public function runFullAnalysis(Customer $customer): array
    {
        $results = [
            'customer_id' => $customer->id,
            'discovery' => null,
            'analysis' => null,
            'auction_insights' => null,
            'counter_strategy' => null,
            'errors' => [],
        ];

        Log::info('CompetitorIntelligenceAgent: Starting full analysis', [
            'customer_id' => $customer->id,
        ]);

        // Step 1: Discover competitors
        try {
            $results['discovery'] = $this->discoveryAgent->discover($customer);
        } catch (\Exception $e) {
            $results['errors'][] = 'Discovery failed: ' . $e->getMessage();
        }

        // Step 2: Analyze all competitors
        try {
            $results['analysis'] = $this->analysisAgent->analyzeAll($customer);
        } catch (\Exception $e) {
            $results['errors'][] = 'Analysis failed: ' . $e->getMessage();
        }

        // Step 3: Fetch Auction Insights (if Google Ads connected)
        if ($customer->google_ads_customer_id) {
            try {
                $results['auction_insights'] = $this->fetchAuctionInsights($customer);
            } catch (\Exception $e) {
                $results['errors'][] = 'Auction insights failed: ' . $e->getMessage();
            }
        }

        // Step 4: Generate counter-strategy
        try {
            $results['counter_strategy'] = $this->generateCounterStrategy($customer);
        } catch (\Exception $e) {
            $results['errors'][] = 'Counter-strategy failed: ' . $e->getMessage();
        }

        Log::info('CompetitorIntelligenceAgent: Full analysis complete', [
            'customer_id' => $customer->id,
            'errors_count' => count($results['errors']),
        ]);

        return $results;
    }

    /**
     * Fetch Auction Insights for all campaigns.
     */
    protected function fetchAuctionInsights(Customer $customer): array
    {
        $results = [
            'campaigns_analyzed' => 0,
            'competitors_found' => [],
        ];

        try {
            $auctionInsightsService = new GetAuctionInsights($customer, true);
            $allInsights = $auctionInsightsService->getAllCampaigns($customer->google_ads_customer_id);

            foreach ($allInsights as $campaignInsights) {
                $results['campaigns_analyzed']++;
                
                // Extract competitor domains and match with our database
                foreach ($campaignInsights['competitors'] ?? [] as $competitorData) {
                    $domain = $competitorData['domain'];
                    
                    // Update or create competitor record with auction data
                    $competitor = $customer->competitors()
                        ->where('domain', 'LIKE', "%{$domain}%")
                        ->first();

                    if ($competitor) {
                        $competitor->update([
                            'auction_insights' => $competitorData,
                            'impression_share' => $competitorData['impression_share'] ?? null,
                            'overlap_rate' => $competitorData['overlap_rate'] ?? null,
                            'position_above_rate' => $competitorData['position_above_rate'] ?? null,
                        ]);
                    } else {
                        // New competitor discovered via auction insights
                        $customer->competitors()->create([
                            'domain' => $domain,
                            'url' => "https://{$domain}",
                            'name' => $domain,
                            'discovery_source' => 'auction_insights',
                            'auction_insights' => $competitorData,
                            'impression_share' => $competitorData['impression_share'] ?? null,
                            'overlap_rate' => $competitorData['overlap_rate'] ?? null,
                            'position_above_rate' => $competitorData['position_above_rate'] ?? null,
                        ]);
                    }

                    $results['competitors_found'][] = $domain;
                }
            }

            $results['competitors_found'] = array_unique($results['competitors_found']);

        } catch (\Exception $e) {
            Log::error('CompetitorIntelligenceAgent: Auction insights failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Generate an AI-powered counter-strategy based on all competitive intelligence.
     */
    public function generateCounterStrategy(Customer $customer): array
    {
        // Gather all competitor intelligence
        $competitors = $customer->competitors()
            ->whereNotNull('messaging_analysis')
            ->orderByDesc('impression_share')
            ->take(5)
            ->get();

        if ($competitors->isEmpty()) {
            return [
                'status' => 'no_data',
                'message' => 'No competitor analysis data available yet.',
            ];
        }

        // Build context for AI
        $competitorContext = $competitors->map(function ($competitor) {
            return [
                'name' => $competitor->name,
                'domain' => $competitor->domain,
                'impression_share' => $competitor->impression_share,
                'value_propositions' => $competitor->value_propositions,
                'keywords' => $competitor->keywords_detected,
                'messaging' => $competitor->messaging_analysis,
                'pricing' => $competitor->pricing_info,
            ];
        })->toArray();

        // Get our business context
        $ourContext = $this->getOurContext($customer);

        $prompt = $this->buildCounterStrategyPrompt($ourContext, $competitorContext);

        $response = $this->gemini->generateContent(
            'gemini-2.5-pro',
            $prompt,
            ['responseMimeType' => 'application/json'],
            'You are an expert advertising strategist specializing in competitive positioning and counter-messaging.',
            true  // Enable thinking
        );

        if (!$response || !isset($response['text'])) {
            return [
                'status' => 'error',
                'message' => 'Failed to generate counter-strategy',
            ];
        }

        $strategy = $this->parseJson($response['text']);

        // Store the strategy on the customer
        $customer->update([
            'competitive_strategy' => $strategy,
            'competitive_strategy_updated_at' => now(),
        ]);

        return [
            'status' => 'success',
            'strategy' => $strategy,
        ];
    }

    /**
     * Get our business context for strategy generation.
     */
    protected function getOurContext(Customer $customer): array
    {
        $context = [
            'name' => $customer->name,
            'website' => $customer->website,
            'business_type' => $customer->business_type,
            'description' => $customer->description,
        ];

        if ($customer->brandGuideline) {
            $context['usps'] = $customer->brandGuideline->unique_selling_propositions;
            $context['target_audience'] = $customer->brandGuideline->target_audience;
            $context['brand_voice'] = $customer->brandGuideline->brand_voice;
        }

        return $context;
    }

    /**
     * Build the counter-strategy prompt.
     */
    protected function buildCounterStrategyPrompt(array $ourContext, array $competitorContext): string
    {
        $ourJson = json_encode($ourContext, JSON_PRETTY_PRINT);
        $competitorJson = json_encode($competitorContext, JSON_PRETTY_PRINT);

        return <<<PROMPT
**COMPETITIVE COUNTER-STRATEGY REQUEST**

**Our Business:**
{$ourJson}

**Top Competitors Analysis:**
{$competitorJson}

---

**GENERATE A COMPREHENSIVE COUNTER-STRATEGY:**

Based on the competitive analysis above, create an actionable advertising strategy that:

1. **Differentiates** us from competitors
2. **Exploits gaps** in competitor messaging
3. **Targets keywords** competitors are missing
4. **Positions us** to win impression share

**RESPONSE FORMAT (JSON):**
{
  "executive_summary": "2-3 sentence strategy overview",
  
  "positioning_strategy": {
    "primary_angle": "Main differentiator to emphasize",
    "supporting_points": ["point 1", "point 2", "point 3"],
    "avoid_competing_on": ["areas where competitors are stronger"]
  },
  
  "messaging_recommendations": {
    "headline_themes": ["theme 1", "theme 2", "theme 3"],
    "key_messages": ["message 1", "message 2", "message 3"],
    "calls_to_action": ["CTA 1", "CTA 2"],
    "proof_points_to_emphasize": ["proof 1", "proof 2"]
  },
  
  "keyword_strategy": {
    "attack_keywords": ["keywords to target against specific competitors"],
    "defense_keywords": ["keywords to protect/maintain"],
    "opportunity_keywords": ["keywords competitors are missing"],
    "negative_keywords": ["keywords to avoid"]
  },
  
  "bidding_recommendations": {
    "aggressive_times": ["when to bid higher based on competitor patterns"],
    "budget_allocation": "How to allocate budget vs competitors",
    "target_impression_share": "Recommended impression share target"
  },
  
  "ad_copy_examples": [
    {
      "target_competitor": "competitor name",
      "headline": "Example headline",
      "description": "Example description",
      "strategy": "Why this works against them"
    }
  ],
  
  "quick_wins": ["immediate actions to take"],
  
  "long_term_plays": ["strategic moves for sustained advantage"]
}
PROMPT;
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
