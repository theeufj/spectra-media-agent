# Agent & Workflow Analysis - Improvement Recommendations

**Date:** November 26, 2025  
**Analyst:** GitHub Copilot  
**Status:** ✅ RECOMMENDATIONS IMPLEMENTED

---

## Executive Summary

The Spectra Media Agent system employs a sophisticated multi-agent architecture for automated advertising campaign management. This document analyzes each agent, its workflows, and associated prompts to identify potential improvements.

### ✅ Implemented Improvements (November 26, 2025)

| Improvement | File(s) Modified | Description |
|------------|------------------|-------------|
| **RetryableApiOperation Trait** | `Traits/RetryableApiOperation.php` (new) | Retry logic with exponential backoff, circuit breaker integration |
| **HealthCheckAgent** | `HealthCheckAgent.php` (new) | Proactive monitoring for API connectivity, tokens, delivery |
| **Enhanced SelfHealingAgent** | `SelfHealingAgent.php` | Added Facebook Ads support, improved structure |
| **Confidence Scoring** | `CampaignOptimizationAgent.php` | AI recommendations now include confidence scores |
| **Enhanced AdCompliancePrompt** | `AdCompliancePrompt.php` | Supports both Google and Facebook policy violations |
| **Enhanced OptimizationPrompt** | `OptimizationPrompt.php` | Historical comparison, data quality assessment |
| **Scheduled Health Checks** | `routes/console.php` | Health checks run every 6 hours |
| **RunHealthChecks Job** | `Jobs/RunHealthChecks.php` (new) | Scheduled job for proactive monitoring |

### Agent Inventory

| Agent | Purpose | Maturity | Priority for Improvement |
|-------|---------|----------|--------------------------|
| `GoogleAdsExecutionAgent` | Deploy campaigns to Google Ads | ✅ High | ✅ Implemented (retry logic) |
| `FacebookAdsExecutionAgent` | Deploy campaigns to Facebook/Meta | ✅ High | ✅ Implemented (retry logic) |
| `SelfHealingAgent` | Fix disapproved ads automatically | ✅ High | ✅ Implemented (FB support) |
| `SearchTermMiningAgent` | Keyword optimization from search terms | ✅ Medium | Medium |
| `BudgetIntelligenceAgent` | Dynamic budget adjustments | ✅ Medium | Low |
| `CompetitorIntelligenceAgent` | Competitive analysis orchestrator | ✅ Medium | Medium |
| `CreativeIntelligenceAgent` | Analyze creative performance | ✅ Medium | High |
| `CampaignOptimizationAgent` | Generate optimization recommendations | ✅ Medium | ✅ Implemented (confidence scoring) |
| `AudienceIntelligenceAgent` | Audience creation and segmentation | ⚠️ Basic | Medium |
| **`HealthCheckAgent`** | **Proactive health monitoring** | **✅ New** | **N/A** |

---

## 1. Execution Agents

### 1.1 GoogleAdsExecutionAgent

**Current State:** Mature implementation with comprehensive campaign type support.

**Strengths:**
- ✅ Supports Search, Display, Performance Max, and Video campaigns
- ✅ AI-powered execution planning with Google Search grounding
- ✅ Auto-creates sub-accounts when needed
- ✅ Comprehensive asset management (images, sitelinks, callouts)
- ✅ Location and audience targeting
- ✅ Conversion tracking setup

**Weaknesses & Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No retry logic for API failures | Medium | Implement exponential backoff with retry |
| Single ad group per campaign | Medium | Support multiple ad groups for better organization |
| No A/B testing structure | Medium | Create multiple ad variations automatically |
| Missing responsive search ad pinning | Low | Add headline/description pinning based on strategy |
| No Shopping campaign support | Medium | Add Shopping/PLA campaign type |

**Recommended Code Changes:**

```php
// Add retry logic to executePlan()
protected function executePlanWithRetry(ExecutionPlan $plan, ExecutionContext $context, int $maxRetries = 3): ExecutionResult
{
    $lastException = null;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            return $this->executePlan($plan, $context);
        } catch (\Exception $e) {
            $lastException = $e;
            if ($attempt < $maxRetries) {
                sleep(pow(2, $attempt)); // Exponential backoff
                Log::warning("GoogleAdsExecutionAgent: Retry attempt {$attempt}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    return ExecutionResult::failure([$lastException->getMessage()]);
}
```

---

### 1.2 FacebookAdsExecutionAgent

**Current State:** Good implementation with core campaign types.

**Strengths:**
- ✅ Supports single image, carousel, and video ads
- ✅ AI-powered execution planning
- ✅ Dynamic creative optimization eligibility check
- ✅ Advantage+ campaign detection
- ✅ Placement strategy with Instagram support

**Weaknesses & Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No Collection ad format | Medium | Add Collection/Instant Experience support |
| Missing Stories-specific creatives | Medium | Auto-generate 9:16 crops for Stories |
| No Catalog sales integration | High | Add product catalog campaign support |
| Limited A/B testing | Medium | Implement native Facebook A/B test API |
| No Advantage+ Shopping campaigns | High | Add Advantage+ Shopping support |
| Missing lead form integration | Medium | Support Lead Generation objective with forms |

**Recommended New Feature - Advantage+ Shopping:**

```php
protected function executeAdvantagePlusShoppingCampaign(
    string $accountId,
    Campaign $campaign,
    Strategy $strategy,
    ExecutionPlan $plan,
    ExecutionResult $result
): void {
    // Advantage+ Shopping campaigns require:
    // 1. Product catalog connection
    // 2. Pixel with purchase events
    // 3. Minimum $50/day budget
    
    $campaignData = [
        'name' => $campaign->name,
        'objective' => 'OUTCOME_SALES',
        'special_ad_categories' => [],
        'buying_type' => 'AUCTION',
        'campaign_optimization_type' => 'CATALOG_SALES',
    ];
    
    // Create with automated audience targeting
    // Facebook's AI handles targeting automatically
}
```

---

## 2. Maintenance Agents

### 2.1 SelfHealingAgent

**Current State:** Basic implementation for ad disapprovals only.

**Strengths:**
- ✅ Detects disapproved ads
- ✅ Uses AI to generate compliant alternatives
- ✅ Budget pacing monitoring

**Critical Improvements Needed:**

| Issue | Impact | Priority |
|-------|--------|----------|
| Only handles disapproved ads | High | **Critical** |
| No Facebook support | High | **Critical** |
| No auto-pause for policy risks | Medium | High |
| Limited error types handled | Medium | High |
| No notification system | Medium | Medium |

**Expanded SelfHealingAgent Proposal:**

```php
class SelfHealingAgent
{
    /**
     * Comprehensive healing that handles multiple failure types
     */
    public function heal(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'actions_taken' => [],
            'errors' => [],
        ];

        // 1. Check for disapproved ads (existing)
        $this->healDisapprovedAds(...);

        // 2. NEW: Check for limited/disapproved campaigns
        $this->healCampaignPolicyIssues($campaign, $results);

        // 3. NEW: Check for billing issues
        $this->healBillingIssues($campaign, $results);

        // 4. NEW: Check for landing page issues
        $this->healLandingPageIssues($campaign, $results);

        // 5. NEW: Check for conversion tracking issues
        $this->healConversionTrackingIssues($campaign, $results);

        // 6. Budget health (existing)
        $this->checkBudgetHealth(...);

        // 7. NEW: Send notifications for critical issues
        $this->sendAlertNotifications($campaign, $results);

        return $results;
    }

    /**
     * NEW: Handle campaign-level policy issues
     */
    protected function healCampaignPolicyIssues(Campaign $campaign, array &$results): void
    {
        // Check for:
        // - Sensitive category violations
        // - Restricted content
        // - Geographic restrictions
        // - Age-gated content requirements
    }

    /**
     * NEW: Heal landing page issues (404, slow load, policy)
     */
    protected function healLandingPageIssues(Campaign $campaign, array &$results): void
    {
        // Check landing page:
        // - HTTP status code
        // - Load time
        // - Mobile-friendliness
        // - Malware/phishing detection
        // Auto-pause if landing page is down
    }
}
```

---

### 2.2 SearchTermMiningAgent

**Current State:** Functional but limited in intelligence.

**Strengths:**
- ✅ Automated keyword promotion
- ✅ Negative keyword addition
- ✅ Configurable thresholds

**Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No AI-powered relevance scoring | High | Add semantic analysis for keyword relevance |
| Missing close variant handling | Medium | Group similar search terms together |
| No competitor term detection | Medium | Identify competitor brand terms |
| Fixed thresholds | Medium | Use AI to determine optimal thresholds per campaign |
| No cross-campaign learning | Medium | Share negative keywords across campaigns |

**AI-Enhanced Search Term Mining:**

```php
protected function evaluateSearchTermWithAI(
    Customer $customer,
    string $searchTerm,
    array $termMetrics,
    Campaign $campaign
): array {
    $prompt = <<<PROMPT
Analyze this search term for a {$campaign->industry} business:

Search Term: "{$searchTerm}"
Impressions: {$termMetrics['impressions']}
Clicks: {$termMetrics['clicks']}
Cost: \${$termMetrics['cost']}
Conversions: {$termMetrics['conversions']}

Business: {$customer->name}
Landing Page: {$campaign->landing_page_url}

Determine:
1. Relevance score (0-100): Is this term relevant to the business?
2. Intent score (0-100): Does this term indicate purchase intent?
3. Recommendation: "add_keyword", "add_negative", or "monitor"
4. Match type if adding: "EXACT", "PHRASE", or "BROAD"
5. Reasoning: Brief explanation

Return as JSON.
PROMPT;

    return $this->gemini->generateContent($prompt);
}
```

---

### 2.3 BudgetIntelligenceAgent

**Current State:** Rule-based budget adjustments.

**Strengths:**
- ✅ Time-of-day multipliers
- ✅ Day-of-week multipliers
- ✅ Seasonal multipliers (Black Friday, Cyber Monday)

**Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No performance-based adjustments | High | Adjust budget based on ROAS/CPA |
| No weather integration | Low | Pause outdoor/seasonal campaigns in bad weather |
| Static multipliers | Medium | Learn optimal multipliers from historical data |
| No Facebook support | High | Add Facebook budget management |
| Missing portfolio budget optimization | High | Optimize across multiple campaigns |

**Performance-Based Budget Intelligence:**

```php
/**
 * NEW: Adjust budget based on real-time performance
 */
protected function getPerformanceMultiplier(Campaign $campaign): float
{
    $metrics = $this->getRecentPerformance($campaign, 7); // Last 7 days
    
    if (!$metrics) return 1.0;
    
    $targetCPA = $campaign->target_cpa ?? 50;
    $actualCPA = $metrics['cost_per_conversion'] ?? 0;
    
    if ($actualCPA == 0) return 1.0; // No conversions yet
    
    // If CPA is 50% better than target, increase budget
    // If CPA is 50% worse than target, decrease budget
    $ratio = $targetCPA / $actualCPA;
    
    return match(true) {
        $ratio >= 2.0 => 1.5,    // CPA is half of target - scale up!
        $ratio >= 1.5 => 1.25,   // CPA is good - increase
        $ratio >= 1.0 => 1.0,    // CPA is at target - maintain
        $ratio >= 0.75 => 0.9,   // CPA is slightly high - reduce
        $ratio >= 0.5 => 0.75,   // CPA is high - reduce more
        default => 0.5,          // CPA is very high - significantly reduce
    };
}
```

---

## 3. Intelligence Agents

### 3.1 CompetitorIntelligenceAgent

**Current State:** Orchestrates competitor discovery and analysis.

**Strengths:**
- ✅ Multi-stage analysis pipeline
- ✅ Auction Insights integration
- ✅ AI-powered counter-strategy generation

**Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No ad library analysis | High | Scrape Facebook/Google Ad Library |
| Missing price monitoring | Medium | Track competitor pricing changes |
| No creative analysis | Medium | Analyze competitor ad creatives |
| Manual trigger only | Medium | Add scheduled automatic analysis |
| No alert system | Medium | Alert when competitor changes strategy |

**Competitive Ad Library Integration:**

```php
/**
 * NEW: Analyze competitor ads from Facebook Ad Library
 */
public function analyzeCompetitorAds(Customer $customer, Competitor $competitor): array
{
    // Use Facebook Ad Library API to fetch competitor ads
    $ads = $this->fetchAdLibraryAds($competitor->facebook_page_id);
    
    $prompt = <<<PROMPT
Analyze these competitor ads and extract insights:

Competitor: {$competitor->name}
Domain: {$competitor->domain}

Ads Found:
{json_encode($ads)}

Extract:
1. Common messaging themes
2. Visual style patterns
3. Call-to-action preferences
4. Seasonal/promotional patterns
5. Target audience signals
6. Recommended counter-messaging

Return as JSON.
PROMPT;

    return $this->gemini->generateContent($prompt);
}
```

---

### 3.2 CreativeIntelligenceAgent

**Current State:** Basic asset performance analysis.

**Strengths:**
- ✅ Asset categorization (winners/losers)
- ✅ AI-generated headline variations
- ✅ Performance thresholds

**Improvement Opportunities:**

| Issue | Impact | Recommendation |
|-------|--------|----------------|
| No image analysis | High | Use Vision AI to analyze image performance patterns |
| Missing video analysis | High | Analyze video frame-by-frame for engagement |
| No cross-campaign learning | Medium | Share winning elements across campaigns |
| Limited variation generation | Medium | Generate variations in brand voice |
| No creative fatigue detection | High | Detect when creatives need refresh |

**Vision-Powered Creative Analysis:**

```php
/**
 * NEW: Analyze image creatives using Vision AI
 */
public function analyzeImageCreatives(Campaign $campaign): array
{
    $images = $campaign->strategy->imageCollaterals;
    $results = [];
    
    foreach ($images as $image) {
        $imageUrl = Storage::disk('s3')->url($image->s3_path);
        
        $prompt = <<<PROMPT
Analyze this advertising image for effectiveness:

Image URL: {$imageUrl}
Performance: CTR {$image->ctr}%, Conversions: {$image->conversions}

Analyze:
1. Visual composition (rule of thirds, focus, balance)
2. Color psychology effectiveness
3. Brand visibility
4. Emotional appeal
5. Call-to-action clarity
6. Mobile visibility at small sizes

Provide recommendations for improvement.
PROMPT;

        $results[] = [
            'image_id' => $image->id,
            'analysis' => $this->gemini->generateContentWithImages($prompt, [$imageUrl]),
        ];
    }
    
    return $results;
}
```

---

### 3.3 CampaignOptimizationAgent

**Current State:** Basic optimization recommendations.

**Strengths:**
- ✅ Cross-platform support (Google/Facebook)
- ✅ AI-generated recommendations

**Critical Improvements Needed:**

| Issue | Impact | Priority |
|-------|--------|----------|
| Recommendations not auto-applied | High | **Critical** |
| No confidence scoring | High | High |
| Missing A/B test suggestions | Medium | Medium |
| No historical learning | Medium | Medium |
| Limited recommendation types | Medium | Medium |

**Auto-Apply Optimization with Confidence Scoring:**

```php
/**
 * Enhanced optimization with auto-apply capability
 */
public function analyzeAndOptimize(Campaign $campaign, bool $autoApply = false): array
{
    $recommendations = $this->analyze($campaign);
    
    foreach ($recommendations as &$rec) {
        // Add confidence score (0-100)
        $rec['confidence'] = $this->calculateConfidence($rec, $campaign);
        
        // Auto-apply if confidence > 80 and autoApply enabled
        if ($autoApply && $rec['confidence'] >= 80 && $rec['impact'] === 'HIGH') {
            $rec['applied'] = $this->applyRecommendation($campaign, $rec);
        }
    }
    
    return $recommendations;
}

protected function calculateConfidence(array $recommendation, Campaign $campaign): int
{
    // Factors:
    // - Historical success rate of this recommendation type
    // - Data volume (more data = higher confidence)
    // - Recommendation alignment with business goals
    // - Time since last similar change
    
    return min(100, $baseConfidence + $dataBonus + $historyBonus);
}
```

---

## 4. Prompt Analysis & Improvements

### 4.1 GoogleAdsExecutionPrompt

**Current State:** Comprehensive but verbose.

**Issues:**
| Problem | Impact | Fix |
|---------|--------|-----|
| Very long prompt (272 lines) | Medium | Split into modular sections |
| No few-shot examples | Medium | Add successful execution examples |
| Missing error handling guidance | Medium | Add common error recovery instructions |

**Improved Prompt Structure:**

```php
public static function generate(ExecutionContext $context): string
{
    return implode("\n\n", [
        self::getCampaignSection($context),
        self::getBudgetSection($context),
        self::getAssetSection($context),
        self::getStrategySection($context),
        self::getConstraintsSection(),
        self::getExamplesSection(), // NEW
        self::getErrorHandlingSection(), // NEW
        self::getOutputFormatSection(),
    ]);
}

protected static function getExamplesSection(): string
{
    return <<<EXAMPLES
# EXAMPLE SUCCESSFUL EXECUTIONS

## Example 1: Search Campaign with Limited Budget
Input: $500 total budget, 15 images, strong ad copy
Output: Search campaign with 3 ad groups, manual CPC bidding
Reasoning: Budget too low for Performance Max, images used for ad extensions

## Example 2: Performance Max with Full Assets
Input: $3000 budget, 20 images, 2 videos, multiple ad copies
Output: Performance Max campaign with comprehensive asset groups
Reasoning: Sufficient budget and assets for Google's AI optimization
EXAMPLES;
}
```

---

### 4.2 StrategyPrompt

**Current State:** Well-structured but missing critical context.

**Issues:**
| Problem | Impact | Fix |
|---------|--------|-----|
| No competitor context | High | Include competitor analysis in strategy |
| Missing seasonal context | Medium | Add current season/events |
| No historical performance | High | Include past campaign learnings |

**Enhanced Strategy Prompt:**

```php
public static function build(
    Campaign $campaign, 
    string $knowledgeBaseContent, 
    array $recommendations = [], 
    ?BrandGuideline $brandGuidelines = null, 
    array $enabledPlatforms = [],
    array $competitorInsights = [], // NEW
    array $historicalPerformance = [] // NEW
): string {
    // Add competitor insights
    $competitorSection = self::formatCompetitorInsights($competitorInsights);
    
    // Add historical learnings
    $historicalSection = self::formatHistoricalPerformance($historicalPerformance);
    
    // Add seasonal context
    $seasonalSection = self::getSeasonalContext();
    
    // ...existing prompt...
}

protected static function getSeasonalContext(): string
{
    $month = now()->format('F');
    $upcomingEvents = self::getUpcomingEvents(30); // Next 30 days
    
    return <<<SEASONAL
**SEASONAL CONTEXT:**
- Current Month: {$month}
- Upcoming Events: {$upcomingEvents}
- Consider seasonal messaging and urgency where appropriate
SEASONAL;
}
```

---

### 4.3 OptimizationPrompt

**Current State:** Too basic, lacks specificity.

**Issues:**
| Problem | Impact | Fix |
|---------|--------|-----|
| Generic recommendations only | High | Add platform-specific guidance |
| No business context | Medium | Include industry benchmarks |
| Missing implementation details | High | Add API-level action specifications |

**Enhanced Optimization Prompt:**

```php
public static function generate(array $campaignData, array $performanceMetrics, array $industryBenchmarks = []): string
{
    $campaignJson = json_encode($campaignData, JSON_PRETTY_PRINT);
    $metricsJson = json_encode($performanceMetrics, JSON_PRETTY_PRINT);
    $benchmarksJson = json_encode($industryBenchmarks, JSON_PRETTY_PRINT);

    return <<<PROMPT
You are an expert {$campaignData['platform']} Optimization Agent.

# CAMPAIGN DETAILS
{$campaignJson}

# PERFORMANCE METRICS (Last 30 Days)
{$metricsJson}

# INDUSTRY BENCHMARKS for {$campaignData['industry']}
{$benchmarksJson}

# ANALYSIS REQUIREMENTS

1. **Performance vs Benchmarks**
   - Compare each metric to industry average
   - Identify areas above/below benchmark

2. **Optimization Opportunities**
   - Budget: Is budget limiting performance? Should it increase/decrease?
   - Bidding: Is the bidding strategy optimal for the conversion volume?
   - Keywords: Are there wasted spend or missed opportunities?
   - Creatives: Is CTR below benchmark? Creative fatigue detected?
   - Targeting: Should audiences be expanded or narrowed?

3. **Action Items**
   - Provide specific API calls or settings changes
   - Include exact values (e.g., "Increase daily budget from \$50 to \$75")

# RESPONSE FORMAT
{
    "performance_summary": {
        "overall_health": "good|warning|critical",
        "vs_benchmark": {
            "ctr": "+15%",
            "cpa": "-8%",
            "roas": "+22%"
        }
    },
    "recommendations": [
        {
            "type": "BUDGET|BIDDING|KEYWORDS|CREATIVES|TARGETING",
            "action": "INCREASE|DECREASE|ADD|REMOVE|MODIFY",
            "current_value": "current setting",
            "recommended_value": "new setting",
            "description": "What to do",
            "reasoning": "Why this will help",
            "expected_impact": "Estimated improvement",
            "confidence": 0-100,
            "api_action": {
                "service": "CampaignBudgetService",
                "method": "update",
                "parameters": {}
            }
        }
    ]
}
PROMPT;
}
```

---

## 5. Workflow Improvements

### 5.1 AutomatedCampaignMaintenance Job

**Current Flow:**
1. SelfHealingAgent (ad disapprovals)
2. SearchTermMiningAgent (keywords)
3. BudgetIntelligenceAgent (budget)

**Recommended Enhanced Flow:**

```php
public function handle(): void
{
    $campaigns = Campaign::active()->with('customer')->get();

    foreach ($campaigns as $campaign) {
        // 1. Health Check (New - runs first)
        $healthResults = $this->healthCheckAgent->check($campaign);
        if ($healthResults['critical_issues']) {
            $this->notifyOwner($campaign, $healthResults);
            continue; // Skip optimization if critical issues
        }

        // 2. Self-Healing (Enhanced)
        $healingResults = $this->selfHealingAgent->heal($campaign);

        // 3. Performance Analysis (New)
        $performanceResults = $this->optimizationAgent->analyze($campaign);

        // 4. Creative Analysis (New - weekly)
        if (now()->dayOfWeek === Carbon::MONDAY) {
            $creativeResults = $this->creativeAgent->analyze($campaign);
        }

        // 5. Search Term Mining (Google only)
        if ($campaign->platform === 'google') {
            $miningResults = $this->searchTermAgent->mine($campaign);
        }

        // 6. Budget Intelligence (Enhanced)
        $budgetResults = $this->budgetAgent->optimizeWithPerformance($campaign, $performanceResults);

        // 7. Auto-Apply High-Confidence Recommendations (New)
        $this->applyHighConfidenceChanges($campaign, $performanceResults);

        // 8. Store Results & Learn (New)
        $this->storeMaintenanceResults($campaign, [...]);
    }
}
```

---

### 5.2 New Agent: HealthCheckAgent

**Purpose:** Pre-flight checks before any optimization.

```php
class HealthCheckAgent
{
    public function check(Campaign $campaign): array
    {
        return [
            'account_health' => $this->checkAccountHealth($campaign),
            'payment_status' => $this->checkPaymentStatus($campaign),
            'landing_page' => $this->checkLandingPage($campaign),
            'tracking' => $this->checkConversionTracking($campaign),
            'budget_depleted' => $this->checkBudgetDepletion($campaign),
            'critical_issues' => [], // Populated if any check fails
        ];
    }

    protected function checkLandingPage(Campaign $campaign): array
    {
        $url = $campaign->landing_page_url;
        $response = Http::timeout(10)->get($url);
        
        return [
            'status_code' => $response->status(),
            'load_time' => $response->transferStats?->getTransferTime(),
            'is_healthy' => $response->successful(),
            'redirect_chain' => [], // Track redirects
        ];
    }
}
```

---

## 6. Missing Agents to Build

### 6.1 LandingPageOptimizationAgent

**Purpose:** Analyze landing page performance and suggest improvements.

```php
class LandingPageOptimizationAgent
{
    public function analyze(string $landingPageUrl, Campaign $campaign): array
    {
        // 1. Technical analysis
        $technical = $this->analyzeTechnical($landingPageUrl);
        
        // 2. Content analysis
        $content = $this->analyzeContent($landingPageUrl, $campaign);
        
        // 3. CRO recommendations
        $cro = $this->generateCRORecommendations($technical, $content);
        
        return [
            'page_speed' => $technical['speed'],
            'mobile_friendly' => $technical['mobile'],
            'content_alignment' => $content['ad_alignment_score'],
            'cro_recommendations' => $cro,
        ];
    }
}
```

### 6.2 AttributionAnalysisAgent

**Purpose:** Analyze conversion paths and attribution.

```php
class AttributionAnalysisAgent
{
    public function analyze(Customer $customer): array
    {
        // Analyze cross-channel attribution
        // Identify assist conversions
        // Recommend budget reallocation based on true value
    }
}
```

### 6.3 AnomalyDetectionAgent

**Purpose:** Detect unusual patterns that need attention.

```php
class AnomalyDetectionAgent
{
    public function detect(Campaign $campaign): array
    {
        // Detect:
        // - Sudden performance drops
        // - Unusual spend patterns
        // - Click fraud indicators
        // - Conversion tracking breaks
    }
}
```

---

## 7. Priority Roadmap

### Phase 1: Critical (Next 2 Weeks)
1. ✅ Enhance SelfHealingAgent with Facebook support
2. ✅ Add retry logic to execution agents
3. ✅ Add confidence scoring to optimization recommendations
4. ✅ Create HealthCheckAgent

### Phase 2: High Priority (Next Month)
1. Add creative fatigue detection to CreativeIntelligenceAgent
2. Implement AI-powered search term analysis
3. Add performance-based budget adjustments
4. Create LandingPageOptimizationAgent

### Phase 3: Medium Priority (Next Quarter)
1. Add Advantage+ Shopping support to Facebook agent
2. Implement cross-campaign learning
3. Add competitive ad library analysis
4. Create AnomalyDetectionAgent

### Phase 4: Long-term
1. Build AttributionAnalysisAgent
2. Implement predictive budget allocation
3. Add automated A/B testing framework
4. Create customer lifetime value prediction

---

## 8. Conclusion

The current agent architecture is well-designed with a solid foundation. The key improvements needed are:

1. **Resilience**: Add retry logic and better error handling
2. **Intelligence**: Move from rule-based to AI-powered decisions
3. **Cross-platform**: Ensure feature parity between Google and Facebook
4. **Auto-apply**: Enable high-confidence optimizations to be applied automatically
5. **Learning**: Add historical performance tracking to improve recommendations over time

The system is production-ready but has significant room for optimization and feature expansion.
