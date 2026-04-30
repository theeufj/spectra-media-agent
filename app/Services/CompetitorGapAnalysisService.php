<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Competitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Generates a structured gap analysis comparing the customer's business
 * against their pinned War Room competitors.
 */
class CompetitorGapAnalysisService
{
    protected GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Generate gap analysis and persist to the customer record.
     */
    public function generate(Customer $customer, Collection $competitors): array
    {
        Log::info('CompetitorGapAnalysisService: Generating gap analysis', [
            'customer_id' => $customer->id,
            'competitor_count' => $competitors->count(),
        ]);

        $prompt = $this->buildPrompt($customer, $competitors);

        $response = $this->gemini->generateContent(
            config('ai.models.default'),
            $prompt,
            ['responseMimeType' => 'application/json'],
            'You are an expert competitive intelligence analyst specializing in digital advertising strategy. Produce precise, actionable gap analysis.',
            true
        );

        if (!$response || !isset($response['text'])) {
            Log::error('CompetitorGapAnalysisService: No AI response');
            return ['status' => 'error', 'message' => 'Failed to generate gap analysis'];
        }

        $analysis = $this->parseJson($response['text']);

        if (empty($analysis)) {
            return ['status' => 'error', 'message' => 'Failed to parse gap analysis'];
        }

        // Merge in auction metrics from the database (not AI-generated)
        $analysis['competitors'] = $competitors->map(fn (Competitor $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'domain' => $c->domain,
            'impression_share' => $c->impression_share,
            'overlap_rate' => $c->overlap_rate,
            'position_above_rate' => $c->position_above_rate,
            'last_analyzed_at' => $c->last_analyzed_at?->toIso8601String(),
        ])->values()->toArray();

        $customer->update([
            'war_room_gap_analysis' => $analysis,
            'war_room_gap_analysis_at' => now(),
        ]);

        return ['status' => 'success', 'analysis' => $analysis];
    }

    protected function buildPrompt(Customer $customer, Collection $competitors): string
    {
        $ourContext = $this->getOurContext($customer);
        $competitorData = $competitors->map(fn (Competitor $c) => [
            'name' => $c->name,
            'domain' => $c->domain,
            'messaging' => $c->messaging_analysis,
            'value_propositions' => $c->value_propositions,
            'keywords' => $c->keywords_detected,
            'pricing' => $c->pricing_info,
            'impression_share' => $c->impression_share,
        ])->toArray();

        $ourJson = json_encode($ourContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $competitorJson = json_encode($competitorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
**COMPETITIVE GAP ANALYSIS REQUEST**

**Our Business:**
{$ourJson}

**Competitor Intelligence ({$competitors->count()} competitors):**
{$competitorJson}

---

Analyze the gaps between our business and each competitor. Produce a structured gap analysis.

**RESPONSE FORMAT (JSON):**
{
  "summary": "2-3 sentence overall competitive position summary",

  "keyword_gaps": [
    {
      "keyword": "keyword phrase",
      "competitor": "competitor name targeting it",
      "opportunity": "high|medium|low",
      "recommended_action": "brief action"
    }
  ],

  "messaging_gaps": [
    {
      "gap": "What messaging angle we're missing",
      "competitors_using": ["competitor names"],
      "recommended_angle": "How we should address it",
      "priority": "high|medium|low"
    }
  ],

  "pricing_comparison": [
    {
      "competitor": "competitor name",
      "their_positioning": "How they position on price",
      "our_advantage": "How we compare or can counter"
    }
  ],

  "strengths_to_exploit": [
    {
      "strength": "Our advantage",
      "competitors_weak_on": ["competitor names"],
      "ad_copy_angle": "Suggested messaging"
    }
  ],

  "counter_strategies": [
    {
      "competitor": "competitor name",
      "their_weakness": "What they lack",
      "our_play": "How to attack this gap",
      "headline_example": "Example ad headline",
      "description_example": "Example ad description"
    }
  ],

  "quick_wins": ["immediate action 1", "immediate action 2", "immediate action 3"]
}

Only include data you can derive from the competitor intelligence provided. Do not fabricate keywords or pricing data.
PROMPT;
    }

    protected function getOurContext(Customer $customer): array
    {
        $context = [
            'name' => $customer->name,
            'website' => $customer->website,
            'business_type' => $customer->business_type,
            'description' => $customer->description,
        ];

        if ($customer->brandGuideline) {
            $context['usps'] = $customer->brandGuideline->unique_selling_propositions;
            $context['target_audience'] = $customer->brandGuideline->target_audience;
            $context['brand_voice'] = $customer->brandGuideline->brand_voice;
        }

        // Include our current keywords if available
        if ($customer->competitive_strategy) {
            $context['current_keywords'] = $customer->competitive_strategy['keyword_strategy'] ?? null;
        }

        return $context;
    }

    protected function parseJson(string $text): array
    {
        $cleaned = trim($text);

        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        return json_decode(trim($cleaned), true) ?? [];
    }
}
