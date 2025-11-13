<?php

namespace App\Prompts;

use App\Models\Campaign;
use App\Models\User;

/**
 * StrategyPrompt is a dedicated class responsible for constructing the prompt
 * used to generate a marketing strategy from an LLM.
 *
 * In Go, you might implement this as a struct with a method, e.g.,
 * type StrategyPrompt struct { ... }
 * func (p *StrategyPrompt) Build(campaign *Campaign) (string, error) { ... }
 *
 * Centralizing prompt generation like this makes it easier to test, version,
 * and refine the prompts without changing the core application logic.
 */
class StrategyPrompt
{
    /**
     * build constructs the final prompt string.
     *
     * @param Campaign $campaign The campaign containing the marketing brief.
     * @param string $knowledgeBaseContent The compiled content from the user's website.
     * @return string The fully constructed prompt.
     */
    public static function build(Campaign $campaign, string $knowledgeBaseContent): string
    {
        // Here, we use a HEREDOC string (`<<<PROMPT`) for a clean, multi-line prompt.
        // This is similar to using backticks for multi-line strings in Go.
        return <<<PROMPT
You are an expert digital marketing strategist. Your task is to generate a comprehensive, platform-specific marketing strategy based on the provided campaign brief and knowledge base.

**YOUR RESPONSE MUST BE A VALID, PARSABLE JSON OBJECT.**
The JSON object should have a single root key: "strategies".
The value of "strategies" should be an array of objects, where each object represents the strategy for a single platform.
Each platform object must have the following keys: "platform", "ad_copy_strategy", "imagery_strategy", "video_strategy".

**Example Response Format:**
```json
{
  "strategies": [
    {
      "platform": "Facebook",
      "ad_copy_strategy": "Focus on vibrant, lifestyle-oriented copy...",
      "imagery_strategy": "Use bright, eye-catching images of people enjoying the product...",
      "video_strategy": "Create short, engaging video clips (15-30 seconds) showcasing the product in action..."
    },
    {
      "platform": "Google Ads (SEM)",
      "ad_copy_strategy": "Write concise, keyword-rich headlines and descriptions...",
      "imagery_strategy": "N/A for text-based search ads.",
      "video_strategy": "N/A for text-based search ads."
    }
  ]
}
```

---

**1. KNOWLEDGE BASE (The Brand & Products):**
This is the context about the business, its brand voice, and its products, extracted from their website.
---
{$knowledgeBaseContent}
---

**2. CAMPAIGN BRIEF (The Goal):**
This is the specific goal for the marketing campaign.
---
- **Campaign Name:** {$campaign->name}
- **Reason for Campaign:** {$campaign->reason}
- **Primary Goals:** {$campaign->goals}
- **Target Market:** {$campaign->target_market}
- **Brand Voice:** {$campaign->voice}
- **Budget:** \${$campaign->total_budget}
- **Duration:** {$campaign->start_date} to {$campaign->end_date}
- **Key Performance Indicator (KPI):** {$campaign->primary_kpi}
- **Product/Service Focus:** {$campaign->product_focus}
- **Exclusions (What to avoid):** {$campaign->exclusions}
---

Based on all the information above, generate the JSON object containing the platform-specific strategies for Facebook, Instagram, and Google Ads (SEM).
PROMPT;
    }
}
