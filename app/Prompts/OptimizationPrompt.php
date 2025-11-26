<?php

namespace App\Prompts;

class OptimizationPrompt
{
    /**
     * Generate optimization prompt with support for historical comparison and confidence scoring.
     *
     * @param array $campaignData Campaign information
     * @param array $performanceMetrics Current performance metrics (last 30 days)
     * @param array|null $historicalMetrics Historical metrics for trend analysis (optional)
     * @return string The generated prompt
     */
    public static function generate(array $campaignData, array $performanceMetrics, ?array $historicalMetrics = null): string
    {
        $campaignJson = json_encode($campaignData, JSON_PRETTY_PRINT);
        $metricsJson = json_encode($performanceMetrics, JSON_PRETTY_PRINT);
        $historicalJson = $historicalMetrics ? json_encode($historicalMetrics, JSON_PRETTY_PRINT) : 'No historical data available';
        
        $dataQualityNote = self::getDataQualityNote($campaignData);

        return <<<PROMPT
You are an expert Digital Advertising Optimization Agent with deep knowledge of Google Ads and Facebook Ads platforms.
Your goal is to analyze campaign performance and provide actionable recommendations with confidence assessments.

Campaign Details:
{$campaignJson}

Current Performance Metrics (Last 30 Days):
{$metricsJson}

Historical Performance Metrics (Previous 30-Day Period):
{$historicalJson}

Data Quality Assessment:
{$dataQualityNote}

Analyze the data comprehensively and provide recommendations. For each recommendation, consider:

1. **Data Sufficiency**: Do we have enough data to make this recommendation confidently?
2. **Trend Analysis**: How does current performance compare to historical patterns?
3. **Platform Best Practices**: What does the platform (Google/Facebook) recommend for similar situations?
4. **Risk Assessment**: What could go wrong if this recommendation is applied?

Focus Areas:
1. Budget allocation (if limited by budget, underspending, or inefficient spend distribution)
2. Bid adjustments (based on CPA, ROAS, or conversion performance)
3. Keyword/audience opportunities (if targeting needs refinement)
4. Ad copy improvements (if CTR or engagement is below benchmarks)
5. Creative optimization (if ad fatigue or low engagement is detected)

Return your response in the following JSON format:
{
    "analysis": "A comprehensive summary of campaign performance, highlighting key strengths and areas for improvement.",
    "data_confidence": "HIGH|MEDIUM|LOW - Overall confidence in the data available for analysis",
    "trend_summary": "Brief description of performance trends (improving, declining, stable)",
    "recommendations": [
        {
            "type": "BUDGET|BIDDING|KEYWORDS|ADS|TARGETING|CREATIVE|OTHER",
            "action": "INCREASE|DECREASE|ADD|REMOVE|MODIFY|TEST",
            "description": "Detailed description of the recommendation.",
            "reasoning": "Why this recommendation will help achieve the campaign goals, with supporting data points.",
            "impact": "HIGH|MEDIUM|LOW",
            "risk_level": "HIGH|MEDIUM|LOW",
            "data_requirements_met": true|false,
            "estimated_improvement": "Expected improvement (e.g., '+15-20% conversions', '-10% CPA')",
            "implementation_notes": "Specific steps or considerations for implementing this change"
        }
    ],
    "quick_wins": [
        "List of low-risk, high-confidence optimizations that can be applied immediately"
    ],
    "monitoring_suggestions": [
        "Metrics to watch after implementing recommendations"
    ]
}

Important Guidelines:
- Be conservative with recommendations when data is limited
- Clearly indicate when more data is needed for confident recommendations  
- Prioritize quick wins and low-risk optimizations
- Consider the campaign's primary KPI when making recommendations
- Account for platform-specific best practices and limitations
PROMPT;
    }

    /**
     * Generate data quality note based on campaign data.
     */
    protected static function getDataQualityNote(array $campaignData): string
    {
        $score = $campaignData['data_quality_score'] ?? null;
        $notes = $campaignData['data_quality_notes'] ?? [];
        
        if ($score === null) {
            return 'Data quality assessment not available.';
        }
        
        $level = match (true) {
            $score >= 80 => 'HIGH',
            $score >= 50 => 'MEDIUM',
            default => 'LOW',
        };
        
        $noteString = !empty($notes) ? implode('; ', $notes) : 'No specific data quality concerns.';
        
        return "Data Quality Level: {$level} (Score: {$score}/100)\nNotes: {$noteString}";
    }
}
