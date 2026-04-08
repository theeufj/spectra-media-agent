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

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->firecrawl = app(FirecrawlService::class);
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
        try {
            if (!$this->firecrawl->isConfigured()) {
                Log::debug('RankTracking: Firecrawl not configured');
                return ['position' => null, 'url' => null];
            }

            // Fetch up to 100 results to find position
            $response = $this->firecrawl->search($keyword, 100);

            if (!$response['success']) {
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
