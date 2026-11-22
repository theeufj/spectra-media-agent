<?php

namespace App\Services\Analytics;

use App\Services\SignupFunnel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SignupFunnelReport
{
    /**
     * These denominators describe observable routes. A visitor can skip the preview or
     * complete registration without the browser reporting every intermediate step.
     */
    private const TRANSITIONS = [
        'landing_view' => null,
        'landing_visible' => 'landing_view',
        'signup_cta_clicked' => 'landing_visible',
        'preview_started' => 'landing_visible',
        'preview_submitted' => 'preview_started',
        'preview_succeeded' => 'preview_submitted',
        'signup_viewed' => null,
        'signup_started' => 'signup_viewed',
        'signup_submitted' => 'signup_started',
        'signup_completed' => 'signup_submitted',
        'email_verified' => 'signup_completed',
    ];

    public function summary(array $filters = []): array
    {
        $asOf = CarbonImmutable::now('UTC');
        $end = isset($filters['funnel_to']) ? CarbonImmutable::parse($filters['funnel_to'], 'UTC') : $asOf;
        $start = isset($filters['funnel_from']) ? CarbonImmutable::parse($filters['funnel_from'], 'UTC') : $end->subDays(29);
        $source = $filters['funnel_source'] ?? null;
        $campaign = $filters['funnel_campaign'] ?? null;
        $entrySurface = $filters['funnel_entry_surface'] ?? null;

        $cohort = DB::table('signup_funnel_journeys')
            ->where('started_at', '>=', $start->startOfDay())
            ->where('started_at', '<', $end->startOfDay()->addDay())
            ->where('started_at', '<=', $asOf)
            ->when($source, fn ($query) => $query->where('source', $source))
            ->when($campaign, fn ($query) => $query->where('campaign_key', $campaign))
            ->select('id');
        if ($entrySurface !== null) {
            $cohort->where('entry_surface', $entrySurface === 'unspecified' ? null : $entrySurface);
        }

        $stages = DB::table('signup_funnel_stages as s')->where('s.occurred_at', '<=', $asOf);
        $journeys = DB::query()->fromSub($cohort, 'j')
            ->leftJoinSub(clone $stages, 's', 's.journey_id', '=', 'j.id')
            ->groupBy('j.id')->select('j.id');
        $totals = ['COUNT(*) AS journeys'];
        foreach (self::TRANSITIONS as $stage => $baseline) {
            $journeys->selectRaw("MAX(CASE WHEN s.stage = ? THEN 1 ELSE 0 END) AS {$stage}", [$stage]);
            $totals[] = "COUNT(*) FILTER (WHERE {$stage} = 1) AS {$stage}";
            if ($baseline !== null) {
                $totals[] = "COUNT(*) FILTER (WHERE {$baseline} = 1 AND {$stage} = 1) AS paired_{$stage}";
            }
        }
        $journeys->selectRaw("MIN(CASE WHEN s.stage = 'preview_succeeded' THEN s.occurred_at END) AS preview_at")
            ->selectRaw("MIN(CASE WHEN s.stage = 'signup_completed' THEN s.occurred_at END) AS signup_at");
        $totals[] = 'COUNT(*) FILTER (WHERE preview_at <= signup_at) AS signups_with_preview';
        $counts = (array) DB::query()->fromSub($journeys, 'observed')->selectRaw(implode(', ', $totals))->first();
        $steps = [];
        foreach (self::TRANSITIONS as $stage => $baseline) {
            $base = (int) $counts[$baseline ?? 'journeys'];
            $reached = (int) $counts[$stage];
            $paired = $baseline === null ? $reached : (int) $counts['paired_'.$stage];
            $steps[$stage] = [
                'reached' => $reached,
                'baseline' => $baseline,
                'baseline_count' => $base,
                'both' => $paired,
                'missing' => $base - $paired,
                'dropoff_percent' => $base > 0 ? round(100 * ($base - $paired) / $base, 1) : null,
            ];
        }

        $reasons = [...SignupFunnel::CLIENT_REASONS, 'email_taken', 'captcha'];
        $placeholders = implode(', ', array_fill(0, count($reasons), '?'));
        $failures = $stages->joinSub($cohort, 'j', 'j.id', '=', 's.journey_id')
            ->whereIn('s.stage', ['preview_failed', 'signup_failed'])
            ->select('s.stage')
            ->selectRaw("CASE WHEN s.reason IN ({$placeholders}) THEN s.reason ELSE 'unknown' END AS safe_reason", $reasons)
            ->selectRaw('COUNT(DISTINCT s.journey_id) AS journeys')
            ->groupBy('s.stage', 'safe_reason')->orderBy('s.stage')->orderBy('safe_reason')
            ->get()->map(fn ($row) => [
                'stage' => $row->stage,
                'reason' => $row->safe_reason,
                'journeys' => (int) $row->journeys,
            ])->all();

        $campaigns = DB::table('signup_funnel_journeys')
            ->where('campaign_key', '~', '^spectra_[1-9][0-9]{0,17}$')
            ->distinct()->orderBy('campaign_key')->pluck('campaign_key')->all();

        return [
            'filters' => [
                'funnel_from' => $start->toDateString(),
                'funnel_to' => $end->toDateString(),
                'funnel_source' => $source,
                'funnel_campaign' => $campaign,
                'funnel_entry_surface' => $entrySurface,
            ],
            'first_observed_at' => DB::table('signup_funnel_journeys')->min('started_at'),
            'as_of' => $asOf->toIso8601String(),
            'campaigns' => $campaigns,
            'journeys' => (int) $counts['journeys'],
            'steps' => $steps,
            'signups_with_preview' => (int) $counts['signups_with_preview'],
            'signups_without_observed_preview' => $steps['signup_completed']['reached'] - (int) $counts['signups_with_preview'],
            'failures' => $failures,
        ];
    }
}
