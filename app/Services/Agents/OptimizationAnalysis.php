<?php

namespace App\Services\Agents;

/**
 * Represents available optimization opportunities for a platform.
 * 
 * Tracks platform-specific features and optimizations that can be leveraged
 * based on available assets, budget, and account configuration.
 */
class OptimizationAnalysis
{
    protected array $opportunities = [];

    /**
     * Add an optimization opportunity.
     * 
     * @param string $type Type of optimization (e.g., 'performance_max', 'dynamic_creative')
     * @param string $description Description of the opportunity
     * @param string $confidence Confidence level: 'high', 'medium', 'low'
     * @param array $requirements Requirements or conditions for this opportunity
     * @return self
     */
    public function addOpportunity(
        string $type,
        string $description,
        string $confidence = 'medium',
        array $requirements = []
    ): self {
        $this->opportunities[] = [
            'type' => $type,
            'description' => $description,
            'confidence' => $confidence,
            'requirements' => $requirements,
        ];
        return $this;
    }

    /**
     * Check if any opportunities are available.
     * 
     * @return bool True if opportunities exist
     */
    public function hasOpportunities(): bool
    {
        return !empty($this->opportunities);
    }

    /**
     * Get all opportunities.
     * 
     * @return array All opportunities
     */
    public function getOpportunities(): array
    {
        return $this->opportunities;
    }

    /**
     * Get opportunities by confidence level.
     * 
     * @param string $confidence Confidence level to filter by
     * @return array Filtered opportunities
     */
    public function getByConfidence(string $confidence): array
    {
        return array_filter(
            $this->opportunities,
            fn($opp) => $opp['confidence'] === $confidence
        );
    }

    /**
     * Get high-confidence opportunities only.
     * 
     * @return array High-confidence opportunities
     */
    public function getHighConfidenceOpportunities(): array
    {
        return $this->getByConfidence('high');
    }

    /**
     * Check if a specific opportunity type exists.
     * 
     * @param string $type Opportunity type
     * @return bool True if opportunity exists
     */
    public function hasOpportunityType(string $type): bool
    {
        foreach ($this->opportunities as $opp) {
            if ($opp['type'] === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get count of opportunities.
     * 
     * @return int Number of opportunities
     */
    public function count(): int
    {
        return count($this->opportunities);
    }

    /**
     * Convert to array for storage or passing to AI.
     * 
     * @return array Analysis as array
     */
    public function toArray(): array
    {
        return [
            'opportunities' => $this->opportunities,
            'total_count' => $this->count(),
            'high_confidence_count' => count($this->getHighConfidenceOpportunities()),
        ];
    }

    /**
     * Get a summary string of opportunities.
     * 
     * @return string Summary of opportunities
     */
    public function getSummary(): string
    {
        if (!$this->hasOpportunities()) {
            return 'No optimization opportunities identified';
        }

        $highConfidence = $this->getHighConfidenceOpportunities();
        $summary = sprintf(
            '%d optimization opportunit%s identified',
            $this->count(),
            $this->count() === 1 ? 'y' : 'ies'
        );

        if (!empty($highConfidence)) {
            $summary .= sprintf(
                ' (%d high-confidence)',
                count($highConfidence)
            );
        }

        return $summary;
    }
}
