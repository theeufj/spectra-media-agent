<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Models\SeoRanking;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keyword rank tracking service.
 *
 * Tracks daily search engine ranking positions for target keywords
 * using Google Search Console API and fallback estimation methods.
 */
class RankTrackingService
{
    protected Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
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

        // Use Google Custom Search API for position checking
        $position = $this->searchGoogleForPosition($keyword, $domain);

        $change = null;
        if ($previousPosition !== null && $position !== null) {
            $change = $previousPosition - $position; // Positive = improved
        }

        return [
            'keyword' => $keyword,
            'domain' => $domain,
            'position' => $position,
            'url' => null,
            'previous_position' => $previousPosition,
            'change' => $change,
            'tracked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Search Google Custom Search API to find ranking position.
     */
    protected function searchGoogleForPosition(string $keyword, string $domain): ?int
    {
        try {
            $apiKey = config('services.google_cse.api_key');
            $cseId = config('services.google_cse.search_engine_id');

            if (!$apiKey || !$cseId) {
                return null;
            }

            // Search up to 100 results (10 pages of 10)
            for ($start = 1; $start <= 91; $start += 10) {
                $response = Http::get('https://www.googleapis.com/customsearch/v1', [
                    'key' => $apiKey,
                    'cx' => $cseId,
                    'q' => $keyword,
                    'start' => $start,
                    'num' => 10,
                ]);

                if (!$response->successful()) break;

                $items = $response->json('items', []);
                foreach ($items as $index => $item) {
                    $link = $item['link'] ?? '';
                    if (str_contains($link, $domain)) {
                        return $start + $index;
                    }
                }
            }

            return null; // Not found in top 100
        } catch (\Exception $e) {
            Log::debug('RankTracking: Google search failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);
            return null;
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
