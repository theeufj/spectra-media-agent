<?php

namespace App\Services\GoogleAds\Diagnostics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Bounded, read-only documentation lookup. Callers choose a known topic, never
 * an AI-supplied URL. Page text is evidence for humans, not executable guidance.
 */
class OfficialTroubleshootingDocs
{
    private const TOPICS = [
        'search_delivery' => [
            'title' => 'Fix a Search campaign that is not running or has low traffic',
            'url' => 'https://support.google.com/google-ads/answer/9208915?hl=en',
            'fallback' => 'Check eligibility, targeting, Ad Rank, bidding, and conversion tracking before changing the budget.',
        ],
        'impression_share' => [
            'title' => 'Google Ads API impression-share metrics',
            'url' => 'https://developers.google.com/google-ads/api/fields/v22/metrics',
            'fallback' => 'Search impression share below 10% is reported as 0.0999; rank loss above 90% is reported as 0.9001.',
        ],
    ];

    public function lookup(string $topic): ?array
    {
        $entry = self::TOPICS[$topic] ?? null;
        if (! $entry) {
            return null;
        }

        return Cache::remember('official_google_ads_docs:'.$topic, now()->addDay(), function () use ($entry) {
            $summary = $entry['fallback'];
            $source = 'curated';

            try {
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($entry['url']);
                if ($response->successful() && str_contains($response->header('Content-Type'), 'text/html')) {
                    $body = $response->body();
                    $body = preg_replace('/<(script|style|nav|footer)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
                    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                    if (mb_strlen($text) >= 100) {
                        $summary = mb_substr($text, 0, 1200);
                        $source = 'official_page';
                    }
                }
            } catch (\Throwable) {
                // Documentation availability must never block campaign diagnosis.
            }

            return [
                'title' => $entry['title'],
                'url' => $entry['url'],
                'summary' => $summary,
                'source' => $source,
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }
}
