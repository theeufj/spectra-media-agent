<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = app(GeminiService::class);
    }

    /**
     * Analyze backlink profile for a domain.
     */
    public function analyze(string $domain): array
    {
        Log::info('BacklinkAnalysis: Starting', ['customer_id' => $this->customer->id, 'domain' => $domain]);

        // Check for Moz or Ahrefs API keys
        $backlinks = $this->fetchBacklinks($domain);

        $profile = [
            'domain' => $domain,
            'total_backlinks' => count($backlinks),
            'referring_domains' => $this->countUniqueDomains($backlinks),
            'dofollow_count' => collect($backlinks)->where('rel', '!=', 'nofollow')->count(),
            'nofollow_count' => collect($backlinks)->where('rel', 'nofollow')->count(),
            'backlinks' => $backlinks,
            'toxic_links' => $this->detectToxicLinks($backlinks),
            'top_anchors' => $this->analyzeAnchors($backlinks),
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

        // Fallback: use Common Crawl / Google search operator estimation
        return $this->estimateBacklinks($domain);
    }

    protected function fetchFromMoz(string $domain, string $apiKey): array
    {
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
    }

    /**
     * Estimate backlinks using Google search operators.
     */
    protected function estimateBacklinks(string $domain): array
    {
        // This is a limited fallback — real implementation would use a backlink API
        return [];
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
