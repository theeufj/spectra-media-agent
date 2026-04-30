<?php

namespace App\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class KeywordClusteringService
{
    protected GeminiService $gemini;

    public function __construct()
    {
        $this->gemini = new GeminiService();
    }

    public function cluster(array $keywords): array
    {
        if (empty($keywords)) {
            return ['clusters' => [], 'unclustered' => []];
        }

        $keywordTexts = array_map(fn($k) => is_string($k) ? $k : ($k['text'] ?? $k['keyword_text'] ?? ''), $keywords);
        $keywordTexts = array_filter($keywordTexts);

        if (count($keywordTexts) > 100) {
            $keywordTexts = array_slice($keywordTexts, 0, 100);
        }

        $keywordList = implode("\n", $keywordTexts);

        $prompt = <<<PROMPT
You are a Google Ads keyword clustering expert. Analyze these keywords and group them into clusters.

Keywords:
{$keywordList}

For each cluster, provide:
1. cluster_name: descriptive name for the ad group
2. intent: one of "informational", "navigational", "commercial", "transactional"
3. funnel_stage: one of "awareness", "consideration", "decision"
4. keywords: array of the keywords belonging to this cluster
5. recommended_ad_group: true/false — whether this cluster is tight enough for a dedicated ad group

Return ONLY valid JSON in this format:
{
  "clusters": [
    {
      "cluster_name": "...",
      "intent": "...",
      "funnel_stage": "...",
      "keywords": ["..."],
      "recommended_ad_group": true
    }
  ]
}
PROMPT;

        try {
            $result = $this->gemini->generateContent(
                config('ai.models.default'),
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 4096],
            );

            if (!$result || empty($result['text'])) {
                return $this->fallbackClusters($keywordTexts);
            }

            $parsed = $this->parseJson($result['text']);

            if (isset($parsed['clusters']) && !empty($parsed['clusters'])) {
                return $parsed;
            }

            return $this->fallbackClusters($keywordTexts);
        } catch (\Exception $e) {
            Log::warning('KeywordClusteringService: AI clustering failed', ['error' => $e->getMessage()]);
            return $this->fallbackClusters($keywordTexts);
        }
    }

    protected function fallbackClusters(array $keywords): array
    {
        return [
            'clusters' => [
                [
                    'cluster_name' => 'General',
                    'intent' => 'commercial',
                    'funnel_stage' => 'consideration',
                    'keywords' => $keywords,
                    'recommended_ad_group' => false,
                ],
            ],
        ];
    }

    protected function parseJson(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : [];
    }
}
