<?php

namespace App\Prompts;

/**
 * CompetitorDiscoveryPrompt
 * 
 * Generates prompts for AI-powered competitor discovery using Google Search grounding.
 * The agent analyzes the customer's website content and uses real-time search to find
 * actual competitors in the market.
 */
class CompetitorDiscoveryPrompt
{
    /**
     * Generate the system instruction for competitor discovery.
     */
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert competitive intelligence analyst with deep expertise in market research and competitor identification. 

Your task is to discover and analyze competitors for a business based on their website content, industry, and product/service offerings.

IMPORTANT: You have access to Google Search. USE IT to find REAL competitors. Do not make up competitor names or URLs - search for actual businesses that compete in this market.

When searching:
1. Look for "[industry/product] companies" or "[product type] alternatives"
2. Search for businesses mentioned in industry publications
3. Find companies ranking for similar keywords
4. Look for "best [product/service] providers" lists

Return ONLY verified, real competitor URLs that you found through search.
INSTRUCTION;
    }

    /**
     * Build the competitor discovery prompt.
     *
     * @param string $businessName The customer's business name
     * @param string $websiteUrl The customer's website URL
     * @param string $knowledgeBaseContent Summarized content from the customer's website
     * @param string|null $industry The industry/vertical if known
     * @param array $existingCompetitors Already tracked competitor domains to exclude
     * @return string The formatted prompt
     */
    public static function build(
        string $businessName,
        string $websiteUrl,
        string $knowledgeBaseContent,
        ?string $industry = null,
        array $existingCompetitors = []
    ): string {
        $existingList = !empty($existingCompetitors) 
            ? "EXCLUDE these already tracked competitors:\n- " . implode("\n- ", $existingCompetitors)
            : "No existing competitors tracked yet.";

        return <<<PROMPT
**COMPETITOR DISCOVERY REQUEST**

**Business to Analyze:**
- Name: {$businessName}
- Website: {$websiteUrl}
- Industry: {$industry}

**Website Content Summary:**
{$knowledgeBaseContent}

**{$existingList}**

---

**YOUR TASK:**
Using Google Search, find 5-10 REAL competitors for this business. Search for:
1. Direct competitors (same product/service, same market)
2. Indirect competitors (similar solution, different approach)
3. Emerging competitors (newer players in the space)

For each competitor found, provide:
- Their actual website URL (verified through search)
- Their business name
- Brief description of what they offer
- Why they compete with this business

**RESPONSE FORMAT (JSON):**
{
  "search_queries_used": ["query 1", "query 2", ...],
  "competitors": [
    {
      "url": "https://competitor1.com",
      "domain": "competitor1.com",
      "name": "Competitor One Inc",
      "description": "Brief description of their business",
      "competition_type": "direct|indirect|emerging",
      "why_competitor": "Why they compete with our business",
      "estimated_size": "small|medium|large|enterprise"
    }
  ],
  "market_observations": "Brief notes about the competitive landscape"
}

**CRITICAL:** 
- Only include competitors you found through actual Google searches
- Verify URLs are real and active
- Do not include the business being analyzed
- Do not include generic marketplaces (Amazon, eBay) unless directly relevant
PROMPT;
    }
}
