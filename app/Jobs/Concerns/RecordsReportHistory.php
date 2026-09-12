<?php

namespace App\Jobs\Concerns;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * The Reports page's listing, which is a cache entry rather than a table.
 *
 * GenerateExecutiveReport and GenerateMonthlyReport each had their own copy of
 * this, and both did a bare `array_unshift` — so a period could be recorded more
 * than once and the page would list it more than once. On the production data
 * every report is currently doubled: six periods, twelve rows, each pair
 * identical down to the CPA.
 *
 * There are at least two ways in, and neither is exotic:
 *
 *   - GenerateExecutiveReport rethrows on failure, so the queue retries it. A
 *     run that stored its record and then died further on wrote a second one on
 *     the next attempt.
 *   - "Generate Weekly" on the Reports page dispatches the same job for the same
 *     period the Monday schedule already covered.
 *
 * Keying on (period, start) makes the write idempotent, which is the same
 * property the money paths get from a deterministic Stripe idempotency key: a
 * job that runs twice leaves the same state as a job that ran once. The newer
 * record replaces the older one in place rather than being appended, so a
 * regenerated report updates its figures instead of shadowing them.
 */
trait RecordsReportHistory
{
    /** How many periods the listing keeps. */
    private const HISTORY_LIMIT = 24;

    /**
     * @param  array<string, mixed>  $report
     */
    protected function storeReportRecord(Customer $customer, array $report, ?string $pdfPath): void
    {
        $key = "report_history:{$customer->id}";

        $record = [
            'period' => $report['period']['type'],
            'start' => $report['period']['start'],
            'end' => $report['period']['end'],
            'generated_at' => $report['generated_at'],
            'pdf_path' => $pdfPath,
            'summary' => [
                'total_cost' => $report['summary']['total_cost'],
                'total_clicks' => $report['summary']['total_clicks'],
                'total_conversions' => $report['summary']['total_conversions'],
                'blended_cpa' => $report['summary']['blended_cpa'],
                'total_impressions' => $report['summary']['total_impressions'] ?? null,
            ],
            /*
             * The narrative, so the report can be read on the page.
             *
             * The listing kept four numbers and a path to a PDF, so the only way
             * to find out what the AI made of a period was to download a file —
             * on a page whose own subtitle promises "the AI's read on what
             * changed". The narrative was computed, emailed, rendered into the
             * PDF and then dropped.
             *
             * Records written before this change have neither key, and the page
             * falls back to the PDF for those rather than showing an empty
             * panel.
             */
            'executive_summary' => $report['ai_executive_summary'] ?? null,
            'insights' => array_slice($report['ai_insights'] ?? [], 0, 6),
        ];

        $history = array_values(array_filter(
            Cache::get($key, []),
            fn ($existing) => ! self::isSamePeriod($existing, $record),
        ));

        array_unshift($history, $record);

        Cache::put($key, array_slice($history, 0, self::HISTORY_LIMIT), now()->addDays(365));
    }

    /**
     * Two records describe the same report when they cover the same period.
     *
     * `end` is deliberately not compared: a weekly run and a manual regeneration
     * of the same week can compute the boundary a few hours apart, and treating
     * those as different reports is the bug rather than a distinction worth
     * keeping.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $record
     */
    private static function isSamePeriod(array $existing, array $record): bool
    {
        return ($existing['period'] ?? null) === $record['period']
            && ($existing['start'] ?? null) === $record['start'];
    }
}
