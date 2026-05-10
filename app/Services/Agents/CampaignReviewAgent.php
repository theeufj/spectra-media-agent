<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * Pre-execution plan validator using Gemini Pro.
 *
 * Called between generateExecutionPlan() and executePlan() in both
 * FacebookAdsExecutionAgent and GoogleAdsExecutionAgent. Reviews the
 * AI-generated ExecutionPlan JSON for common mistakes and auto-corrects
 * them before any API calls are made.
 */
class CampaignReviewAgent
{
    private GeminiService $gemini;
    private Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = app(GeminiService::class);
    }

    /**
     * Review and auto-correct an ExecutionPlan before deployment.
     * Returns the corrected plan, or the original if the review model fails.
     */
    public function review(ExecutionPlan $plan, string $platform): ExecutionPlan
    {
        $rawJson = json_encode($plan->rawPlan, JSON_PRETTY_PRINT);

        $prompt = $this->buildPrompt($rawJson, $platform);

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.pro'),
                prompt: $prompt,
                config: [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 8192,
                ],
                systemInstruction: $this->getSystemInstruction($platform),
            );

            if (!$response || !isset($response['text'])) {
                Log::warning('[CampaignReviewAgent] Empty response from review model, using original plan', [
                    'platform' => $platform,
                    'customer_id' => $this->customer->id,
                ]);
                return $plan;
            }

            $corrected = ExecutionPlan::fromJson($response['text']);

            $changes = $this->detectChanges($plan->rawPlan, $corrected->rawPlan);
            if (!empty($changes)) {
                Log::info('[CampaignReviewAgent] Plan corrected before execution', [
                    'platform' => $platform,
                    'customer_id' => $this->customer->id,
                    'corrections' => $changes,
                ]);
            } else {
                Log::info('[CampaignReviewAgent] Plan passed review with no corrections', [
                    'platform' => $platform,
                    'customer_id' => $this->customer->id,
                ]);
            }

            return $corrected;

        } catch (\Exception $e) {
            Log::warning('[CampaignReviewAgent] Review failed, using original plan', [
                'platform' => $platform,
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
            return $plan;
        }
    }

    private function getSystemInstruction(string $platform): string
    {
        return "You are a senior paid media QA specialist. Your only job is to receive an ad campaign execution plan JSON and return a corrected version of that exact JSON. Fix any violations of the rules provided. Return ONLY valid JSON — no markdown fences, no commentary.";
    }

    private function buildPrompt(string $rawJson, string $platform): string
    {
        $platformRules = match ($platform) {
            'facebook' => $this->facebookRules(),
            'google'   => $this->googleRules(),
            default    => '',
        };

        return <<<PROMPT
You are reviewing a campaign execution plan JSON before it is sent to the {$platform} Ads API.

Apply the following rules and return a corrected version of the JSON. If a field already satisfies the rule, leave it unchanged.

{$platformRules}

## GENERAL RULES
- All budget values used in API calls must be in cents/micros as appropriate per platform.
- Campaign names must be descriptive and unique (no generic "Campaign Name" placeholders).

## INPUT PLAN JSON
{$rawJson}

Return ONLY the corrected JSON. No markdown code fences. No commentary. No additional text.
PROMPT;
    }

    private function facebookRules(): string
    {
        return <<<RULES
## FACEBOOK-SPECIFIC RULES

1. **Objective selection**
   - For new SaaS/B2B products with no pixel conversion data: set `campaign_structure.objective` to `OUTCOME_LEADS`
   - For e-commerce with pixel data showing purchases: use `OUTCOME_SALES`
   - For awareness/brand campaigns: use `OUTCOME_AWARENESS`
   - `OUTCOME_TRAFFIC` is only appropriate for content sites or blogs — NEVER for SaaS signup funnels
   - Never use legacy objectives: LINK_CLICKS, CONVERSIONS, REACH, ENGAGEMENT (these are invalid in API v18+)

2. **Primary text**
   - `creative_strategy.primary_text` must be non-empty and at least 20 characters
   - If missing or too short, write compelling ad copy (125 chars max) that speaks to the buyer's pain point, not product features
   - Focus on outcomes: "Stop paying agency retainers — get AI-powered ads that actually convert"

3. **Geographic targeting**
   - `targeting_strategy.geo_locations.countries` must contain at least one country
   - If missing or empty, default to `["US", "CA", "AU", "GB"]`
   - Never target all countries for a SaaS product priced in USD

4. **Optimization goal alignment**
   - For `OUTCOME_LEADS`: set `optimization_goal` to `LEAD_GENERATION`
   - For `OUTCOME_SALES`: set `optimization_goal` to `OFFSITE_CONVERSIONS`
   - For `OUTCOME_TRAFFIC`: set `optimization_goal` to `LANDING_PAGE_VIEWS`
   - For `OUTCOME_AWARENESS`: set `optimization_goal` to `REACH`
RULES;
    }

    private function googleRules(): string
    {
        return <<<RULES
## GOOGLE ADS-SPECIFIC RULES

1. **Campaign type**
   - `campaign_structure.type` must be one of: `search`, `performance_max`, `display`, `video`, `demand_gen`, `shopping`, `local_services`
   - Never use invalid types like `smart` or `universal`

2. **Geographic targeting**
   - `campaign_structure.locations` must contain at least one location name or Google criterion ID
   - If missing, default to `["United States", "Canada", "Australia", "United Kingdom"]`

3. **Bid caps**
   - If any `cpc_bid_micros` value appears in the plan and is below `2000000` (i.e. $2.00), raise it to `2000000`
   - For MAXIMIZE_CLICKS or MAXIMIZE_CONVERSIONS strategies, do not include a `cpc_bid_micros` cap at all

4. **Ad copy**
   - `creative_strategy.ads` should be an array with at least 2 RSA variants
   - Each RSA needs at minimum 5 headlines and 2 descriptions
   - Headlines must be ≤ 30 characters each; descriptions ≤ 90 characters each
RULES;
    }

    private function detectChanges(array $original, array $corrected): array
    {
        $changes = [];

        $checks = [
            'campaign_structure.objective',
            'campaign_structure.type',
            'campaign_structure.locations',
            'creative_strategy.primary_text',
            'targeting_strategy.geo_locations.countries',
        ];

        foreach ($checks as $path) {
            $keys = explode('.', $path);
            $origVal = $this->nestedGet($original, $keys);
            $corrVal = $this->nestedGet($corrected, $keys);

            if ($origVal !== $corrVal) {
                $changes[$path] = ['before' => $origVal, 'after' => $corrVal];
            }
        }

        return $changes;
    }

    private function nestedGet(array $data, array $keys): mixed
    {
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
}
