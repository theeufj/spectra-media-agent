<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Models\SeoRanking;

/** Organic query measurements from Search Console, separate from paid ad keywords. */
class RankTrackingService
{
    public function __construct(protected Customer $customer) {}

    public function trackOrganicQueries(int $limit = 50): array
    {
        $searchConsole = app(SearchConsoleService::class);
        if (! $searchConsole->isVerified($this->customer)) {
            return ['success' => false, 'error' => 'Search Console property access is required to measure organic queries. Existing results are preserved.'];
        }
        $report = $searchConsole->performance($this->customer, 'query', 28, $limit);
        if (! $report['success']) {
            return $report;
        }
        // Associate each observed query with its most visible landing page.
        // Query metrics remain from the host-filtered query report; do not
        // rebuild them by summing a potentially truncated query/page report.
        $pageReport = $searchConsole->performance($this->customer, 'query_page', 28, 25000);
        $pages = collect($pageReport['rows'] ?? [])->groupBy('key')->map(fn ($rows) => $rows->sortByDesc('impressions')->first()['page'] ?? null);
        $domain = (string) (parse_url($this->customer->website, PHP_URL_HOST) ?: $this->customer->website);
        $count = 0;
        foreach ($report['rows'] ?? [] as $row) {
            if (! filled($row['key']) || ! is_numeric($row['position']) || ($row['impressions'] ?? 0) < 1) {
                continue;
            }
            $previous = SeoRanking::where('customer_id', $this->customer->id)
                ->where('keyword', $row['key'])->where('source', 'google_search_console')
                ->where('reporting_end', '<', $report['reporting_end'])
                ->orderByDesc('reporting_end')->first();
            $existing = SeoRanking::where('customer_id', $this->customer->id)
                ->where('keyword', $row['key'])->where('source', 'google_search_console')
                ->whereDate('date', now()->toDateString())->first();
            SeoRanking::updateOrCreate([
                'customer_id' => $this->customer->id, 'keyword' => $row['key'],
                'date' => now()->toDateString(), 'source' => 'google_search_console',
            ], [
                'domain' => $domain, 'search_engine' => 'google',
                'position' => null, 'previous_position' => null, 'change' => null,
                'average_position' => $row['position'],
                'previous_average_position' => $previous?->average_position,
                'average_change' => $previous?->average_position !== null ? $previous->average_position - $row['position'] : null,
                'url' => $pageReport['success'] ? $pages->get($row['key']) : ($existing->url ?? $previous->url ?? null),
                'reporting_start' => $report['reporting_start'], 'reporting_end' => $report['reporting_end'],
                'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => $row['ctr'],
            ]);
            $count++;
        }

        return ['success' => true, 'tracked' => $count, 'warning' => $pageReport['success'] ? null : 'Query metrics were saved, but landing page associations were unavailable.'];
    }

    /** Kept for existing callers; the measured terms come from organic search, not ad keywords. */
    public function trackKeywords(array $keywords, string $domain): array
    {
        return $this->trackOrganicQueries();
    }

    public function latestRankings()
    {
        $query = SeoRanking::where('customer_id', $this->customer->id);
        $date = (clone $query)->max('date');
        $latest = $query->whereDate('date', $date ?? now()->toDateString())->get();
        // A first-party measurement and an old scraped snapshot are different
        // instruments. Never combine them in a summary or trend.
        if ($latest->contains('source', 'google_search_console')) {
            $latest = $latest->where('source', 'google_search_console');
        }

        return $latest->sortBy(fn ($r) => $r->average_position ?? $r->position ?? PHP_INT_MAX)->values();
    }

    public function getTrends(string $keyword, int $days = 30): array
    {
        $latest = SeoRanking::where('customer_id', $this->customer->id)->where('keyword', $keyword)->orderByDesc('date')->first();

        return SeoRanking::where('customer_id', $this->customer->id)
            ->where('keyword', $keyword)->where('source', $latest->source ?? 'legacy_unknown')
            ->where('date', '>=', now()->subDays($days)->toDateString())->orderBy('date')->get()
            ->map(fn ($r) => [
                'date' => $r->date, 'position' => $r->average_position ?? $r->position,
                'change' => $r->average_change ?? $r->change, 'source' => $r->source,
                'reporting_start' => $r->reporting_start, 'reporting_end' => $r->reporting_end,
            ])->toArray();
    }

    public function getSummary(): array
    {
        $latest = $this->latestRankings();
        $ranked = $latest->filter(fn ($r) => ($r->average_position ?? $r->position) !== null);
        $positions = $ranked->map(fn ($r) => $r->average_position ?? $r->position);
        $top3 = $positions->filter(fn ($p) => $p <= 3)->count();
        $top10 = $positions->filter(fn ($p) => $p <= 10)->count();
        $top30 = $positions->filter(fn ($p) => $p <= 30)->count();
        $changes = $latest->map(fn ($r) => $r->average_change ?? $r->change);
        $improved = $changes->filter(fn ($c) => $c !== null && $c > 0)->count();
        $declined = $changes->filter(fn ($c) => $c !== null && $c < 0)->count();
        $first = $latest->first();

        return [
            'total_keywords' => $latest->count(), 'ranked_keywords' => $ranked->count(),
            'top_3' => $top3, 'top_3_count' => $top3, 'top_10' => $top10, 'top_10_count' => $top10,
            'top_11_30' => $top30 - $top10, 'not_ranking' => $latest->count() - $ranked->count(),
            'improved' => $improved, 'improved_count' => $improved, 'declined' => $declined,
            'unchanged' => $latest->count() - $improved - $declined,
            'average_position' => $positions->avg(), 'avg_position' => $positions->avg(),
            'source' => $first?->source, 'measured_on' => $first?->date?->toDateString(),
            'reporting_start' => $first?->reporting_start?->toDateString(),
            'reporting_end' => $first?->reporting_end?->toDateString(),
        ];
    }
}
