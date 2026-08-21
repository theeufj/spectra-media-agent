<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

/**
 * The reporting window every usage metric is measured over.
 *
 * This exists because the same three lines — `now()->subDays($n)->startOfDay()`
 * and the matching previous-period bounds — were about to be open-coded in five
 * services the way AiCostController@index open-codes them today. Two things go
 * wrong when that happens:
 *
 *  1. The previous-period bounds drift out of step with the current ones, and a
 *     trend percentage compares 30 days against 29.
 *  2. Somebody caches a result under a key that does not mention the period, so
 *     switching from 30 days to 7 silently returns the 30-day numbers. That bug
 *     is invisible — the page looks fine, it is just wrong — which is why the
 *     cache key is derived from this object and nowhere else.
 *
 * Immutable: a metric cannot shift the window out from under the metric next to it.
 */
final class UsagePeriod
{
    public const DEFAULT = '30';

    /**
     * Selectable windows. Deliberately capped at 90 days: the AI-cost queries
     * scan ai_costs, which is one row per LLM call and unpruned by policy
     * (see routes/console.php). Anything longer is served from the nightly
     * rollup, not from a live scan.
     */
    public const ALLOWED = ['7', '30', '90', 'mtd'];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CarbonImmutable $since,
        public readonly CarbonImmutable $until,
        public readonly CarbonImmutable $previousSince,
        public readonly CarbonImmutable $previousUntil,
    ) {}

    /**
     * Resolve a request value, falling back to the default rather than throwing.
     * An unrecognised ?period= should show the dashboard, not a 500.
     */
    public static function fromRequest(?string $value, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $key = in_array($value, self::ALLOWED, true) ? $value : self::DEFAULT;

        if ($key === 'mtd') {
            $since = $now->startOfMonth();
            $previousSince = $since->subMonthNoOverflow();

            return new self(
                key: 'mtd',
                label: 'Month to date',
                since: $since,
                until: $now,
                previousSince: $previousSince,
                // Same elapsed span in the prior month, so "month to date" is
                // compared against the equivalent slice — not a whole month,
                // which would make every early-month figure look catastrophic.
                previousUntil: $previousSince->addDays($since->diffInDays($now)),
            );
        }

        $days = (int) $key;
        $since = $now->subDays($days)->startOfDay();

        return new self(
            key: $key,
            label: "Last {$days} days",
            since: $since,
            until: $now,
            previousSince: $now->subDays($days * 2)->startOfDay(),
            previousUntil: $since,
        );
    }

    /**
     * Elapsed days in the window. Used to normalise per-day rates so a 7-day
     * and a 90-day view are comparable.
     */
    public function days(): int
    {
        return max(1, (int) $this->since->diffInDays($this->until));
    }

    /**
     * The cache-key fragment. Every cached usage query MUST include this —
     * a key without it serves one period's numbers under another's label.
     */
    public function cacheSuffix(): string
    {
        return $this->key;
    }

    /**
     * Percentage change against the equivalent previous window.
     *
     * Returns null rather than 0 when there is no prior activity to compare
     * against: "no baseline" and "flat" are different statements, and the UI
     * renders the first as nothing at all instead of a misleading 0%.
     */
    public static function trend(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array{key: string, label: string, since: string, until: string, days: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'since' => $this->since->toDateString(),
            'until' => $this->until->toDateString(),
            'days' => $this->days(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => '7', 'label' => '7 days'],
            ['value' => '30', 'label' => '30 days'],
            ['value' => '90', 'label' => '90 days'],
            ['value' => 'mtd', 'label' => 'Month to date'],
        ];
    }
}
