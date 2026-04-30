<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Models\Customer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class KeywordResearchService
{
    protected Customer $customer;
    protected GeminiService $gemini;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = new GeminiService();
    }

    /**
     * Research keywords for a business using Gemini AI + Google Ads Keyword Planner.
     *
     * @param string $customerId Google Ads customer ID
     * @param string $businessName Business name
     * @param string|null $industry Industry or vertical description
     * @param string|null $landingPageUrl Landing page URL for context
     * @param string|null $language Language resource name (default: English)
     * @param array $geoTargets Geo target constants (default: empty for global)
     * @param int $maxKeywords Maximum keywords to return
     * @return array ['keywords' => [...], 'negative_keywords' => [...]]
     */
    public function research(
        string $customerId,
        string $businessName,
        ?string $industry = null,
        ?string $landingPageUrl = null,
        ?string $language = 'languageConstants/1000',
        array $geoTargets = [],
        int $maxKeywords = 20
    ): array {
        // Step 1: Generate seed keywords using Gemini AI
        $seedKeywords = $this->generateSeedKeywords($businessName, $industry, $landingPageUrl);

        if (empty($seedKeywords)) {
            Log::warning("KeywordResearchService: Gemini returned no seed keywords for '{$businessName}'");
            return ['keywords' => [], 'negative_keywords' => []];
        }

        Log::info("KeywordResearchService: Generated " . count($seedKeywords) . " seed keywords via Gemini", [
            'seeds' => $seedKeywords,
        ]);

        // Step 2: Expand via Google Keyword Planner
        $keywordIdeas = $this->expandWithKeywordPlanner(
            $customerId, $seedKeywords, $landingPageUrl, $language, $geoTargets, $maxKeywords * 3
        );

        // Step 3: If Keyword Planner returned data, rank and select best
        if (!empty($keywordIdeas)) {
            $keywords = $this->rankAndSelect($keywordIdeas, $maxKeywords);
        } else {
            // Fallback: use seed keywords directly with recommended match types
            Log::info("KeywordResearchService: Keyword Planner returned no results, using Gemini seeds directly");
            $keywords = $this->seedsToKeywords(array_slice($seedKeywords, 0, $maxKeywords));
        }

        // Step 4: Generate negative keywords
        $negativeKeywords = $this->generateNegativeKeywords($businessName, $industry);

        return [
            'keywords' => $keywords,
            'negative_keywords' => $negativeKeywords,
        ];
    }

    /**
     * Use Gemini to generate seed keywords from business context.
     */
    protected function generateSeedKeywords(string $businessName, ?string $industry, ?string $landingPageUrl): array
    {
        $prompt = "You are a Google Ads keyword research expert. Generate exactly 15 seed keywords for a Google Search campaign.\n\n";
        $prompt .= "Business: {$businessName}\n";
        if ($industry) {
            $prompt .= "Industry: {$industry}\n";
        }
        if ($landingPageUrl) {
            $prompt .= "Landing Page: {$landingPageUrl}\n";
        }
        $prompt .= "\nRequirements:\n";
        $prompt .= "- Focus on high commercial intent keywords (people ready to buy/enquire)\n";
        $prompt .= "- Include a mix of: brand-adjacent, service/product, problem-solution keywords\n";
        $prompt .= "- Keep keywords 2-5 words each\n";
        $prompt .= "- No branded competitor terms\n";
        $prompt .= "\nReturn ONLY a JSON array of keyword strings, no explanation. Example: [\"keyword one\", \"keyword two\"]";

        $result = $this->gemini->generateContent(
            config('ai.models.default'),
            $prompt,
            ['temperature' => 0.7, 'maxOutputTokens' => 1024],
        );

        if (!$result || empty($result['text'])) {
            return [];
        }

        return $this->parseJsonArray($result['text']);
    }

    /**
     * Expand seed keywords via Google Ads Keyword Planner API.
     */
    protected function expandWithKeywordPlanner(
        string $customerId,
        array $seedKeywords,
        ?string $url,
        ?string $language,
        array $geoTargets,
        int $maxResults
    ): array {
        try {
            $service = new GenerateKeywordIdeas($this->customer);
            return ($service)($customerId, $seedKeywords, $url, $language, $geoTargets, $maxResults);
        } catch (\Exception $e) {
            Log::warning("KeywordResearchService: Keyword Planner unavailable, using seeds only", [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Rank keyword ideas by relevance/opportunity and select the best.
     */
    protected function rankAndSelect(array $keywordIdeas, int $max): array
    {
        // Score each keyword: balance of volume, competition (lower = better), CPC
        // Filter out keywords with insufficient search volume
        $keywordIdeas = array_filter($keywordIdeas, function ($idea) {
            return ($idea['avg_monthly_searches'] ?? 0) >= 10;
        });

        $scored = array_map(function ($idea) {
            $volume = $idea['avg_monthly_searches'] ?? 0;
            $competitionIndex = $idea['competition_index'] ?? 50;
            $cpc = ($idea['average_cpc_micros'] ?? 0) / 1_000_000;

            // Opportunity score: higher volume + lower competition = better
            // Normalize: volume log scale (avoid log(0)), competition inverse
            $volumeScore = $volume > 0 ? log10($volume + 1) * 25 : 0;
            $competitionScore = (100 - $competitionIndex);
            $score = $volumeScore + $competitionScore;

            return array_merge($idea, [
                'score' => $score,
                'recommended_match_type' => $this->recommendMatchType($volume, $competitionIndex, $cpc),
            ]);
        }, $keywordIdeas);

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Take top N and format
        return array_map(function ($idea) {
            return [
                'text' => $idea['keyword'],
                'match_type' => $idea['recommended_match_type'],
                'avg_monthly_searches' => $idea['avg_monthly_searches'],
                'competition_index' => $idea['competition_index'],
            ];
        }, array_slice($scored, 0, $max));
    }

    /**
     * Recommend match type based on keyword metrics.
     */
    protected function recommendMatchType(int $volume, int $competitionIndex, float $cpc): string
    {
        // High volume + low competition → BROAD (maximize reach)
        if ($volume > 1000 && $competitionIndex < 30) {
            return 'BROAD';
        }
        // High competition or expensive → EXACT (control spend)
        if ($competitionIndex > 70 || $cpc > 5.0) {
            return 'EXACT';
        }
        // Default → PHRASE (balanced)
        return 'PHRASE';
    }

    /**
     * Convert raw seed strings to keyword array with default match types.
     */
    protected function seedsToKeywords(array $seeds): array
    {
        return array_map(function ($seed, $i) {
            // First 2 as EXACT, next 3 as PHRASE, rest as BROAD
            if ($i < 2) {
                $matchType = 'EXACT';
            } elseif ($i < 5) {
                $matchType = 'PHRASE';
            } else {
                $matchType = 'BROAD';
            }
            return [
                'text' => $seed,
                'match_type' => $matchType,
                'avg_monthly_searches' => null,
                'competition_index' => null,
            ];
        }, $seeds, array_keys($seeds));
    }

    /**
     * Generate negative keywords using Gemini AI.
     */
    protected function generateNegativeKeywords(string $businessName, ?string $industry): array
    {
        $prompt = "You are a Google Ads negative keyword expert. Generate exactly 15 negative keywords for a Search campaign.\n\n";
        $prompt .= "Business: {$businessName}\n";
        if ($industry) {
            $prompt .= "Industry: {$industry}\n";
        }
        $prompt .= "\nGenerate negative keywords that would waste ad spend — queries from people NOT looking to buy.\n";
        $prompt .= "Include universal negatives (free, cheap, DIY, jobs, salary, reddit, wiki, how to, tutorial) ";
        $prompt .= "plus industry-specific negatives.\n";
        $prompt .= "\nReturn ONLY a JSON array of keyword strings. Example: [\"free\", \"jobs\", \"salary\"]";

        $result = $this->gemini->generateContent(
            config('ai.models.default'),
            $prompt,
            ['temperature' => 0.5, 'maxOutputTokens' => 512],
        );

        if (!$result || empty($result['text'])) {
            // Fallback: universal negative keywords
            return $this->getUniversalNegatives();
        }

        $negatives = $this->parseJsonArray($result['text']);
        return !empty($negatives) ? $negatives : $this->getUniversalNegatives();
    }

    /**
     * Universal negative keywords that apply to most businesses.
     */
    protected function getUniversalNegatives(): array
    {
        return [
            'free', 'cheap', 'diy', 'jobs', 'salary', 'career', 'hiring',
            'reddit', 'wiki', 'wikipedia', 'tutorial', 'how to', 'youtube',
            'download', 'torrent',
        ];
    }

    /**
     * Parse a JSON array from Gemini text response.
     */
    protected function parseJsonArray(string $text): array
    {
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (is_array($decoded) && !empty($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return [];
    }
}
