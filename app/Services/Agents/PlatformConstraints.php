<?php

namespace App\Services\Agents;

/**
 * Registry of hard platform API constraints for Facebook and Google Ads.
 *
 * Each constraint has three capabilities:
 *   1. validate($plan) — deterministic check, returns a violation message or null
 *   2. fix($plan)      — auto-corrects the plan where possible, returns corrected plan
 *   3. promptRule()    — returns a one-line rule string for injection into AI prompts
 *
 * Usage:
 *   PlatformConstraints::validate('facebook', $plan->rawPlan)  → ['violations']
 *   PlatformConstraints::autoFix('facebook', $plan->rawPlan)   → corrected plan array
 *   PlatformConstraints::asPromptRules('facebook')             → rules text block
 */
class PlatformConstraints
{
    // -------------------------------------------------------------------------
    // Facebook constraints
    // -------------------------------------------------------------------------

    private static function facebookConstraints(): array
    {
        return [
            // ── Advantage+ audience: age_max must be >= 65 (API error 1870189) ──────
            [
                'id'       => 'fb_age_max_advantage_plus',
                'fixable'  => true,
                'prompt'   => 'age_max must always be 65 — Facebook rejects lower values when Advantage+ audience is active (API error 1870189). Use age_min to restrict the younger bound.',
                'validate' => function (array $plan): ?string {
                    $ageMax = self::dig($plan, ['targeting_strategy', 'age_max'])
                        ?? self::dig($plan, ['creative_strategy', 'targeting', 'age_max']);
                    if ($ageMax !== null && (int) $ageMax < 65) {
                        return "age_max is {$ageMax} but must be >= 65 when using Advantage+ audience.";
                    }
                    return null;
                },
                'fix'      => function (array $plan): array {
                    if (isset($plan['targeting_strategy']['age_max']) && (int) $plan['targeting_strategy']['age_max'] < 65) {
                        $plan['targeting_strategy']['age_max'] = 65;
                    }
                    if (isset($plan['creative_strategy']['targeting']['age_max']) && (int) $plan['creative_strategy']['targeting']['age_max'] < 65) {
                        $plan['creative_strategy']['targeting']['age_max'] = 65;
                    }
                    return $plan;
                },
            ],

            // ── Valid campaign objective (API v18+ only accepts OUTCOME_* values) ──
            [
                'id'       => 'fb_valid_objective',
                'fixable'  => false,
                'prompt'   => 'campaign_structure.objective must be one of: OUTCOME_LEADS, OUTCOME_SALES, OUTCOME_AWARENESS, OUTCOME_ENGAGEMENT, OUTCOME_APP_PROMOTION, OUTCOME_TRAFFIC. Legacy values (CONVERSIONS, LINK_CLICKS, REACH) are rejected by the API.',
                'validate' => function (array $plan): ?string {
                    $valid = ['OUTCOME_LEADS', 'OUTCOME_SALES', 'OUTCOME_AWARENESS', 'OUTCOME_ENGAGEMENT', 'OUTCOME_APP_PROMOTION', 'OUTCOME_TRAFFIC'];
                    $objective = self::dig($plan, ['campaign_structure', 'objective']) ?? '';
                    if ($objective && !in_array($objective, $valid)) {
                        return "Objective '{$objective}' is a legacy value rejected by the API. Use one of: " . implode(', ', $valid);
                    }
                    return null;
                },
            ],

            // ── Optimization goal must align with objective ───────────────────────
            [
                'id'       => 'fb_optimization_goal_alignment',
                'fixable'  => true,
                'prompt'   => 'optimization_goal must align with objective: OUTCOME_LEADS → LEAD_GENERATION, OUTCOME_SALES → OFFSITE_CONVERSIONS, OUTCOME_TRAFFIC → LANDING_PAGE_VIEWS, OUTCOME_AWARENESS → REACH.',
                'validate' => function (array $plan): ?string {
                    $map = [
                        'OUTCOME_LEADS'   => 'LEAD_GENERATION',
                        'OUTCOME_SALES'   => 'OFFSITE_CONVERSIONS',
                        'OUTCOME_TRAFFIC' => 'LANDING_PAGE_VIEWS',
                        'OUTCOME_AWARENESS' => 'REACH',
                    ];
                    $objective = self::dig($plan, ['campaign_structure', 'objective']) ?? '';
                    $goal      = self::dig($plan, ['campaign_structure', 'optimization_goal']) ?? '';
                    if (isset($map[$objective]) && $goal && $goal !== $map[$objective]) {
                        return "optimization_goal '{$goal}' does not match objective '{$objective}' — expected '{$map[$objective]}'.";
                    }
                    return null;
                },
                'fix' => function (array $plan): array {
                    $map = [
                        'OUTCOME_LEADS'   => 'LEAD_GENERATION',
                        'OUTCOME_SALES'   => 'OFFSITE_CONVERSIONS',
                        'OUTCOME_TRAFFIC' => 'LANDING_PAGE_VIEWS',
                        'OUTCOME_AWARENESS' => 'REACH',
                    ];
                    $objective = self::dig($plan, ['campaign_structure', 'objective']) ?? '';
                    if (isset($map[$objective])) {
                        $plan['campaign_structure']['optimization_goal'] = $map[$objective];
                    }
                    return $plan;
                },
            ],

            // ── Primary text length (125-char hard cap, 20-char minimum) ─────────
            [
                'id'       => 'fb_primary_text_length',
                'fixable'  => false,
                'prompt'   => 'creative_strategy.primary_text must be between 20 and 125 characters. Empty or too-short copy will be rejected.',
                'validate' => function (array $plan): ?string {
                    $text = self::dig($plan, ['creative_strategy', 'primary_text']) ?? '';
                    $len  = mb_strlen(trim($text));
                    if ($len > 0 && $len < 20) {
                        return "primary_text is {$len} chars — minimum is 20.";
                    }
                    if ($len > 125) {
                        return "primary_text is {$len} chars — maximum is 125.";
                    }
                    return null;
                },
            ],

            // ── Headline length (40-char hard cap) ───────────────────────────────
            [
                'id'       => 'fb_headline_length',
                'fixable'  => false,
                'prompt'   => 'creative_strategy.headline must be 40 characters or fewer.',
                'validate' => function (array $plan): ?string {
                    $headline = self::dig($plan, ['creative_strategy', 'headline']) ?? '';
                    if (mb_strlen($headline) > 40) {
                        return "headline is " . mb_strlen($headline) . " chars — maximum is 40.";
                    }
                    return null;
                },
            ],

            // ── Geographic targeting required ────────────────────────────────────
            [
                'id'       => 'fb_geo_targeting_required',
                'fixable'  => true,
                'prompt'   => 'targeting_strategy.geo_locations.countries must contain at least one country. Default to ["US", "CA", "AU", "GB"] if not specified.',
                'validate' => function (array $plan): ?string {
                    $countries = self::dig($plan, ['targeting_strategy', 'geo_locations', 'countries']) ?? [];
                    if (empty($countries)) {
                        return 'targeting_strategy.geo_locations.countries is empty — at least one country is required.';
                    }
                    return null;
                },
                'fix' => function (array $plan): array {
                    $countries = self::dig($plan, ['targeting_strategy', 'geo_locations', 'countries']) ?? [];
                    if (empty($countries)) {
                        $plan['targeting_strategy']['geo_locations']['countries'] = ['US', 'CA', 'AU', 'GB'];
                    }
                    return $plan;
                },
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Google Ads constraints
    // -------------------------------------------------------------------------

    private static function googleConstraints(): array
    {
        return [
            // ── RSA headlines: max 30 chars each ─────────────────────────────────
            [
                'id'       => 'goog_headline_length',
                'fixable'  => false,
                'prompt'   => 'RSA headlines must be 30 characters or fewer each. Google will reject longer headlines.',
                'validate' => function (array $plan): ?string {
                    $ads = self::dig($plan, ['creative_strategy', 'ads']) ?? [];
                    foreach ((array) $ads as $ad) {
                        foreach ((array) ($ad['headlines'] ?? []) as $headline) {
                            if (mb_strlen($headline) > 30) {
                                return "RSA headline exceeds 30 chars: \"{$headline}\" (" . mb_strlen($headline) . " chars)";
                            }
                        }
                    }
                    return null;
                },
            ],

            // ── RSA descriptions: max 90 chars each ──────────────────────────────
            [
                'id'       => 'goog_description_length',
                'fixable'  => false,
                'prompt'   => 'RSA descriptions must be 90 characters or fewer each.',
                'validate' => function (array $plan): ?string {
                    $ads = self::dig($plan, ['creative_strategy', 'ads']) ?? [];
                    foreach ((array) $ads as $ad) {
                        foreach ((array) ($ad['descriptions'] ?? []) as $desc) {
                            if (mb_strlen($desc) > 90) {
                                return "RSA description exceeds 90 chars: \"{$desc}\" (" . mb_strlen($desc) . " chars)";
                            }
                        }
                    }
                    return null;
                },
            ],

            // ── Valid campaign type ───────────────────────────────────────────────
            [
                'id'       => 'goog_valid_campaign_type',
                'fixable'  => false,
                'prompt'   => 'campaign_structure.type must be one of: search, performance_max, display, video, demand_gen, shopping, local_services. Never use "smart" or "universal".',
                'validate' => function (array $plan): ?string {
                    $valid = ['search', 'performance_max', 'display', 'video', 'demand_gen', 'shopping', 'local_services'];
                    $type  = self::dig($plan, ['campaign_structure', 'type']) ?? '';
                    if ($type && !in_array(strtolower($type), $valid)) {
                        return "campaign_structure.type '{$type}' is not a valid Google Ads campaign type.";
                    }
                    return null;
                },
            ],

            // ── Geographic targeting required ────────────────────────────────────
            [
                'id'       => 'goog_geo_targeting_required',
                'fixable'  => true,
                'prompt'   => 'campaign_structure.locations must contain at least one location. Default to ["United States", "Canada", "Australia", "United Kingdom"] if not specified.',
                'validate' => function (array $plan): ?string {
                    $locations = self::dig($plan, ['campaign_structure', 'locations']) ?? [];
                    if (empty($locations)) {
                        return 'campaign_structure.locations is empty — geographic targeting is required.';
                    }
                    return null;
                },
                'fix' => function (array $plan): array {
                    $locations = self::dig($plan, ['campaign_structure', 'locations']) ?? [];
                    if (empty($locations)) {
                        $plan['campaign_structure']['locations'] = ['United States', 'Canada', 'Australia', 'United Kingdom'];
                    }
                    return $plan;
                },
            ],

            // ── RSA headline count (min 5, max 15) ───────────────────────────────
            [
                'id'       => 'goog_headline_count',
                'fixable'  => false,
                'prompt'   => 'Each RSA ad must have between 5 and 15 headlines. Fewer than 5 reduces ad strength; more than 15 are rejected.',
                'validate' => function (array $plan): ?string {
                    $ads = self::dig($plan, ['creative_strategy', 'ads']) ?? [];
                    foreach ((array) $ads as $ad) {
                        $count = count((array) ($ad['headlines'] ?? []));
                        if ($count > 0 && $count < 5) {
                            return "RSA has {$count} headline(s) — minimum is 5.";
                        }
                        if ($count > 15) {
                            return "RSA has {$count} headlines — maximum is 15.";
                        }
                    }
                    return null;
                },
            ],

            // ── RSA description count (min 2, max 4) ─────────────────────────────
            [
                'id'       => 'goog_description_count',
                'fixable'  => false,
                'prompt'   => 'Each RSA ad must have between 2 and 4 descriptions.',
                'validate' => function (array $plan): ?string {
                    $ads = self::dig($plan, ['creative_strategy', 'ads']) ?? [];
                    foreach ((array) $ads as $ad) {
                        $count = count((array) ($ad['descriptions'] ?? []));
                        if ($count > 0 && $count < 2) {
                            return "RSA has {$count} description(s) — minimum is 2.";
                        }
                        if ($count > 4) {
                            return "RSA has {$count} descriptions — maximum is 4.";
                        }
                    }
                    return null;
                },
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run deterministic validation against all constraints for a platform.
     * Returns an array of violation messages (empty = all clear).
     */
    public static function validate(string $platform, array $plan): array
    {
        $violations = [];
        foreach (self::constraints($platform) as $constraint) {
            $violation = ($constraint['validate'])($plan);
            if ($violation !== null) {
                $violations[$constraint['id']] = $violation;
            }
        }
        return $violations;
    }

    /**
     * Apply all auto-fixable constraints to the plan.
     * Returns the corrected plan and a list of applied fix IDs.
     */
    public static function autoFix(string $platform, array $plan): array
    {
        $applied = [];
        foreach (self::constraints($platform) as $constraint) {
            if (!($constraint['fixable'] ?? false)) {
                continue;
            }
            // Only fix if the constraint is actually violated
            if (($constraint['validate'])($plan) !== null) {
                $plan    = ($constraint['fix'])($plan);
                $applied[] = $constraint['id'];
            }
        }
        return ['plan' => $plan, 'fixes_applied' => $applied];
    }

    /**
     * Return a formatted string of constraint rules suitable for AI prompt injection.
     */
    public static function asPromptRules(string $platform): string
    {
        $lines = [];
        foreach (self::constraints($platform) as $i => $constraint) {
            $n = $i + 1;
            $fix = ($constraint['fixable'] ?? false) ? ' [auto-fixable]' : '';
            $lines[] = "{$n}. {$constraint['prompt']}{$fix}";
        }

        $platformLabel = ucfirst($platform);
        return "## {$platformLabel} API Hard Constraints (enforce strictly — violations cause deployment failures)\n\n"
            . implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function constraints(string $platform): array
    {
        return match (strtolower($platform)) {
            'facebook', 'meta' => self::facebookConstraints(),
            'google'           => self::googleConstraints(),
            default            => [],
        };
    }

    /**
     * Safely traverse a nested array by key path without triggering undefined-index warnings.
     */
    private static function dig(array $data, array $keys): mixed
    {
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
}
