<?php

namespace Tests\Feature;

use App\Jobs\Concerns\RecordsReportHistory;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A report period appears once in the listing, however many times it is generated.
 *
 * The Reports page reads a cache entry, and both generator jobs appended to it
 * with a bare `array_unshift`. Production shows the result: six periods listed
 * as twelve rows, each pair identical down to the CPA. Two routes in —
 * GenerateExecutiveReport rethrows on failure so the queue retries it, and the
 * page's own "Generate Weekly" button re-runs a period the Monday schedule
 * already covered.
 *
 * Nothing surfaced it because a duplicate report is not an error: the job
 * succeeds, the PDF is fine, and the only symptom is a longer list.
 */
class ReportHistoryIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    /** A stand-in for the two generator jobs, which differ only in their period. */
    private function recorder(): object
    {
        return new class
        {
            use RecordsReportHistory {
                storeReportRecord as public record;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $type, string $start, string $end, float $cost = 592.8): array
    {
        return [
            'period' => ['type' => $type, 'start' => $start, 'end' => $end],
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total_cost' => $cost,
                'total_clicks' => 330,
                'total_conversions' => 30,
                'blended_cpa' => 19.76,
            ],
        ];
    }

    public function test_regenerating_a_period_replaces_it_rather_than_listing_it_twice(): void
    {
        $customer = Customer::factory()->create();
        $recorder = $this->recorder();

        $recorder->record($customer, $this->report('weekly', '2026-08-17', '2026-08-24'), 'a.pdf');
        $recorder->record($customer, $this->report('weekly', '2026-08-17', '2026-08-24', 601.40), 'b.pdf');

        $history = Cache::get("report_history:{$customer->id}");

        $this->assertCount(1, $history, 'The same week was listed more than once.');
        // The newer run wins, so a regenerated report updates its figures.
        $this->assertSame(601.40, $history[0]['summary']['total_cost']);
        $this->assertSame('b.pdf', $history[0]['pdf_path']);
    }

    public function test_a_weekly_and_a_monthly_report_starting_the_same_day_are_both_kept(): void
    {
        $customer = Customer::factory()->create();
        $recorder = $this->recorder();

        $recorder->record($customer, $this->report('weekly', '2026-08-01', '2026-08-08'), 'w.pdf');
        $recorder->record($customer, $this->report('monthly', '2026-08-01', '2026-09-01'), 'm.pdf');

        // Same start date, different period type — two genuinely different
        // reports, and deduping on the date alone would have eaten one.
        $this->assertCount(2, Cache::get("report_history:{$customer->id}"));
    }

    public function test_distinct_periods_accumulate_newest_first(): void
    {
        $customer = Customer::factory()->create();
        $recorder = $this->recorder();

        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $start) {
            $recorder->record($customer, $this->report('weekly', $start, $start), null);
        }

        $history = Cache::get("report_history:{$customer->id}");

        $this->assertSame(['2026-08-17', '2026-08-10', '2026-08-03'], array_column($history, 'start'));
    }

    public function test_the_listing_is_capped(): void
    {
        $customer = Customer::factory()->create();
        $recorder = $this->recorder();

        for ($week = 1; $week <= 30; $week++) {
            $recorder->record($customer, $this->report('weekly', '2026-01-'.str_pad((string) $week, 2, '0', STR_PAD_LEFT), '2026-01-31'), null);
        }

        $this->assertCount(24, Cache::get("report_history:{$customer->id}"));
    }
}
