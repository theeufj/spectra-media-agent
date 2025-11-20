# Platform-Specific Execution Agents

## Overview

Currently, our deployment strategies (`FacebookAdsDeploymentStrategy` and `GoogleAdsDeploymentStrategy`) contain hardcoded execution logic with predefined sequences of API calls. This limits flexibility, makes maintenance difficult, and doesn't leverage AI for intelligent decision-making during deployment.

**The Problem:**
- Hardcoded campaign creation flows
- Static targeting logic
- Fixed creative upload sequences
- No dynamic adaptation to platform changes or errors
- Limited ability to handle edge cases
- Platform-specific logic scattered throughout deployment code
- **No awareness of total campaign budget constraints across platforms**
- **Lacks intelligent budget allocation based on platform performance potential**

**The Solution:**
Platform-specific execution agents that:
1. Understand platform capabilities and constraints
2. Dynamically determine optimal execution paths
3. Handle errors intelligently and adapt in real-time
4. Make platform-specific decisions based on context
5. Provide detailed execution reasoning and logging
6. **Respect total campaign budget limits and allocate intelligently across platforms**
7. **Optimize budget distribution based on platform strengths and business objectives**

---

## Campaign Budget Management

### Total Campaign Budget Concept

When users set up a campaign, they should specify a **total campaign budget** (e.g., $500 maximum spend) that represents the entire investment across all platforms. This budget constraint is critical for:

1. **Multi-Platform Budget Allocation**
   - Intelligently distribute budget across Google Ads, Facebook Ads, Microsoft Ads, etc.
   - Allocate based on platform strengths relative to campaign objectives
   - Consider minimum spend requirements per platform

2. **Strategy Tailoring**
   - Budget influences campaign type selection (e.g., $100 insufficient for Performance Max)
   - Determines testing vs scaling approach
   - Affects targeting breadth and creative variety

3. **Performance Optimization**
   - Enable budget reallocation based on platform performance
   - Prevent overspend on any single platform
   - Maximize overall campaign ROAS within budget constraint

### Budget Allocation Intelligence

```json
{
  "campaign_budget_analysis": {
    "total_budget": 500,
    "campaign_duration_days": 30,
    "objective": "conversions",
    "enabled_platforms": ["Google Ads", "Facebook Ads"],
    
    "allocation_strategy": {
      "Google Ads": {
        "allocated_budget": 300,
        "daily_budget": 10,
        "percentage": 60,
        "reasoning": "Higher intent traffic for conversion objective, search campaigns convert at 2x rate based on industry benchmarks"
      },
      "Facebook Ads": {
        "allocated_budget": 200,
        "daily_budget": 6.67,
        "percentage": 40,
        "reasoning": "Strong for awareness and retargeting, visual product benefits from social placement"
      }
    },
    
    "minimum_requirements_check": {
      "Google Ads": {
        "minimum_daily": 10,
        "recommended_minimum_total": 300,
        "status": "adequate"
      },
      "Facebook Ads": {
        "minimum_daily": 5,
        "recommended_minimum_total": 150,
        "status": "adequate"
      }
    },
    
    "constraints": {
      "prevent_overspend": true,
      "allow_reallocation": true,
      "reallocation_threshold": "after 7 days with performance data"
    }
  }
}
```

### Dynamic Budget Reallocation

```json
{
  "budget_optimization_analysis": {
    "days_elapsed": 10,
    "total_budget_remaining": 335,
    "original_allocation": {
      "Google Ads": 300,
      "Facebook Ads": 200
    },
    "actual_spend": {
      "Google Ads": 100,
      "Facebook Ads": 65
    },
    "performance": {
      "Google Ads": {
        "conversions": 15,
        "cpa": 6.67,
        "roas": 450
      },
      "Facebook Ads": {
        "conversions": 8,
        "cpa": 8.13,
        "roas": 370
      }
    },
    "reallocation_recommendation": {
      "action": "shift_budget",
      "from_platform": "Facebook Ads",
      "to_platform": "Google Ads",
      "new_allocation": {
        "Google Ads": 365,
        "Facebook Ads": 135
      },
      "reasoning": "Google Ads showing 22% higher ROAS and 18% lower CPA. Reallocate remaining budget to higher performer while maintaining minimum Facebook presence for retargeting."
    }
  }
}
```

---

## Architecture

### Current State (Problems)

```php
// FacebookAdsDeploymentStrategy.php - HARDCODED LOGIC
private function deployDisplayCampaign($accountId, $campaign, $strategy): bool
{
    // 1. Create Campaign - HARDCODED ORDER
    $fbCampaign = $this->campaignService->createCampaign(...);
    
    // 2. Create Ad Set - HARDCODED ORDER
    $fbAdSet = $this->adSetService->createAdSet(...);
    
    // 3. Upload Images - HARDCODED ORDER
    $imageUrl = Storage::disk('s3')->url($firstImage->s3_path);
    
    // 4. Create Creative - HARDCODED ORDER
    $fbCreative = $this->creativeService->createImageCreative(...);
    
    // 5. Create Ad - HARDCODED ORDER
    $fbAd = $this->adService->createAd(...);
    
    // No intelligence, no adaptation, no context awareness
}
```

### Proposed State (AI-Driven)

```php
// Platform-specific execution agent with AI decision-making
class FacebookAdsExecutionAgent
{
    public function execute(Strategy $strategy, Campaign $campaign): ExecutionResult
    {
        // 1. Validate budget allocation
        $budgetValidation = $this->validateBudgetAllocation($strategy, $campaign);
        if (!$budgetValidation->isValid()) {
            return ExecutionResult::failed($budgetValidation->errors());
        }
        
        // 2. Agent analyzes current state and requirements
        $executionPlan = $this->generateExecutionPlan($strategy, $campaign);
        
        // 3. Agent executes plan with real-time adaptation
        $result = $this->executePlan($executionPlan);
        
        // 4. Agent handles errors intelligently
        if ($result->hasErrors()) {
            $recoveryPlan = $this->generateRecoveryPlan($result);
            $result = $this->executeRecovery($recoveryPlan);
        }
        
        return $result;
    }
    
    private function validateBudgetAllocation(Strategy $strategy, Campaign $campaign): BudgetValidation
    {
        // Ensure platform budget respects total campaign budget
        // Check if allocated budget meets platform minimums
        // Validate daily budget calculations
    }
    
    private function generateExecutionPlan(Strategy $strategy, Campaign $campaign): ExecutionPlan
    {
        // Use AI to determine optimal execution sequence
        // Consider: available assets, platform requirements, best practices, BUDGET CONSTRAINTS
        // Output: Structured execution plan with reasoning
    }
}
```

---

## Platform-Specific Execution Agents

### 1. Facebook Ads Execution Agent

**Purpose:** Intelligently deploy and manage campaigns on Facebook Ads with platform-specific optimization.

**Key Capabilities:**

#### A. Dynamic Execution Planning
```json
{
  "agent": "FacebookAdsExecutionAgent",
  "analysis": {
    "budget_context": {
      "total_campaign_budget": 500,
      "allocated_to_facebook": 200,
      "daily_budget": 6.67,
      "campaign_duration_days": 30,
      "budget_status": "adequate_for_optimization"
    },
    "available_assets": {
      "images": 5,
      "videos": 2,
      "ad_copy_variations": 3
    },
    "platform_requirements": {
      "page_connected": true,
      "pixel_installed": true,
      "payment_method": "valid"
    },
    "optimization_opportunities": {
      "multi_creative_testing": true,
      "advantage_plus_eligible": true,
      "dynamic_creative": true
    }
  },
  "execution_plan": {
    "budget_adjusted_strategy": {
      "testing_budget": 60,
      "scaling_budget": 140,
      "reasoning": "With $200 allocated, reserve 30% for initial testing phase, then scale winners"
    },
    "steps": [
      {
        "action": "create_campaign",
        "objective": "CONVERSIONS",
        "budget_allocation": 200,
        "daily_budget": 6.67,
        "reasoning": "Customer has pixel installed and conversion events, optimize for conversions rather than link clicks. Daily budget calculated from total allocation over 30 days."
      },
      {
        "action": "create_adset_with_dynamic_creative",
        "initial_budget": 60,
        "reasoning": "Multiple images available, enable dynamic creative optimization. Start with testing budget to identify best performers."
      },
      {
        "action": "create_multiple_ad_variations",
        "count": 3,
        "budget_per_variation": 20,
        "reasoning": "Test 3 different value propositions from ad copy. Equal budget split during testing phase."
      }
    ]
  }
}
```

#### B. Intelligent Targeting Configuration
```json
{
  "targeting_analysis": {
    "audience_size_check": {
      "current_size": 45000,
      "status": "too_narrow",
      "recommendation": "expand_age_range"
    },
    "placement_optimization": {
      "instagram_feed": "include",
      "instagram_stories": "include",
      "facebook_feed": "include",
      "audience_network": "exclude",
      "reasoning": "High-quality visual assets favor Instagram, exclude low-quality placements"
    }
  }
}
```

#### C. Creative Optimization
```json
{
  "creative_strategy": {
    "format_selection": {
      "primary": "carousel",
      "reasoning": "Multiple product images available, carousel drives 30% higher engagement"
    },
    "image_selection": {
      "method": "quality_score",
      "selected_images": [1, 3, 5],
      "reasoning": "Highest aesthetic scores and feature diversity"
    },
    "copy_optimization": {
      "headline_testing": "all_variations",
      "description_testing": "top_2",
      "reasoning": "Test all headlines but limit description combinations to avoid overlap"
    }
  }
}
```

#### D. Error Recovery & Adaptation
```json
{
  "error_encountered": {
    "type": "targeting_too_narrow",
    "original_audience_size": 12000,
    "facebook_minimum": 50000
  },
  "recovery_actions": [
    {
      "action": "expand_geographic_targeting",
      "from": ["New York City"],
      "to": ["New York City", "Los Angeles", "Chicago"],
      "new_audience_size": 85000
    },
    {
      "action": "broaden_age_range",
      "from": "25-35",
      "to": "25-45",
      "reasoning": "Maintain primary demographic but include adjacent segments"
    }
  ],
  "validation": "audience_size_now_sufficient"
}
```

---

### 2. Google Ads Execution Agent

**Purpose:** Intelligently deploy and manage campaigns on Google Ads with platform-specific optimization.

**Key Capabilities:**

#### A. Campaign Type Selection Intelligence
```json
{
  "agent": "GoogleAdsExecutionAgent",
  "campaign_analysis": {
    "budget_context": {
      "total_campaign_budget": 500,
      "allocated_to_google": 300,
      "daily_budget": 10,
      "campaign_duration_days": 30,
      "minimum_performance_max_budget": 250,
      "budget_adequacy": "sufficient_for_performance_max"
    },
    "available_assets": {
      "headlines": 15,
      "descriptions": 4,
      "images": 8,
      "videos": 1,
      "product_feed": false
    },
    "business_goals": {
      "objective": "conversions",
      "budget": 300,
      "target_roas": 400
    },
    "platform_capabilities_assessment": {
      "performance_max": {
        "eligible": true,
        "confidence": "high",
        "reasoning": "Multiple asset types available, conversion objective, sufficient budget"
      },
      "search_campaigns": {
        "eligible": true,
        "confidence": "medium",
        "reasoning": "Strong headlines but limited by search volume"
      },
      "demand_gen": {
        "eligible": true,
        "confidence": "high",
        "reasoning": "Rich visual assets, broad awareness goal"
      }
    }
  },
  "recommendation": {
    "primary_campaign": "performance_max",
    "primary_budget": 250,
    "supporting_campaigns": [
      {
        "type": "branded_search",
        "budget": 50,
        "reasoning": "Protect brand terms and capture high-intent traffic"
      }
    ],
    "budget_allocation_reasoning": "With $300 total, allocate 83% to Performance Max (meets $250 minimum for effective learning) and 17% to branded search for brand protection. This split maximizes automation benefits while preventing competitor conquest.",
    "scaling_plan": {
      "if_total_budget_increases": "Add $100 to Performance Max first to improve learning, then consider non-brand search campaigns",
      "if_total_budget_decreases": "Drop branded search, focus all budget on Performance Max as it's more efficient for limited budgets"
    }
  }
}
```

#### B. Smart Bidding Strategy Selection
```json
{
  "bidding_strategy_analysis": {
    "conversion_data_available": {
      "conversions_last_30_days": 45,
      "conversion_rate": 3.2,
      "average_conversion_value": 125
    },
    "budget_constraints": {
      "total_campaign_budget": 500,
      "allocated_to_google": 300,
      "daily_budget": 10,
      "campaign_duration_days": 30
    },
    "recommendation": {
      "strategy": "maximize_conversion_value",
      "reasoning": "With limited budget ($300 total), maximize conversion value without ROAS constraint allows Google more flexibility to find conversions. Budget constraint naturally limits spend risk."
    },
    "alternative_if_higher_budget": {
      "strategy": "target_roas",
      "target_value": 400,
      "minimum_recommended_budget": 1000,
      "reasoning": "Target ROAS works best with larger budgets that allow algorithm more bidding flexibility. Current $300 budget too constraining for ROAS target."
    },
    "fallback_strategy": {
      "if_insufficient_data": "maximize_conversions",
      "reasoning": "If conversion tracking fails validation, fall back to maximize conversions to build data"
    }
  }
}
```

#### C. Keyword Strategy Intelligence
```json
{
  "keyword_strategy": {
    "search_volume_analysis": {
      "high_volume_keywords": ["buy sneakers", "running shoes"],
      "medium_volume_keywords": ["athletic footwear", "sports shoes"],
      "low_volume_keywords": ["marathon running shoes"],
      "niche_keywords": ["carbon plate running shoes"]
    },
    "match_type_recommendation": {
      "broad_match": ["running shoes", "athletic footwear"],
      "phrase_match": ["buy running shoes", "best running shoes"],
      "exact_match": ["[carbon plate running shoes]"],
      "reasoning": "Use broad match on high-intent terms with sufficient budget, phrase for qualified traffic, exact for niche high-value terms"
    },
    "negative_keyword_strategy": {
      "add_immediately": ["free", "cheap", "wholesale", "used"],
      "reasoning": "Prevent wasted spend on low-intent queries"
    }
  }
}
```

#### D. Ad Extension Optimization
```json
{
  "extension_strategy": {
    "sitelinks": {
      "enabled": true,
      "strategy": "category_based",
      "sitelinks": ["Men's Shoes", "Women's Shoes", "Sale", "New Arrivals"],
      "reasoning": "Direct users to key categories, improves CTR by 10-15%"
    },
    "callouts": {
      "enabled": true,
      "callouts": ["Free Shipping", "30-Day Returns", "Lifetime Warranty"],
      "reasoning": "Highlight competitive advantages"
    },
    "structured_snippets": {
      "enabled": true,
      "header": "Brands",
      "values": ["Nike", "Adidas", "New Balance", "Brooks"],
      "reasoning": "Showcase brand variety for multi-brand retailers"
    },
    "price_extensions": {
      "enabled": false,
      "reasoning": "Price points vary significantly by product, avoid setting expectations"
    }
  }
}
```

#### E. Asset Group Creation (Performance Max)
```json
{
  "asset_group_strategy": {
    "groups": [
      {
        "name": "Running Shoes - Premium",
        "audience_signal": "running_enthusiasts",
        "headlines": ["Premium Running Shoes", "Marathon Ready Footwear"],
        "descriptions": ["Advanced carbon plate technology for serious runners"],
        "images": ["premium_1.jpg", "premium_2.jpg"],
        "reasoning": "Target high-value running enthusiasts with premium positioning"
      },
      {
        "name": "Running Shoes - Value",
        "audience_signal": "fitness_beginners",
        "headlines": ["Affordable Running Shoes", "Start Your Running Journey"],
        "descriptions": ["Quality running shoes for every budget"],
        "images": ["value_1.jpg", "value_2.jpg"],
        "reasoning": "Capture broader audience with value positioning"
      }
    ],
    "reasoning": "Two asset groups allow distinct positioning for different customer segments"
  }
}
```

---

### 3. Microsoft Ads Execution Agent

**Purpose:** Intelligently deploy campaigns on Microsoft Ads (Bing) with platform-specific optimization.

**Key Capabilities:**

#### A. Platform-Specific Opportunities
```json
{
  "agent": "MicrosoftAdsExecutionAgent",
  "platform_advantages": {
    "linkedin_profile_targeting": {
      "available": true,
      "recommendation": "enable",
      "targeting": {
        "job_functions": ["IT", "Engineering"],
        "industries": ["Technology", "Software"],
        "company_sizes": ["1000+"]
      },
      "reasoning": "B2B product, leverage unique LinkedIn integration"
    },
    "lower_cpcs": {
      "expected_cpc_reduction": "30%",
      "reasoning": "Lower competition vs Google, same quality traffic for B2B"
    }
  }
}
```

---

### 4. TikTok Ads Execution Agent

**Purpose:** Intelligently deploy campaigns on TikTok with platform-specific optimization.

**Key Capabilities:**

#### A. Creative Format Optimization
```json
{
  "agent": "TikTokAdsExecutionAgent",
  "creative_analysis": {
    "video_requirements": {
      "aspect_ratio_check": "9:16",
      "duration_optimal": "9-15 seconds",
      "hook_timing": "first 2 seconds critical"
    },
    "platform_native_features": {
      "spark_ads": {
        "enabled": true,
        "reasoning": "Use organic post as ad for authenticity, 30% higher engagement"
      },
      "sound_usage": {
        "trending_sound": "enabled",
        "reasoning": "Leverage trending audio for discovery, 2x view-through rate"
      }
    }
  }
}
```

---

## Implementation Plan

### Phase 1: Core Agent Framework

```php
namespace App\Services\Execution;

abstract class PlatformExecutionAgent
{
    protected Customer $customer;
    protected GeminiService $llm;
    
    abstract public function execute(Strategy $strategy, Campaign $campaign): ExecutionResult;
    
    abstract protected function generateExecutionPlan(Strategy $strategy, Campaign $campaign): ExecutionPlan;
    
    abstract protected function validatePrerequisites(Strategy $strategy, Campaign $campaign): ValidationResult;
    
    abstract protected function analyzeOptimizationOpportunities(Strategy $strategy): OptimizationAnalysis;
    
    abstract protected function handleExecutionError(\Exception $e, ExecutionContext $context): RecoveryPlan;
}
```

### Phase 2: Facebook Ads Execution Agent

```php
namespace App\Services\Execution;

use App\Prompts\FacebookAdsExecutionPrompt;

class FacebookAdsExecutionAgent extends PlatformExecutionAgent
{
    public function execute(Strategy $strategy, Campaign $campaign): ExecutionResult
    {
        // 1. Validate prerequisites
        $validation = $this->validatePrerequisites($strategy, $campaign);
        if (!$validation->passed()) {
            return ExecutionResult::failed($validation->errors());
        }
        
        // 2. Analyze optimization opportunities
        $optimization = $this->analyzeOptimizationOpportunities($strategy);
        
        // 3. Generate AI-powered execution plan
        $plan = $this->generateExecutionPlan($strategy, $campaign, $optimization);
        
        // 4. Execute plan with monitoring
        return $this->executePlan($plan);
    }
    
    protected function generateExecutionPlan(
        Strategy $strategy, 
        Campaign $campaign,
        OptimizationAnalysis $optimization
    ): ExecutionPlan {
        $prompt = FacebookAdsExecutionPrompt::generate([
            'strategy' => $strategy->toArray(),
            'campaign' => $campaign->toArray(),
            'available_assets' => [
                'images' => $strategy->imageCollaterals()->active()->count(),
                'videos' => $strategy->videoCollaterals()->active()->count(),
                'ad_copies' => $strategy->adCopies()->count(),
            ],
            'platform_status' => [
                'page_connected' => !empty($this->customer->facebook_page_id),
                'pixel_installed' => $this->checkPixelInstallation(),
                'recent_performance' => $this->getRecentPerformance(),
            ],
            'optimization_opportunities' => $optimization->toArray(),
        ]);
        
        $result = $this->llm->generateWithThinkingAndSearch(
            'gemini-2.5-pro',
            FacebookAdsExecutionPrompt::getSystemInstruction(),
            $prompt
        );
        
        return ExecutionPlan::fromJson($result['text']);
    }
    
    protected function validatePrerequisites(Strategy $strategy, Campaign $campaign): ValidationResult
    {
        $errors = [];
        
        // Check Facebook account
        if (!$this->customer->facebook_ads_account_id) {
            $errors[] = 'No Facebook Ads account connected';
        }
        
        // Check Facebook Page
        if (!$this->customer->facebook_page_id) {
            $errors[] = 'No Facebook Page connected';
        }
        
        // Check creative assets
        if ($strategy->imageCollaterals()->active()->count() === 0 && 
            $strategy->videoCollaterals()->active()->count() === 0) {
            $errors[] = 'No active creative assets available';
        }
        
        // Check ad copy
        if (!$strategy->adCopies()->exists()) {
            $errors[] = 'No ad copy available';
        }
        
        return new ValidationResult(empty($errors), $errors);
    }
    
    protected function analyzeOptimizationOpportunities(Strategy $strategy): OptimizationAnalysis
    {
        $analysis = new OptimizationAnalysis();
        
        // Check for dynamic creative opportunities
        if ($strategy->imageCollaterals()->active()->count() >= 3) {
            $analysis->addOpportunity(
                'dynamic_creative',
                'Multiple images available for dynamic creative optimization',
                'high'
            );
        }
        
        // Check for Advantage+ campaign eligibility
        if ($this->checkPixelInstallation() && 
            $strategy->budget >= 50 && 
            $this->customer->hasRecentConversions()) {
            $analysis->addOpportunity(
                'advantage_plus',
                'Eligible for Advantage+ Shopping/Catalog campaigns',
                'high'
            );
        }
        
        // Check for video opportunities
        if ($strategy->videoCollaterals()->active()->count() > 0) {
            $analysis->addOpportunity(
                'video_ads',
                'Video content available for higher engagement',
                'medium'
            );
        }
        
        return $analysis;
    }
}
```

### Phase 3: Google Ads Execution Agent

```php
namespace App\Services\Execution;

use App\Prompts\GoogleAdsExecutionPrompt;

class GoogleAdsExecutionAgent extends PlatformExecutionAgent
{
    public function execute(Strategy $strategy, Campaign $campaign): ExecutionResult
    {
        // Similar structure to Facebook agent but with Google-specific logic
        
        $validation = $this->validatePrerequisites($strategy, $campaign);
        if (!$validation->passed()) {
            return ExecutionResult::failed($validation->errors());
        }
        
        $optimization = $this->analyzeOptimizationOpportunities($strategy);
        $plan = $this->generateExecutionPlan($strategy, $campaign, $optimization);
        
        return $this->executePlan($plan);
    }
    
    protected function analyzeOptimizationOpportunities(Strategy $strategy): OptimizationAnalysis
    {
        $analysis = new OptimizationAnalysis();
        
        // Check for Performance Max eligibility
        if ($this->hasMultipleAssetTypes($strategy) && 
            $strategy->budget >= 100 &&
            $this->customer->hasConversionTracking()) {
            $analysis->addOpportunity(
                'performance_max',
                'Eligible for Performance Max with full asset coverage',
                'high'
            );
        }
        
        // Check for Smart Bidding eligibility
        $conversionCount = $this->getRecentConversionCount(30);
        if ($conversionCount >= 30) {
            $analysis->addOpportunity(
                'target_roas',
                'Sufficient conversion data for Target ROAS bidding',
                'high'
            );
        } elseif ($conversionCount >= 15) {
            $analysis->addOpportunity(
                'target_cpa',
                'Sufficient conversion data for Target CPA bidding',
                'medium'
            );
        }
        
        // Check for audience signal opportunities
        if ($this->customer->hasCustomerListData()) {
            $analysis->addOpportunity(
                'customer_match',
                'Customer list available for audience targeting',
                'high'
            );
        }
        
        return $analysis;
    }
    
    private function hasMultipleAssetTypes(Strategy $strategy): bool
    {
        $assetTypes = 0;
        
        if ($strategy->adCopies()->count() > 0) $assetTypes++;
        if ($strategy->imageCollaterals()->active()->count() > 0) $assetTypes++;
        if ($strategy->videoCollaterals()->active()->count() > 0) $assetTypes++;
        
        return $assetTypes >= 2;
    }
}
```

### Phase 4: Execution Prompts

```php
namespace App\Prompts;

class FacebookAdsExecutionPrompt
{
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are a Facebook Ads platform execution specialist. Your role is to generate optimal, platform-specific execution plans for deploying advertising campaigns.

Your responsibilities:
1. Analyze available assets, campaign objectives, and platform capabilities
2. Generate detailed execution plans with step-by-step API calls
3. Identify optimization opportunities (Dynamic Creative, Advantage+, etc.)
4. Recommend optimal campaign structures, targeting, and creative strategies
5. Provide reasoning for all recommendations
6. Handle platform constraints and requirements

You must output a structured JSON execution plan that includes:
- Prerequisite checks
- Optimal campaign structure
- Creative optimization strategy
- Targeting recommendations
- Budget allocation
- Placement strategy
- Expected performance indicators

Always prioritize:
- Platform best practices
- Advertiser objectives
- Available creative assets
- Historical performance data
- Facebook's latest features and capabilities

INSTRUCTION;
    }
    
    public static function generate(array $context): string
    {
        return <<<PROMPT
Generate a comprehensive Facebook Ads execution plan based on the following context:

**Campaign Details:**
{$context['campaign']['name']}
Objective: {$context['campaign']['objective']}
Budget: \${$context['strategy']['budget']} per day

**Available Assets:**
- Images: {$context['available_assets']['images']}
- Videos: {$context['available_assets']['videos']}
- Ad Copy Variations: {$context['available_assets']['ad_copies']}

**Platform Status:**
- Facebook Page Connected: {$context['platform_status']['page_connected'] ? 'Yes' : 'No'}
- Pixel Installed: {$context['platform_status']['pixel_installed'] ? 'Yes' : 'No'}

**Strategy Context:**
{$context['strategy']['ad_copy_strategy']}

**Targeting:**
{$context['strategy']['targeting_config']}

**Recent Performance (if available):**
{$context['platform_status']['recent_performance'] ?? 'No recent performance data'}

**Optimization Opportunities Detected:**
{$context['optimization_opportunities']}

Generate a detailed execution plan that:
1. Selects the optimal campaign objective
2. Determines the best campaign structure
3. Recommends creative strategy (single creative, dynamic creative, etc.)
4. Optimizes targeting configuration
5. Suggests placement strategy
6. Provides step-by-step execution sequence
7. Includes fallback plans for common errors

Output must be valid JSON following this structure:
{
  "campaign_structure": {
    "objective": "...",
    "reasoning": "..."
  },
  "creative_strategy": {
    "format": "...",
    "selected_assets": [...],
    "reasoning": "..."
  },
  "targeting_optimization": {
    "audience_definition": {...},
    "estimated_reach": "...",
    "reasoning": "..."
  },
  "execution_sequence": [
    {
      "step": 1,
      "action": "create_campaign",
      "parameters": {...},
      "reasoning": "..."
    }
  ],
  "fallback_plans": [
    {
      "error_type": "...",
      "recovery_action": "...",
      "reasoning": "..."
    }
  ]
}

PROMPT;
    }
}
```

---

## Benefits of Platform-Specific Execution Agents

### 1. **Intelligent Decision Making**
- AI analyzes current state and makes optimal choices
- Adapts to platform updates automatically
- Considers multiple factors simultaneously
- Provides reasoning for transparency

### 2. **Reduced Maintenance**
- Less hardcoded logic to update
- Platform changes handled by AI understanding
- Fewer bugs from static assumptions
- Easier to extend to new platforms

### 3. **Better Performance**
- Optimizes for each platform's unique features
- Leverages latest platform capabilities
- Adapts execution based on available assets
- Handles edge cases intelligently

### 4. **Enhanced Error Handling**
- Intelligent error recovery
- Context-aware fallback strategies
- Graceful degradation
- Detailed error reporting with recovery suggestions

### 5. **Scalability**
- Easy to add new platforms
- Consistent agent pattern across platforms
- Reusable validation and optimization logic
- Clear separation of concerns

---

## Migration Strategy

### Step 1: Create Base Infrastructure (Week 1-2)
- Implement `PlatformExecutionAgent` abstract class
- Create `ExecutionResult`, `ExecutionPlan`, `ValidationResult` classes
- Build `OptimizationAnalysis` framework
- Set up logging and monitoring

### Step 2: Implement Facebook Ads Agent (Week 3-4)
- Create `FacebookAdsExecutionAgent`
- Implement `FacebookAdsExecutionPrompt`
- Build prerequisite validation
- Add optimization opportunity detection
- Create execution plan generation
- Implement plan execution logic

### Step 3: Implement Google Ads Agent (Week 5-6)
- Create `GoogleAdsExecutionAgent`
- Implement `GoogleAdsExecutionPrompt`
- Build Google-specific validations
- Add Performance Max eligibility detection
- Create Smart Bidding recommendations
- Implement keyword strategy intelligence

### Step 4: Parallel Testing (Week 7-8)
- Run old and new systems in parallel
- Compare execution plans and results
- Validate performance improvements
- Gather feedback from deployments

### Step 5: Migration & Deprecation (Week 9-10)
- Switch to agent-based execution
- Deprecate old deployment strategies
- Monitor production performance
- Iterate on prompts and logic

---

## Success Metrics

1. **Execution Intelligence**
   - % of deployments using platform-specific features
   - Average optimization opportunities utilized per campaign
   - Quality of execution reasoning (manual review)

2. **Error Handling**
   - % of deployments requiring manual intervention
   - Average time to recover from errors
   - Success rate of automated error recovery

3. **Performance**
   - Campaign performance vs hardcoded deployments
   - Time to deploy (should be similar or better)
   - Platform API error rate

4. **Maintenance**
   - Lines of code maintained (should decrease)
   - Time to add new platform support
   - Bug count in deployment logic

---

## Future Enhancements

### 1. Cross-Platform Learning
- Agents share learnings across platforms
- Identify universal optimization patterns
- Build platform comparison intelligence

### 2. A/B Testing Integration
- Agents automatically set up multivariate tests
- Test different execution strategies
- Learn from test results

### 3. Predictive Optimization
- Predict campaign performance before deployment
- Simulate different execution strategies
- Recommend optimal configuration

### 4. Autonomous Optimization
- Agents monitor post-deployment performance
- Automatically adjust targeting, creative, bidding
- Continuous learning and improvement

---

## Conclusion

Platform-specific execution agents represent a fundamental shift from hardcoded deployment logic to intelligent, adaptive AI-driven execution. This approach:

- **Reduces technical debt** by minimizing hardcoded logic
- **Improves campaign performance** through intelligent optimization
- **Increases maintainability** with clear agent patterns
- **Scales efficiently** to new platforms and features
- **Provides transparency** through execution reasoning

The investment in building these agents will pay dividends in reduced maintenance burden, improved campaign performance, and the ability to rapidly adapt to platform changes and new features.
