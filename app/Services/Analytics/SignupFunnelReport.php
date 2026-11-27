<?php

namespace App\Services\Analytics;

use App\Services\SignupFunnel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SignupFunnelReport
{
    private const PREVIEW_PATH = ['landing_visible', 'preview_succeeded', 'save_clicked', 'signup_completed', 'paid_subscription'];

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
        'save_clicked' => 'preview_succeeded',
        'signup_viewed' => null,
        'signup_started' => 'signup_viewed',
        'signup_submitted' => 'signup_started',
        'signup_completed' => 'signup_submitted',
        'signup_saved_draft' => 'signup_completed',
        'email_verified' => 'signup_completed',
        'paid_subscription' => 'signup_completed',
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
            ->where('started_at', '>=', $start->startOfDay()->toIso8601String())
            ->where('started_at', '<', $end->startOfDay()->addDay()->toIso8601String())
            ->where('started_at', '<=', $asOf->toIso8601String())
            ->when($source, fn ($query) => $query->where('source', $source))
            ->when($campaign, fn ($query) => $query->where('campaign_key', $campaign))
            ->select('id', 'entry_surface', 'started_at');
        if ($entrySurface !== null) {
            $cohort->where('entry_surface', $entrySurface === 'unspecified' ? null : $entrySurface);
        }

        $stages = DB::table('signup_funnel_stages as s')->where('s.occurred_at', '<=', $asOf->toIso8601String());
        // Stage times have second precision. Use recording order to resolve ties
        // so a Save observed before a preview cannot complete the preview path.
        $orderedStages = DB::query()->fromSub($cohort, 'j')
            ->leftJoinSub(clone $stages, 's', fn ($join) => $join
                ->on('s.journey_id', '=', 'j.id')->on('s.occurred_at', '>=', 'j.started_at'))
            ->select('j.id', 'j.entry_surface', 's.stage')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY j.id ORDER BY s.occurred_at, s.id) AS step_order');
        $journeys = DB::query()->fromSub($orderedStages, 's')
            ->groupBy('s.id', 's.entry_surface')->select('s.id', 's.entry_surface');
        $totals = ['COUNT(*) AS journeys'];
        foreach (self::TRANSITIONS as $stage => $baseline) {
            $journeys->selectRaw("MIN(s.step_order) FILTER (WHERE s.stage = ?) AS {$stage}", [$stage]);
            $totals[] = "COUNT({$stage}) AS {$stage}";
            if ($baseline !== null) {
                $totals[] = "COUNT(*) FILTER (WHERE {$baseline} <= {$stage}) AS paired_{$stage}";
            }
        }
        // A later event only advances the preview path if every prior event
        // was observed in order for that same session. Direct entry is independent.
        $sequence = 'landing_visible IS NOT NULL';
        foreach (self::PREVIEW_PATH as $index => $stage) {
            if ($index > 0) {
                $previous = self::PREVIEW_PATH[$index - 1];
                $sequence .= " AND {$previous} <= {$stage}";
            }
            $totals[] = "COUNT(*) FILTER (WHERE {$sequence}) AS path_{$stage}";
        }
        $totals[] = 'COUNT(*) FILTER (WHERE preview_succeeded <= signup_completed) AS signups_with_preview';
        $totals[] = "COUNT(*) FILTER (WHERE entry_surface = 'register') AS direct_entries";
        $totals[] = "COUNT(signup_completed) FILTER (WHERE entry_surface = 'register') AS direct_signups";
        $totals[] = "COUNT(*) FILTER (WHERE entry_surface = 'register' AND signup_completed <= paid_subscription) AS direct_paid";
        $counts = (array) DB::query()->fromSub($journeys, 'observed')->selectRaw(implode(', ', $totals))->first();

        $previewPath = [];
        $previousCount = null;
        foreach (self::PREVIEW_PATH as $stage) {
            $reached = (int) $counts['path_'.$stage];
            $missing = $previousCount === null ? null : $previousCount - $reached;
            $previewPath[$stage] = [
                'reached' => $reached,
                'previous' => $previousCount,
                'missing' => $missing,
                'dropoff_percent' => $previousCount > 0 ? round(100 * $missing / $previousCount, 1) : null,
            ];
            $previousCount = $reached;
        }
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
            ->whereColumn('s.occurred_at', '>=', 'j.started_at')
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

        $firstObserved = DB::table('signup_funnel_journeys')->min('started_at');

        return [
            'filters' => [
                'funnel_from' => $start->toDateString(),
                'funnel_to' => $end->toDateString(),
                'funnel_source' => $source,
                'funnel_campaign' => $campaign,
                'funnel_entry_surface' => $entrySurface,
            ],
            'first_observed_at' => $firstObserved ? CarbonImmutable::parse($firstObserved)->utc()->toIso8601String() : null,
            'as_of' => $asOf->toIso8601String(),
            'campaigns' => $campaigns,
            'journeys' => (int) $counts['journeys'],
            'preview_path' => $previewPath,
            'direct_entry' => [
                'journeys' => (int) $counts['direct_entries'],
                'registrations' => (int) $counts['direct_signups'],
                'paid' => (int) $counts['direct_paid'],
            ],
            'other_paths' => [
                'registrations' => $steps['signup_completed']['reached'] - $previewPath['signup_completed']['reached'],
                'paid' => $steps['paid_subscription']['both'] - $previewPath['paid_subscription']['reached'],
            ],
            'steps' => $steps,
            'signups_with_preview' => (int) $counts['signups_with_preview'],
            'signups_without_observed_preview' => $steps['signup_completed']['reached'] - (int) $counts['signups_with_preview'],
            'failures' => $failures,
        ];
    }
}
