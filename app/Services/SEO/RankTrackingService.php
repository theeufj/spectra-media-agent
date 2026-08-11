<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Models\SeoRanking;
use App\Services\FirecrawlService;
use Illuminate\Support\Facades\Log;

/**
 * Keyword rank tracking service.
 *
 * Tracks daily search engine ranking positions for target keywords
 * using Firecrawl search API.
 */
class RankTrackingService
{
    protected Customer $customer;

    protected FirecrawlService $firecrawl;

    protected SearchConsoleService $searchConsole;

    /** Cached Search Console rows for this run, keyed by query. */
    private ?array $searchConsoleRows = null;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->firecrawl = app(FirecrawlService::class);
        $this->searchConsole = app(SearchConsoleService::class);
    }

    /**
     * Track rankings for a set of keywords against a domain.
     */
    public function trackKeywords(array $keywords, string $domain): array
    {
        $results = [];

        foreach ($keywords as $keyword) {
            $ranking = $this->checkRanking($keyword, $domain);
            $results[] = $ranking;

            SeoRanking::updateOrCreate(
                [
                    'customer_id' => $this->customer->id,
                    'keyword' => $keyword,
                    'date' => now()->toDateString(),
                ],
                [
                    'domain' => $domain,
                    'position' => $ranking['position'],
                    'url' => $ranking['url'],
                    'search_engine' => 'google',
                    'previous_position' => $ranking['previous_position'],
                    'change' => $ranking['change'],
                ]
            );
        }

        Log::info('RankTracking: Completed', [
            'customer_id' => $this->customer->id,
            'keywords_tracked' => count($results),
            'domain' => $domain,
        ]);

        return $results;
    }

    /**
     * Check current ranking for a keyword.
     */
    protected function checkRanking(string $keyword, string $domain): array
    {
        // Get previous ranking for comparison
        $previous = SeoRanking::where('customer_id', $this->customer->id)
            ->where('keyword', $keyword)
            ->where('date', '<', now()->toDateString())
            ->orderBy('date', 'desc')
            ->first();

        $previousPosition = $previous?->position;

        // Use Firecrawl search to find ranking position
        $result = $this->searchForPosition($keyword, $domain);

        $change = null;
        if ($previousPosition !== null && $result['position'] !== null) {
            $change = $previousPosition - $result['position']; // Positive = improved
        }

        return [
            'keyword' => $keyword,
            'domain' => $domain,
            'position' => $result['position'],
            'url' => $result['url'],
            'previous_position' => $previousPosition,
            'change' => $change,
            'tracked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Search via Firecrawl to find the domain's ranking position for a keyword.
     */
    protected function searchForPosition(string $keyword, string $domain): array
    {
        // Search Console first: it is first-party, free, and reports the average
        // position across every real impression rather than one scraped
        // snapshot. Scraping is kept only as a fallback for domains we are not
        // verified on — competitors, mainly.
        $fromSearchConsole = $this->positionFromSearchConsole($keyword);

        if ($fromSearchConsole !== null) {
            return $fromSearchConsole;
        }

        try {
            if (! $this->firecrawl->isConfigured()) {
                Log::debug('RankTracking: Firecrawl not configured');

                return ['position' => null, 'url' => null];
            }

            // Fetch up to 100 results to find position
            $response = $this->firecrawl->search($keyword, 100);

            if (! $response['success']) {
                return ['position' => null, 'url' => null];
            }

            foreach ($response['results'] as $index => $item) {
                $url = $item['url'] ?? '';
                if (str_contains($url, $domain)) {
                    return [
                        'position' => $index + 1,
                        'url' => $url,
                    ];
                }
            }

            return ['position' => null, 'url' => null]; // Not found in results
        } catch (\Exception $e) {
            Log::debug('RankTracking: Search failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return ['position' => null, 'url' => null];
        }
    }

    /**
     * Average position for a keyword from Search Console, if the site is
     * verified and the keyword actually drew impressions.
     *
     * Returns null — meaning "no answer here" — rather than a null position,
     * so the caller can fall through to scraping instead of recording a miss.
     * The whole point of this change is that "we could not measure" and "the
     * site does not rank" stop looking identical, which is how 4,264 rows of
     * nulls went unnoticed for three months.
     *
     * @return array{position: int|null, url: string|null}|null
     */
    private function positionFromSearchConsole(string $keyword): ?array
    {
        if ($this->searchConsoleRows === null) {
            if (! $this->searchConsole->isVerified($this->customer)) {
                $this->searchConsoleRows = [];
            } else {
                $result = $this->searchConsole->performance($this->customer, 'query', 28, 500);

                $this->searchConsoleRows = $result['success']
                    ? collect($result['rows'] ?? [])
                        ->filter(fn ($r) => filled($r['key']))
                        ->keyBy(fn ($r) => mb_strtolower($r['key']))
                        ->all()
                    : [];
            }
        }

        $row = $this->searchConsoleRows[mb_strtolower($keyword)] ?? null;

        if (! $row) {
            return null;
        }

        return [
            'position' => (int) round($row['position']),
            'url' => null,
        ];
    }

    /**
     * Get ranking trends for a keyword over time.
     */
    public function getTrends(string $keyword, int $days = 30): array
    {
        return SeoRanking::where('customer_id', $this->customer->id)
            ->where('keyword', $keyword)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date,
                'position' => $r->position,
                'change' => $r->change,
            ])
            ->toArray();
    }

    /**
     * Get current rankings summary for all tracked keywords.
     */
    public function getSummary(): array
    {
        $latest = SeoRanking::where('customer_id', $this->customer->id)
            ->whereDate('date', now()->toDateString())
            ->get();

        $top3 = $latest->where('position', '<=', 3)->count();
        $top10 = $latest->where('position', '<=', 10)->count();
        $top30 = $latest->where('position', '<=', 30)->count();
        $improved = $latest->where('change', '>', 0)->count();
        $declined = $latest->where('change', '<', 0)->count();
        $avgPosition = $latest->whereNotNull('position')->avg('position');

        return [
            'total_keywords' => $latest->count(),
            'top_3' => $top3,
            'top_3_count' => $top3,
            'top_10' => $top10,
            'top_10_count' => $top10,
            'top_11_30' => $top30 - $top10,
            'not_ranking' => $latest->whereNull('position')->count(),
            'improved' => $improved,
            'improved_count' => $improved,
            'declined' => $declined,
            'unchanged' => $latest->count() - $improved - $declined,
            'average_position' => $avgPosition,
            'avg_position' => $avgPosition,
        ];
    }
}
