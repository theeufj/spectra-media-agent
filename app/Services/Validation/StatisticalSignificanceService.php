<?php

namespace App\Services\Validation;

use App\Models\Strategy;
use InvalidArgumentException;

class StatisticalSignificanceService
{
    private const CONFIDENCE_LEVEL = 0.95; // 95% confidence

    public function __invoke(Strategy $strategyA, Strategy $strategyB): array
    {
        $performanceA = $strategyA->performanceData->first();
        $performanceB = $strategyB->performanceData->first();

        if (!$performanceA || !$performanceB) {
            throw new InvalidArgumentException("Both strategies must have performance data to compare.");
        }

        $conversionsA = $performanceA->conversions;
        $impressionsA = $performanceA->impressions;
        $conversionsB = $performanceB->conversions;
        $impressionsB = $performanceB->impressions;

        // Handle edge case where impressions are zero to avoid division by zero
        if ($impressionsA == 0 || $impressionsB == 0) {
            return $this->formatResult(false, 1.0, "Cannot calculate significance with zero impressions.");
        }
        
        $convRateA = $conversionsA / $impressionsA;
        $convRateB = $conversionsB / $impressionsB;

        $pValue = $this->calculatePValue($conversionsA, $impressionsA, $conversionsB, $impressionsB);
        $isSignificant = $pValue < (1 - self::CONFIDENCE_LEVEL);

        $winner = null;
        if ($isSignificant) {
            $winner = $convRateA > $convRateB ? 'A' : 'B';
        }
        
        $summary = $this->generateSummary($isSignificant, $pValue, $winner, $convRateA, $convRateB);

        return $this->formatResult($isSignificant, $pValue, $summary, $winner);
    }

    private function calculatePValue(int $convA, int $impA, int $convB, int $impB): float
    {
        $p1 = $convA / $impA;
        $p2 = $convB / $impB;

        $p_pooled = ($convA + $convB) / ($impA + $impB);
        
        if ($p_pooled == 0 || $p_pooled == 1) {
            return 1.0; // No variance, cannot determine significance
        }

        $se = sqrt($p_pooled * (1 - $p_pooled) * (1/$impA + 1/$impB));
        
        if ($se == 0) {
            return 1.0; // Cannot calculate Z-score
        }

        $z_score = ($p1 - $p2) / $se;
        
        // Convert Z-score to p-value (two-tailed test)
        return 2 * (1 - $this->cumulativeDistribution(abs($z_score)));
    }

    private function cumulativeDistribution(float $z): float
    {
        // Standard normal cumulative distribution function approximation
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p = 0.2316419;
        $c2 = 0.39894228;

        if ($z >= 0.0) {
            $t = 1.0 / (1.0 + $p * $z);
            return (1.0 - $c2 * exp(-$z * $z / 2.0) * $t * ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
        } else {
            $t = 1.0 / (1.0 - $p * $z);
            return ($c2 * exp(-$z * $z / 2.0) * $t * ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
        }
    }

    private function generateSummary(bool $isSignificant, float $pValue, ?string $winner, float $rateA, float $rateB): string
    {
        $confidencePercentage = self::CONFIDENCE_LEVEL * 100;
        if ($isSignificant) {
            $improvement = 0;
            if ($winner === 'A' && $rateB > 0) {
                $improvement = (($rateA - $rateB) / $rateB) * 100;
                return "The results are statistically significant. Strategy A outperformed Strategy B with a " . round($improvement, 2) . "% higher conversion rate. We are {$confidencePercentage}% confident in this result.";
            } elseif ($winner === 'B' && $rateA > 0) {
                $improvement = (($rateB - $rateA) / $rateA) * 100;
                return "The results are statistically significant. Strategy B outperformed Strategy A with a " . round($improvement, 2) . "% higher conversion rate. We are {$confidencePercentage}% confident in this result.";
            }
            return "The difference in performance is statistically significant.";
        } else {
            return "The difference in performance is not statistically significant (p-value: " . round($pValue, 4) . "). There is not enough evidence to declare a winner.";
        }
    }

    private function formatResult(bool $isSignificant, float $pValue, string $summary, ?string $winner = null): array
    {
        return [
            'is_significant' => $isSignificant,
            'p_value' => $pValue,
            'confidence_level' => self::CONFIDENCE_LEVEL,
            'winner' => $winner,
            'summary' => $summary,
        ];
    }
}
