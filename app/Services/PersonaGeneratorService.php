<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Campaign;
use App\Models\Persona;
use Illuminate\Support\Facades\Log;

class PersonaGeneratorService
{
    protected GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Generate audience personas for a customer/campaign using Gemini AI.
     */
    public function generate(Customer $customer, ?Campaign $campaign = null, int $count = 4): array
    {
        $brandGuidelines = $customer->brandGuideline;

        $prompt = "You are an expert digital marketing strategist. Generate {$count} distinct audience personas for an advertising campaign.\n\n";

        if ($brandGuidelines) {
            $prompt .= "Brand Information:\n";
            $prompt .= "- Voice/Tone: " . ($brandGuidelines->brand_voice ?? 'Professional') . "\n";
            $prompt .= "- Target Audience: " . ($brandGuidelines->target_audience ?? 'General') . "\n";
            $prompt .= "- USPs: " . implode(', ', $brandGuidelines->unique_selling_propositions ?? []) . "\n";
            $prompt .= "- Messaging Themes: " . implode(', ', $brandGuidelines->messaging_themes ?? []) . "\n";
        }

        if ($campaign) {
            $prompt .= "\nCampaign Context:\n";
            $prompt .= "- Name: {$campaign->name}\n";
            $prompt .= "- Goal: " . ($campaign->business_goal ?? 'Conversions') . "\n";
            $prompt .= "- Industry: " . ($customer->industry ?? 'Not specified') . "\n";

            // Include product context if pages are selected
            $pages = $campaign->pages;
            if ($pages->isNotEmpty()) {
                $prompt .= "- Products/Services:\n";
                foreach ($pages->take(5) as $page) {
                    $prompt .= "  - {$page->title}: {$page->meta_description}\n";
                }
            }
        }

        // Include competitor insights if available
        if ($customer->competitor_domains) {
            $prompt .= "\nKnown Competitors: " . implode(', ', $customer->competitor_domains) . "\n";
        }

        $prompt .= "\nFor each persona, respond with a JSON array of objects. Each object must have:\n";
        $prompt .= "- \"name\": A memorable persona name (e.g. \"Budget-Conscious Buyer\")\n";
        $prompt .= "- \"description\": 2-3 sentence description of who this person is\n";
        $prompt .= "- \"demographics\": object with keys: age_range, gender, income_level, location_type, education\n";
        $prompt .= "- \"psychographics\": object with keys: values (array), interests (array), lifestyle (string)\n";
        $prompt .= "- \"pain_points\": array of 3-4 specific pain points\n";
        $prompt .= "- \"messaging_angle\": A specific messaging approach that would resonate with this persona\n";
        $prompt .= "- \"tone_adjustments\": object with keys: formality (casual/balanced/formal), urgency (low/medium/high), emotion (rational/balanced/emotional)\n\n";
        $prompt .= "Make each persona distinctly different to cover different audience segments. Return ONLY the JSON array.";

        try {
            $response = $this->gemini->generateContent('gemini-3-flash-preview', $prompt);
            $text = $response['text'] ?? $response;
            $cleaned = preg_replace('/^```json\s*|\s*```$/', '', trim($text));
            $personas = json_decode($cleaned, true);

            if (!is_array($personas) || empty($personas)) {
                // Try extracting JSON array from response
                if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
                    $personas = json_decode($matches[0], true);
                }
            }

            if (!is_array($personas) || empty($personas)) {
                Log::warning('PersonaGeneratorService: Failed to parse Gemini response', ['response' => $text]);
                return [];
            }

            $created = [];
            foreach ($personas as $personaData) {
                $persona = Persona::create([
                    'customer_id' => $customer->id,
                    'campaign_id' => $campaign?->id,
                    'name' => $personaData['name'] ?? 'Unnamed Persona',
                    'description' => $personaData['description'] ?? '',
                    'demographics' => $personaData['demographics'] ?? null,
                    'psychographics' => $personaData['psychographics'] ?? null,
                    'pain_points' => $personaData['pain_points'] ?? null,
                    'messaging_angle' => $personaData['messaging_angle'] ?? null,
                    'tone_adjustments' => $personaData['tone_adjustments'] ?? null,
                    'source' => 'ai_generated',
                ]);
                $created[] = $persona;
            }

            Log::info('PersonaGeneratorService: Generated ' . count($created) . ' personas', [
                'customer_id' => $customer->id,
                'campaign_id' => $campaign?->id,
            ]);

            return $created;
        } catch (\Exception $e) {
            Log::error('PersonaGeneratorService: Generation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
