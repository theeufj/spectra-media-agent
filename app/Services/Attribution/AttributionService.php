<?php

namespace App\Services\Attribution;

/**
 * AttributionService
 *
 * Implements 5 multi-touch attribution models:
 * - Last Click: 100% credit to the last touchpoint
 * - First Click: 100% credit to the first touchpoint
 * - Linear: Equal credit across all touchpoints
 * - Time Decay: Exponentially more credit to recent touchpoints (7-day half-life)
 * - Position Based (U-Shaped): 40% first, 40% last, 20% distributed among middle
 */
class AttributionService
{
    // Half-life in days for time decay model
    protected float $halfLifeDays = 7.0;

    /**
     * Run all attribution models on a conversion journey.
     *
     * @param array $touchpoints Ordered touchpoint records
     * @param float $conversionValue The total conversion value to attribute
     * @return array Keyed by model name, each containing per-touchpoint attributions
     */
    public function attributeAll(array $touchpoints, float $conversionValue = 1.0): array
    {
        if (empty($touchpoints)) {
            return [];
        }

        return [
            'last_click' => $this->lastClick($touchpoints, $conversionValue),
            'first_click' => $this->firstClick($touchpoints, $conversionValue),
            'linear' => $this->linear($touchpoints, $conversionValue),
            'time_decay' => $this->timeDecay($touchpoints, $conversionValue),
            'position_based' => $this->positionBased($touchpoints, $conversionValue),
        ];
    }

    /**
     * Last Click: 100% credit to the final touchpoint.
     */
    public function lastClick(array $touchpoints, float $value): array
    {
        $result = $this->initResult($touchpoints);
        $lastIdx = count($touchpoints) - 1;
        $result[$lastIdx]['credit'] = 1.0;
        $result[$lastIdx]['value'] = $value;
        return $result;
    }

    /**
     * First Click: 100% credit to the first touchpoint.
     */
    public function firstClick(array $touchpoints, float $value): array
    {
        $result = $this->initResult($touchpoints);
        $result[0]['credit'] = 1.0;
        $result[0]['value'] = $value;
        return $result;
    }

    /**
     * Linear: Equal credit distributed across all touchpoints.
     */
    public function linear(array $touchpoints, float $value): array
    {
        $result = $this->initResult($touchpoints);
        $count = count($touchpoints);
        $creditEach = 1.0 / $count;
        $valueEach = $value / $count;

        foreach ($result as &$tp) {
            $tp['credit'] = round($creditEach, 6);
            $tp['value'] = round($valueEach, 2);
        }

        return $result;
    }

    /**
     * Time Decay: Exponentially weighted toward the most recent touchpoint.
     * Uses a 7-day half-life by default.
     */
    public function timeDecay(array $touchpoints, float $value): array
    {
        $result = $this->initResult($touchpoints);
        $count = count($touchpoints);

        if ($count === 1) {
            $result[0]['credit'] = 1.0;
            $result[0]['value'] = $value;
            return $result;
        }

        // Get conversion time (last touchpoint)
        $conversionTime = $this->getTimestamp($touchpoints[$count - 1]);
        $weights = [];
        $totalWeight = 0;

        foreach ($touchpoints as $i => $tp) {
            $tpTime = $this->getTimestamp($tp);
            $daysBefore = max(0, ($conversionTime - $tpTime) / 86400); // seconds to days
            $weight = pow(2, -$daysBefore / $this->halfLifeDays);
            $weights[$i] = $weight;
            $totalWeight += $weight;
        }

        // Normalize
        foreach ($result as $i => &$tp) {
            $tp['credit'] = $totalWeight > 0 ? round($weights[$i] / $totalWeight, 6) : 0;
            $tp['value'] = round($tp['credit'] * $value, 2);
        }

        return $result;
    }

    /**
     * Position Based (U-Shaped): 40% first, 40% last, 20% distributed among middle.
     */
    public function positionBased(array $touchpoints, float $value): array
    {
        $result = $this->initResult($touchpoints);
        $count = count($touchpoints);

        if ($count === 1) {
            $result[0]['credit'] = 1.0;
            $result[0]['value'] = $value;
            return $result;
        }

        if ($count === 2) {
            $result[0]['credit'] = 0.5;
            $result[0]['value'] = round($value * 0.5, 2);
            $result[1]['credit'] = 0.5;
            $result[1]['value'] = round($value * 0.5, 2);
            return $result;
        }

        // First and last get 40% each
        $result[0]['credit'] = 0.4;
        $result[0]['value'] = round($value * 0.4, 2);
        $result[$count - 1]['credit'] = 0.4;
        $result[$count - 1]['value'] = round($value * 0.4, 2);

        // Middle touchpoints share remaining 20%
        $middleCount = $count - 2;
        $middleCreditEach = 0.2 / $middleCount;
        $middleValueEach = ($value * 0.2) / $middleCount;

        for ($i = 1; $i < $count - 1; $i++) {
            $result[$i]['credit'] = round($middleCreditEach, 6);
            $result[$i]['value'] = round($middleValueEach, 2);
        }

        return $result;
    }

    /**
     * Aggregate attribution data by channel for a collection of conversions.
     *
     * @param array $conversions Array of AttributionConversion records (as arrays)
     * @param string $model Attribution model to use
     * @return array Keyed by channel string with total credit and value
     */
    public function aggregateByChannel(array $conversions, string $model = 'linear'): array
    {
        $channels = [];

        foreach ($conversions as $conversion) {
            $attribution = $conversion['attributed_to'][$model] ?? [];
            foreach ($attribution as $tp) {
                $channel = $this->getChannel($tp);
                if (!isset($channels[$channel])) {
                    $channels[$channel] = ['channel' => $channel, 'conversions' => 0, 'value' => 0, 'credit' => 0];
                }
                $channels[$channel]['credit'] += $tp['credit'] ?? 0;
                $channels[$channel]['value'] += $tp['value'] ?? 0;
                // Count a conversion fractionally by credit
                $channels[$channel]['conversions'] += $tp['credit'] ?? 0;
            }
        }

        // Sort by value descending
        usort($channels, fn($a, $b) => $b['value'] <=> $a['value']);

        return array_values($channels);
    }

    /**
     * Initialize result array with touchpoint metadata (zero credit).
     */
    protected function initResult(array $touchpoints): array
    {
        return array_map(function ($tp) {
            return [
                'utm_source' => $tp['utm_source'] ?? null,
                'utm_medium' => $tp['utm_medium'] ?? null,
                'utm_campaign' => $tp['utm_campaign'] ?? null,
                'utm_content' => $tp['utm_content'] ?? null,
                'utm_term' => $tp['utm_term'] ?? null,
                'timestamp' => $tp['touched_at'] ?? $tp['timestamp'] ?? null,
                'credit' => 0.0,
                'value' => 0.0,
            ];
        }, $touchpoints);
    }

    /**
     * Extract timestamp from touchpoint as Unix seconds.
     */
    protected function getTimestamp(array $tp): int
    {
        $ts = $tp['touched_at'] ?? $tp['timestamp'] ?? null;
        if (!$ts) return time();
        return is_numeric($ts) ? (int) $ts : (strtotime($ts) ?: time());
    }

    /**
     * Build channel label from a touchpoint's UTM params.
     */
    protected function getChannel(array $tp): string
    {
        $source = $tp['utm_source'] ?? 'direct';
        $medium = $tp['utm_medium'] ?? 'none';
        return ucfirst($source) . ' / ' . ucfirst($medium);
    }
}
