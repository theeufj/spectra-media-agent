<?php

namespace App\Services\Agents\Optimization;

/**
 * Calculates data quality scores and attaches confidence scores to
 * AI-generated recommendations so the applier knows what to act on.
 *
 * Type aliases normalise Gemini's verbose output names to internal categories.
 * Per-type thresholds allow low-risk actions (negatives, extensions) to
 * auto-apply at lower confidence than high-impact actions (bidding, targeting).
 */
class RecommendationScorer
{
    // Gemini returns verbose type names — map them to internal categories
    private const TYPE_ALIASES = [
        'ADJUST_BUDGET' => 'BUDGET',
        'ADJUST_BID' => 'BIDDING',
        'CHANGE_BID_STRATEGY' => 'BIDDING',
        'ADJUST_TARGET_CPA' => 'BIDDING',
        'ADJUST_TARGET_ROAS' => 'BIDDING',
        'NEGATIVE_KEYWORD_ADDITION' => 'NEGATIVE_KEYWORDS',
        'NEGATIVE_KEYWORDS' => 'NEGATIVE_KEYWORDS',
        'SEARCH_TERM_REVIEW' => 'NEGATIVE_KEYWORDS',
        'AD_COPY_OPTIMIZATION' => 'ADS',
        'AD_COPY' => 'ADS',
        'ADS' => 'ADS',
        'AD_EXTENSIONS' => 'AD_EXTENSIONS',
        'BIDDING_STRATEGY_CHANGE' => 'BIDDING',
        'BIDDING_STRATEGY_REVIEW' => 'BIDDING',
        'BIDDING_STRATEGY_ADJUSTMENT' => 'BIDDING',
        'BIDDING' => 'BIDDING',
        'BUDGET_ADJUSTMENT' => 'BUDGET',
        'SET_DAILY_BUDGET' => 'BUDGET',
        'BUDGET' => 'BUDGET',
        'TARGETING_REFINEMENT' => 'TARGETING',
        'TARGETING' => 'TARGETING',
        'KEYWORDS' => 'KEYWORDS',
        'KEYWORD' => 'KEYWORDS',
        'KEYWORD_EXPANSION' => 'KEYWORDS',
        'AUDIENCE' => 'AUDIENCE',
        'SCHEDULE' => 'SCHEDULE',
        'NETWORK_SETTINGS' => 'NETWORK_SETTINGS',
        'DISABLE_SEARCH_PARTNERS' => 'NETWORK_SETTINGS',
        'SEARCH_PARTNERS' => 'NETWORK_SETTINGS',
        'DISABLE_DISPLAY_EXPANSION' => 'NETWORK_SETTINGS',
        'DISPLAY_EXPANSION' => 'NETWORK_SETTINGS',
        'DISABLE_CONTENT_NETWORK' => 'NETWORK_SETTINGS',
        'CONVERSION_TRACKING_AUDIT' => 'AUDIT',
    ];

    // Per-type auto-apply confidence thresholds.
    // Lower = more aggressive. Informational types set to >1 so they never auto-apply.
    private const TYPE_THRESHOLDS = [
        'NEGATIVE_KEYWORDS' => 0.60,
        'AD_EXTENSIONS' => 0.65,
        'KEYWORDS' => 0.70,
        'ADS' => 0.75,
        'BUDGET' => 0.75,
        'SCHEDULE' => 0.78,
        'TARGETING' => 0.80,
        'BIDDING' => 0.85,
        'AUDIENCE' => 0.85,
        // Disabling Search Partners / Display expansion on a Search campaign is a safe,
        // reversible, high-value change — auto-apply at a moderate confidence.
        'NETWORK_SETTINGS' => 0.70,
        'AUDIT' => 1.01,
    ];

    private float $globalAutoApplyThreshold;

    private float $reviewThreshold;

    private int $minImpressions;

    private int $minClicks;

    private int $minConversions;

    public function __construct()
    {
        $cfg = config('optimization.campaign_optimization', []);
        $this->globalAutoApplyThreshold = $cfg['auto_apply_confidence'] ?? 0.90;
        $this->reviewThreshold = $cfg['review_confidence'] ?? 0.55;
        $this->minImpressions = $cfg['min_impressions_for_bid'] ?? 1000;
        $this->minClicks = $cfg['min_clicks_for_ctr'] ?? 100;
        $this->minConversions = $cfg['min_conversions_for_cpa'] ?? 15;
    }

    public function assessDataQuality(array $metrics): array
    {
        $score = 100;
        $notes = [];

        $impressions = $metrics['impressions'] ?? 0;
        $clicks = $metrics['clicks'] ?? 0;
        $conversions = $metrics['conversions'] ?? 0;

        if ($impressions < 1000) {
            $score -= 30;
            $notes[] = 'Low impression volume reduces recommendation confidence';
        } elseif ($impressions < 5000) {
            $score -= 15;
            $notes[] = 'Moderate impression volume — more data would improve accuracy';
        }

        if ($clicks < 50) {
            $score -= 25;
            $notes[] = 'Limited click data affects CTR analysis reliability';
        } elseif ($clicks < 200) {
            $score -= 10;
            $notes[] = 'Click volume is acceptable but could be higher';
        }

        if ($conversions < 5) {
            $score -= 25;
            $notes[] = 'Insufficient conversion data for CPA/ROAS recommendations';
        } elseif ($conversions < 15) {
            $score -= 10;
            $notes[] = 'Limited conversion data — conversion-based recommendations less reliable';
        }

        return [
            'score' => max(0, $score),
            'notes' => $notes,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
        ];
    }

    public function enhance(array $recommendations, array $metrics, ?array $historical, array $dataQuality): array
    {
        if (! isset($recommendations['recommendations'])) {
            return $recommendations;
        }

        foreach ($recommendations['recommendations'] as &$rec) {
            $confidence = $this->calculateConfidence($rec, $metrics, $historical, $dataQuality);
            $threshold = $this->autoApplyThreshold($rec['type'] ?? '');
            $blockers = self::autoApplyBlockers($rec);

            $rec['confidence_score'] = $confidence['score'];
            $rec['confidence_factors'] = $confidence['factors'];
            $rec['auto_apply_eligible'] = $confidence['score'] >= $threshold && $blockers === [];
            $rec['requires_review'] = $confidence['score'] < $this->reviewThreshold;

            if ($blockers !== []) {
                $rec['auto_apply_blocked_by'] = $blockers;
            }
        }

        return $recommendations;
    }

    public function categorize(array $recommendations): array
    {
        $categorized = ['auto_apply' => [], 'recommended' => [], 'review_required' => []];

        foreach ($recommendations['recommendations'] ?? [] as $rec) {
            $score = $rec['confidence_score'] ?? 0;
            $threshold = $this->autoApplyThreshold($rec['type'] ?? '');

            if ($score >= $threshold && self::autoApplyBlockers($rec) === []) {
                $categorized['auto_apply'][] = $rec;
            } elseif ($score >= $this->reviewThreshold) {
                $categorized['recommended'][] = $rec;
            } else {
                $categorized['review_required'][] = $rec;
            }
        }

        return $categorized;
    }

    /**
     * The internal category a raw model-supplied type maps to.
     *
     * Shared with RecommendationApplier: the applier used to `match` on the raw
     * string, so BUDGET_ADJUSTMENT was scored against BUDGET's threshold, filed
     * as auto_apply, and then fell to "Auto-apply not yet supported" — recorded
     * 'failed' with nothing to review. One table, one answer.
     */
    public static function canonicalType(string $rawType): string
    {
        $upper = strtoupper(trim($rawType));

        return self::TYPE_ALIASES[$upper] ?? $upper;
    }

    /**
     * Fields the applier needs before it can execute this recommendation, that
     * the model failed to supply.
     *
     * A recommendation missing one of these cannot be auto-applied: the applier
     * returns applied:false and OptimizeCampaigns files it as 'failed' with
     * requires_approval = false, so it is invisible as both an error and a review
     * item. Blocking it here demotes it to the review queue instead, where a
     * human can supply what the model left out.
     *
     * @return list<string>
     */
    public static function autoApplyBlockers(array $rec): array
    {
        $type = self::canonicalType($rec['type'] ?? '');
        $subType = strtolower((string) ($rec['sub_type'] ?? ''));
        $missing = [];

        switch ($type) {
            case 'BUDGET':
                if (! self::present($rec, 'suggested_value')) {
                    $missing[] = 'suggested_value';
                }
                break;

            case 'NEGATIVE_KEYWORDS':
                if (! self::present($rec, 'keywords', 'negative_keywords', 'parameters.keywords', 'params.keywords')) {
                    $missing[] = 'keywords';
                }
                break;

            case 'KEYWORDS':
                $missing = self::keywordBlockers($rec);
                break;

            case 'BIDDING':
                // Only a keyword-level CPC change is auto-appliable; every other
                // bidding change is a manual review by design.
                if ($subType !== 'keyword_cpc') {
                    $missing[] = 'sub_type (keyword_cpc)';
                    break;
                }
                if (! self::present($rec, 'keyword_resource')) {
                    $missing[] = 'keyword_resource';
                }
                if (! self::present($rec, 'suggested_value')) {
                    $missing[] = 'suggested_value';
                }
                break;

            case 'TARGETING':
                if ($subType === 'device' && ! self::present($rec, 'device_type')) {
                    $missing[] = 'device_type';
                } elseif ($subType === 'location' && ! self::present($rec, 'geo_target_constant')) {
                    $missing[] = 'geo_target_constant';
                } elseif (! in_array($subType, ['device', 'location'], true)) {
                    $missing[] = 'sub_type (device|location)';
                }
                if (! self::present($rec, 'suggested_value')) {
                    $missing[] = 'suggested_value';
                }
                break;

            case 'AD_EXTENSIONS':
                $missing = self::extensionBlockers($rec, $subType);
                break;

            case 'SCHEDULE':
            case 'AUDIENCE':
            case 'NETWORK_SETTINGS':
                break; // the applier defaults every field these need

            default:
                // The applier has no arm for this type, so auto-applying it can
                // only ever record a 'failed' recommendation nobody sees.
                $missing[] = 'auto-apply is not supported for '.($type ?: 'an unnamed type');
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function keywordBlockers(array $rec): array
    {
        $missing = [];

        // Microsoft can only add a keyword to an ad group; Google acts on an
        // existing criterion, so the two shapes need different fields.
        if (self::present($rec, 'ad_group_id')) {
            if (! self::present($rec, 'keyword_text', 'text')) {
                $missing[] = 'keyword_text';
            }

            return $missing;
        }

        if (! self::present($rec, 'criterion_resource_name')) {
            $missing[] = 'criterion_resource_name';
        }

        $action = strtolower((string) ($rec['direction'] ?? $rec['action'] ?? ''));

        if (! in_array($action, ['increase', 'decrease', 'pause', 'enable', 'remove'], true)) {
            $missing[] = 'direction';
        }

        if (in_array($action, ['increase', 'decrease'], true) && ! self::present($rec, 'suggested_value')) {
            $missing[] = 'suggested_value';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function extensionBlockers(array $rec, string $subType): array
    {
        $missing = [];

        switch ($subType) {
            case 'structured_snippet':
                if (! self::present($rec, 'values', 'items')) {
                    $missing[] = 'values';
                }
                break;
            case 'call':
                if (! self::present($rec, 'phone_number')) {
                    $missing[] = 'phone_number';
                }
                break;
            case 'price':
                if (! self::present($rec, 'offerings')) {
                    $missing[] = 'offerings';
                }
                break;
            case 'promotion':
                if (! self::present($rec, 'promotion_target')) {
                    $missing[] = 'promotion_target';
                }
                if (! self::present($rec, 'promotion_data')) {
                    $missing[] = 'promotion_data';
                }
                break;
            default:
                $missing[] = 'sub_type (structured_snippet|call|price|promotion)';
        }

        return $missing;
    }

    /**
     * True when any of the given keys holds a usable value. Dotted keys resolve
     * into nested arrays, so `parameters.keywords` works.
     */
    private static function present(array $rec, string ...$keys): bool
    {
        foreach ($keys as $key) {
            $value = data_get($rec, $key);

            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    private function autoApplyThreshold(string $rawType): float
    {
        return self::TYPE_THRESHOLDS[self::canonicalType($rawType)] ?? $this->globalAutoApplyThreshold;
    }

    private function calculateConfidence(array $rec, array $metrics, ?array $historical, array $dataQuality): array
    {
        $factors = [];
        $baseScore = 0.7;
        $type = self::canonicalType($rec['type'] ?? '');
        $impact = strtoupper($rec['impact'] ?? 'MEDIUM');

        $dataQualityFactor = $dataQuality['score'] / 100;
        $typeConfidence = $this->typeConfidence($type, $metrics);
        $historicalConfidence = $this->historicalConfidence($rec, $historical);
        $impactFactor = match ($impact) {
            'HIGH' => 0.9,
            'MEDIUM' => 0.7,
            'LOW' => 0.5,
            default => 0.6,
        };

        $factors['data_quality'] = round($dataQualityFactor, 2);
        $factors['type_confidence'] = $typeConfidence;
        $factors['historical_consistency'] = $historicalConfidence;
        $factors['impact_significance'] = $impactFactor;

        $score = ($dataQualityFactor * 0.30)
               + ($typeConfidence * 0.25)
               + ($historicalConfidence * 0.25)
               + ($impactFactor * 0.20);

        return [
            'score' => round(min(1.0, $baseScore * $score / 0.7), 2),
            'factors' => $factors,
        ];
    }

    private function typeConfidence(string $type, array $metrics): float
    {
        $impressions = $metrics['impressions'] ?? 0;
        $clicks = $metrics['clicks'] ?? 0;
        $conversions = $metrics['conversions'] ?? 0;

        return match ($type) {
            'NEGATIVE_KEYWORDS' => $impressions >= 500 ? 0.90 : 0.70,
            'BUDGET' => $impressions >= $this->minImpressions ? 0.85 : 0.50,
            'BIDDING' => $conversions >= $this->minConversions ? 0.80 : 0.40,
            'KEYWORDS' => $clicks >= $this->minClicks ? 0.75 : 0.45,
            'AD_EXTENSIONS' => $impressions >= $this->minImpressions ? 0.85 : 0.65,
            'SCHEDULE' => $clicks >= 500 ? 0.75 : 0.40,
            'AUDIENCE' => $conversions >= 30 ? 0.80 : 0.30,
            'ADS' => $clicks >= 100 ? 0.70 : 0.45,
            'TARGETING' => $impressions >= 5000 ? 0.70 : 0.35,
            // Confident once there's enough volume to show out-of-network waste.
            'NETWORK_SETTINGS' => $impressions >= 5000 ? 0.85 : 0.60,
            'AUDIT' => 0.0,
            default => 0.50,
        };
    }

    private function historicalConfidence(array $rec, ?array $historical): float
    {
        if (! $historical) {
            return 0.5;
        }

        $type = $rec['type'] ?? '';
        $direction = $rec['direction'] ?? 'increase';
        $trend = $historical[strtolower($type).'_trend'] ?? null;

        if ($trend === null) {
            return 0.6;
        }

        $aligns = ($direction === 'increase' && $trend > 0)
               || ($direction === 'decrease' && $trend < 0);

        return $aligns ? 0.85 : 0.50;
    }
}
