<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Services\FirecrawlService;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Backlink analysis service.
 *
 * Analyzes a domain's backlink profile, detects toxic links,
 * and identifies link building opportunities from competitors.
 */
class BacklinkAnalysisService
{
    protected Customer $customer;
    protected GeminiService $gemini;
    protected FirecrawlService $firecrawl;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = app(GeminiService::class);
        $this->firecrawl = app(FirecrawlService::class);
    }

    /**
     * Analyze backlink profile for a domain.
     */
    public function analyze(string $domain): array
    {
        return Cache::remember('backlink_analysis:' . md5($domain), now()->addHours(24), function () use ($domain) {
            return $this->performAnalysis($domain);
        });
    }

    protected function performAnalysis(string $domain): array
    {
        Log::info('BacklinkAnalysis: Starting', ['customer_id' => $this->customer->id, 'domain' => $domain]);

        $backlinks = $this->fetchBacklinks($domain);
        $toxicLinks = $this->detectToxicLinks($backlinks);
        $topAnchors = $this->analyzeAnchors($backlinks);
        $totalBacklinks = count($backlinks);

        // Calculate anchor text percentages
        $anchorAnalysis = collect($topAnchors)->map(function ($anchor) use ($totalBacklinks) {
            return array_merge($anchor, [
                'text' => $anchor['anchor'],
                'percentage' => $totalBacklinks > 0 ? round(($anchor['count'] / $totalBacklinks) * 100, 1) : 0,
            ]);
        })->toArray();

        // Estimate average domain authority from backlinks that have it
        $daValues = collect($backlinks)->pluck('domain_authority')->filter()->values();
        $domainAuthority = $daValues->isNotEmpty() ? round($daValues->avg(), 1) : null;

        $profile = [
            'domain' => $domain,
            'total_backlinks' => $totalBacklinks,
            'referring_domains' => $this->countUniqueDomains($backlinks),
            'dofollow_count' => collect($backlinks)->where('rel', '!=', 'nofollow')->count(),
            'nofollow_count' => collect($backlinks)->where('rel', 'nofollow')->count(),
            'domain_authority' => $domainAuthority,
            'backlinks' => $backlinks,
            'toxic_links' => $toxicLinks,
            'toxic_count' => count($toxicLinks),
            'top_anchors' => $topAnchors,
            'anchor_analysis' => $anchorAnalysis,
            'analyzed_at' => now()->toIso8601String(),
        ];

        // AI-powered competitive gap analysis
        $profile['opportunities'] = $this->findLinkOpportunities($domain, $backlinks);

        Log::info('BacklinkAnalysis: Complete', [
            'customer_id' => $this->customer->id,
            'domain' => $domain,
            'total_backlinks' => $profile['total_backlinks'],
        ]);

        return $profile;
    }

    /**
     * Fetch backlinks using available APIs.
     */
    protected function fetchBacklinks(string $domain): array
    {
        // Try Moz API
        $mozApiKey = config('services.moz.api_key');
        if ($mozApiKey) {
            return $this->fetchFromMoz($domain, $mozApiKey);
        }

        // Fallback: use Firecrawl search to find pages linking to the domain
        return $this->estimateBacklinks($domain);
    }

    protected function fetchFromMoz(string $domain, string $apiKey): array
    {
        return Cache::remember('moz_backlinks:' . md5($domain), now()->addHours(24), function () use ($domain, $apiKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])->post('https://lsapi.seomoz.com/v2/links', [
                    'target' => $domain,
                    'scope' => 'page',
                    'limit' => 100,
                ]);

                if (!$response->successful()) return [];

                return collect($response->json('results', []))->map(fn ($link) => [
                    'source_url' => $link['source_page'] ?? '',
                    'source_domain' => parse_url($link['source_page'] ?? '', PHP_URL_HOST) ?: '',
                    'target_url' => $link['target_page'] ?? '',
                    'anchor_text' => $link['anchor_text'] ?? '',
                    'rel' => ($link['nofollow'] ?? false) ? 'nofollow' : 'dofollow',
                    'domain_authority' => $link['source_domain_authority'] ?? null,
                    'first_seen' => $link['first_seen'] ?? null,
                ])->toArray();
            } catch (\Exception $e) {
                Log::debug('BacklinkAnalysis: Moz API failed', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Estimate backlinks using Firecrawl search API.
     *
     * Searches for pages that mention/link to the domain using
     * query operators supported by Firecrawl.
     */
    protected function estimateBacklinks(string $domain): array
    {
        if (!$this->firecrawl->isConfigured()) {
            Log::debug('BacklinkAnalysis: Firecrawl not configured, cannot estimate backlinks');
            return [];
        }

        try {
            $backlinks = [];
            $queries = [
                "\"{$domain}\" -site:{$domain}",
                "link:{$domain} -site:{$domain}",
            ];

            foreach ($queries as $query) {
                $response = $this->firecrawl->search($query, 50);

                if (!$response['success']) continue;

                foreach ($response['results'] as $item) {
                    $sourceUrl = $item['url'] ?? '';
                    $sourceHost = parse_url($sourceUrl, PHP_URL_HOST) ?: '';

                    // Skip self-referencing results
                    if (str_contains($sourceHost, $domain)) continue;

                    $backlinks[] = [
                        'source_url' => $sourceUrl,
                        'source_domain' => $sourceHost,
                        'target_url' => "https://{$domain}",
                        'anchor_text' => $item['title'] ?? $item['description'] ?? '',
                        'rel' => 'dofollow',
                        'domain_authority' => null,
                        'first_seen' => null,
                    ];
                }
            }

            // Deduplicate by source URL
            return collect($backlinks)
                ->unique('source_url')
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::debug('BacklinkAnalysis: Firecrawl fallback failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function countUniqueDomains(array $backlinks): int
    {
        return collect($backlinks)->pluck('source_domain')->unique()->count();
    }

    protected function detectToxicLinks(array $backlinks): array
    {
        $toxic = [];
        $spamIndicators = ['casino', 'poker', 'pharma', 'viagra', 'payday', 'loan'];

        foreach ($backlinks as $link) {
            $domain = strtolower($link['source_domain'] ?? '');
            $anchor = strtolower($link['anchor_text'] ?? '');

            $isSpammy = false;
            foreach ($spamIndicators as $indicator) {
                if (str_contains($domain, $indicator) || str_contains($anchor, $indicator)) {
                    $isSpammy = true;
                    break;
                }
            }

            // Low domain authority from known spam networks
            if (($link['domain_authority'] ?? 100) < 5) {
                $isSpammy = true;
            }

            if ($isSpammy) {
                $toxic[] = array_merge($link, ['reason' => 'Potential spam/toxic link pattern detected']);
            }
        }

        return $toxic;
    }

    protected function analyzeAnchors(array $backlinks): array
    {
        return collect($backlinks)
            ->pluck('anchor_text')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(20)
            ->map(fn ($count, $anchor) => ['anchor' => $anchor, 'count' => $count])
            ->values()
            ->toArray();
    }

    /**
     * Use AI to identify link building opportunities.
     */
    protected function findLinkOpportunities(string $domain, array $backlinks): array
    {
        try {
            $topDomains = collect($backlinks)
                ->pluck('source_domain')
                ->unique()
                ->take(20)
                ->implode(', ');

            $totalBacklinks = count($backlinks);
            $prompt = <<<PROMPT
You are an SEO link building expert. Analyze this domain's backlink profile:
Domain: {$domain}
Top referring domains: {$topDomains}
Total backlinks: {$totalBacklinks}

Suggest 3-5 link building opportunities as JSON:
[{"type": "guest_post|resource_page|broken_link|directory|partnership", "description": "specific opportunity", "difficulty": "easy|medium|hard", "estimated_value": "high|medium|low"}]
Return ONLY valid JSON.
PROMPT;

            $result = $this->gemini->generateContent('gemini-3-flash-preview', $prompt, [
                'temperature' => 0.4,
                'maxOutputTokens' => 1024,
            ]);

            $text = $result['text'] ?? '';
            $text = preg_replace('/```json\s*/', '', $text);
            $text = preg_replace('/```\s*$/', '', $text);

            return json_decode(trim($text), true) ?? [];
        } catch (\Exception $e) {
            Log::debug('BacklinkAnalysis: AI opportunities failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Compare backlink profiles between the customer's domain and a competitor.
     */
    public function compareWithCompetitor(string $domain, string $competitorDomain): array
    {
        $ours = $this->analyze($domain);
        $theirs = $this->analyze($competitorDomain);

        $ourDomains = collect($ours['backlinks'])->pluck('source_domain')->unique();
        $theirDomains = collect($theirs['backlinks'])->pluck('source_domain')->unique();

        return [
            'domain' => $domain,
            'competitor' => $competitorDomain,
            'our_backlinks' => $ours['total_backlinks'],
            'their_backlinks' => $theirs['total_backlinks'],
            'our_referring_domains' => $ours['referring_domains'],
            'their_referring_domains' => $theirs['referring_domains'],
            'shared_domains' => $ourDomains->intersect($theirDomains)->count(),
            'gap_domains' => $theirDomains->diff($ourDomains)->values()->toArray(),
            'unique_to_us' => $ourDomains->diff($theirDomains)->values()->toArray(),
        ];
    }
}
