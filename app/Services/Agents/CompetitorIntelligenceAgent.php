<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAuctionInsights;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
                $results['auction_trend_actions'] = $this->analyzeAuctionTrends($customer, $results['auction_insights']);
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

            $ownIsSum   = 0;
            $ownIsCount = 0;

            foreach ($allInsights as $campaignInsights) {
                $results['campaigns_analyzed']++;

                // Track own impression share for trend analysis
                if (!empty($campaignInsights['our_metrics']['impression_share'])) {
                    $ownIsSum += $campaignInsights['our_metrics']['impression_share'];
                    $ownIsCount++;
                }

                // Extract competitor domains and match with our database
                foreach ($campaignInsights['competitors'] ?? [] as $competitorData) {
                    $domain = $competitorData['domain'];
                    
                    // Update or create competitor record with auction data
                    $competitor = $customer->competitors()
                        ->where('domain', 'LIKE', "%{$domain}%")
                        ->first();

                    if ($competitor) {
                        // Record WoW impression_share delta before overwriting
                        $prevIs  = (float) ($competitor->impression_share ?? 0);
                        $newIs   = (float) ($competitorData['impression_share'] ?? 0);
                        $trends  = $competitor->auction_trends ?? [];
                        $trends[] = [
                            'date'             => now()->toDateString(),
                            'impression_share' => $newIs,
                            'delta'            => round($newIs - $prevIs, 2),
                        ];
                        // Keep only last 8 snapshots
                        if (count($trends) > 8) {
                            $trends = array_slice($trends, -8);
                        }

                        $competitor->update([
                            'auction_insights' => $competitorData,
                            'auction_trends'   => $trends,
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

            if ($ownIsCount > 0) {
                $avgOwnIs = $ownIsSum / $ownIsCount;
                $this->recordOwnImpressionShare($customer, round($avgOwnIs, 2));
                $results['own_impression_share'] = round($avgOwnIs, 2);
            }

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
     * Analyze WoW auction insight trends and surface bid/budget recommendations.
     *
     * - Competitor IS +15% WoW → push BIDDING recommendation into CampaignOptimizationAgent cache
     * - Own IS dropped >10% (non-budget-limited) → push BIDDING escalation recommendation
     */
    protected function analyzeAuctionTrends(Customer $customer, array $insightsResult): array
    {
        $actions = [];

        $competitors = $customer->competitors()
            ->whereNotNull('auction_trends')
            ->get();

        foreach ($competitors as $competitor) {
            $trends = $competitor->auction_trends ?? [];
            if (count($trends) < 2) {
                continue;
            }

            $latest = end($trends);
            $prior  = $trends[count($trends) - 2];

            $delta = ($latest['impression_share'] ?? 0) - ($prior['impression_share'] ?? 0);

            if ($delta >= 15.0) {
                $this->pushBidRecommendation($customer, $competitor, $delta);
                $actions[] = [
                    'type'       => 'competitor_is_surge',
                    'competitor' => $competitor->domain,
                    'delta'      => $delta,
                ];
            }
        }

        // Own IS drop is detected from rolling cache populated during fetchAuctionInsights
        $this->checkOwnImpressionShareDrop($customer, $actions);

        return $actions;
    }

    private function pushBidRecommendation(Customer $customer, Competitor $competitor, float $delta): void
    {
        $cacheKey = "competitor_is_surge:{$customer->id}:{$competitor->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addDays(7));

        // Inject into each active campaign's optimization cache as an additional BIDDING recommendation
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->whereNotNull('google_ads_campaign_id')
            ->get();

        foreach ($campaigns as $campaign) {
            $existing = Cache::get("optimization:campaign:{$campaign->id}", []);
            $existing['competitor_bid_alerts'][] = [
                'priority'        => 'HIGH',
                'category'        => 'BIDDING',
                'recommendation'  => "Competitor {$competitor->domain} grew impression share by {$delta}% WoW. Review bids on overlapping keywords and consider raising target IS or tCPA to defend position.",
                'source'          => 'CompetitorIntelligenceAgent',
                'competitor'      => $competitor->domain,
                'is_delta'        => $delta,
                'recorded_at'     => now()->toIso8601String(),
            ];
            Cache::put("optimization:campaign:{$campaign->id}", $existing, now()->addHours(12));
        }

        Log::info("CompetitorIntelligenceAgent: Competitor IS surge — {$competitor->domain} +{$delta}%", [
            'customer_id' => $customer->id,
        ]);
    }

    private function checkOwnImpressionShareDrop(Customer $customer, array &$actions): void
    {
        // We store the customer's own IS in auction_insights on each competitor marked as 'you'.
        // Instead, we track it via a dedicated cache key that persists week-over-week.
        $ownIsHistory = Cache::get("own_impression_share:{$customer->id}", []);

        if (count($ownIsHistory) < 2) {
            return;
        }

        $latest = end($ownIsHistory);
        $prior  = $ownIsHistory[count($ownIsHistory) - 2];

        $latestIs = $latest['impression_share'] ?? 0;
        $priorIs  = $prior['impression_share']  ?? 0;

        if ($priorIs <= 0) {
            return;
        }

        $dropPct = (($priorIs - $latestIs) / $priorIs) * 100;

        if ($dropPct < 10.0) {
            return;
        }

        // Only escalate if budget is not the limiting factor (check budget pacing)
        $budgetLimited = $customer->campaigns()
            ->where('status', 'active')
            ->whereNotNull('google_ads_campaign_id')
            ->where(function ($q) {
                $q->whereRaw('daily_budget_utilization > 0.95'); // proxy: campaigns nearly exhausting budget
            })
            ->exists();

        if ($budgetLimited) {
            return;
        }

        $actions[] = [
            'type'     => 'own_is_drop',
            'drop_pct' => round($dropPct, 1),
            'prior_is' => $priorIs,
            'latest_is' => $latestIs,
        ];

        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->whereNotNull('google_ads_campaign_id')
            ->get();

        foreach ($campaigns as $campaign) {
            $existing = Cache::get("optimization:campaign:{$campaign->id}", []);
            $existing['competitor_bid_alerts'][] = [
                'priority'       => 'HIGH',
                'category'       => 'BIDDING',
                'recommendation' => "Own impression share dropped {$dropPct}% WoW (from {$priorIs}% to {$latestIs}%) with budget not limiting. Review bid strategy and consider increasing target IS or tCPA.",
                'source'         => 'CompetitorIntelligenceAgent',
                'own_is_drop'    => $dropPct,
                'recorded_at'    => now()->toIso8601String(),
            ];
            Cache::put("optimization:campaign:{$campaign->id}", $existing, now()->addHours(12));
        }

        Log::warning("CompetitorIntelligenceAgent: Own IS dropped {$dropPct}% WoW (budget not limiting)", [
            'customer_id' => $customer->id,
            'prior_is'    => $priorIs,
            'latest_is'   => $latestIs,
        ]);
    }

    /**
     * Record current own impression share snapshot (called after fetching auction insights).
     * Stores in a rolling cache so trend analysis can compare WoW.
     */
    public function recordOwnImpressionShare(Customer $customer, float $impressionShare): void
    {
        $history   = Cache::get("own_impression_share:{$customer->id}", []);
        $history[] = ['date' => now()->toDateString(), 'impression_share' => $impressionShare];
        if (count($history) > 8) {
            $history = array_slice($history, -8);
        }
        Cache::put("own_impression_share:{$customer->id}", $history, now()->addDays(60));
    }

    /**
     * Generate an AI-powered counter-strategy based on all competitive intelligence.
     */
    public function generateCounterStrategy(Customer $customer): array
    {
        // Gather all competitor intelligence
        $competitors = $this->getAnalyzedCompetitors($customer);

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
            'gemini-3-flash-preview',
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
        $this->persistStrategy($customer, $strategy);

        return [
            'status' => 'success',
            'strategy' => $strategy,
        ];
    }

    /**
     * Get analyzed competitors for counter-strategy generation.
     */
    protected function getAnalyzedCompetitors(Customer $customer)
    {
        return $customer->competitors()
            ->whereNotNull('messaging_analysis')
            ->orderByDesc('impression_share')
            ->take(5)
            ->get();
    }

    /**
     * Persist the counter-strategy to the customer record.
     */
    protected function persistStrategy(Customer $customer, array $strategy): void
    {
        $customer->update([
            'competitive_strategy' => $strategy,
            'competitive_strategy_updated_at' => now(),
        ]);
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
