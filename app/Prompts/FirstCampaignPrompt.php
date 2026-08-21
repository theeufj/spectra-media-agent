<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use App\Models\Customer;

class FirstCampaignPrompt
{
    /**
     * Build a customer's first campaign from what the crawl already learned.
     *
     * This runs before the customer has asked for anything, so every field it
     * produces is something they will read as a claim about their own business.
     * Inventing a service they do not offer is worse than a vague campaign —
     * it tells them the product does not understand them, in the first email
     * they get.
     *
     * The budget is the one field with real consequences. It is not a display
     * value: deployment charges seven days of it up front. The model proposes
     * it and explains itself, a human confirms it, and nothing is charged in
     * between (see the budget_confirmed_at column).
     */
    public static function systemInstruction(): string
    {
        return <<<'SYSTEM'
You are a senior paid-search strategist writing a first advertising campaign brief for a
business, based only on their own website.

Everything you write will be shown to the business owner as a description of their own
company, so it must sound like it was written by someone who read their site — not like a
template with a name substituted in.

Hard rules:
1. Use ONLY what the provided material supports. Never invent a service, product,
   location, guarantee, price or credential that does not appear in it.
2. If the material is too thin to say something specific, say something general and true
   rather than something specific and invented.
3. No superlatives you cannot evidence ("the best", "award-winning", "market-leading")
   unless the site itself claims them.
4. Write in the second person about their business ("your team", "your customers").
5. The daily budget must be a realistic entry-level figure for this kind of business in
   this market — enough to gather signal, not so much that a first-time advertiser is
   alarmed. Explain the number in one sentence a non-marketer would accept. Remember it
   will be multiplied by seven and charged up front.
SYSTEM;
    }

    public static function generate(Customer $customer, ?BrandGuideline $brand, array $pageTitles): string
    {
        $brandBlock = $brand
            ? self::describeBrand($brand)
            : 'No brand guidelines were extracted — rely on the page titles alone and stay general.';

        $pages = $pageTitles !== []
            ? '- '.implode("\n- ", array_slice($pageTitles, 0, 40))
            : '(no pages captured)';

        $country = $customer->country ?: 'their local market';
        $industry = $customer->industry ?: 'not stated';
        $currency = $customer->currency_code ?: 'USD';

        return <<<PROMPT
Business: {$customer->name}
Website: {$customer->website}
Country: {$country}
Industry: {$industry}
Budget currency: {$currency}

{$brandBlock}

Pages found on their website:
{$pages}

Write their first Google Ads campaign brief. Return ONLY a JSON object, no prose, no code
fences, with exactly these keys:

{
  "name": "short campaign name, max 60 chars, no quotes",
  "reason": "why run this campaign, 1-2 sentences",
  "goals": "what it should achieve, 1-2 sentences, concrete",
  "target_market": "who to reach, 2-3 sentences",
  "voice": "the tone the ads should take, 1 sentence",
  "primary_kpi": "one measurable target, e.g. 'Cost per enquiry under \$60'",
  "product_focus": "what specifically to advertise, 1 sentence",
  "daily_budget": 50,
  "budget_rationale": "one sentence a non-marketer would accept for why this daily amount"
}

daily_budget must be a plain number in {$currency}, no symbols or separators.
PROMPT;
    }

    private static function describeBrand(BrandGuideline $brand): string
    {
        $parts = array_filter([
            'Brand voice' => $brand->brand_voice,
            'Tone' => self::flatten($brand->tone_attributes),
            'Audience they describe' => $brand->target_audience,
            'Messaging themes' => self::flatten($brand->messaging_themes),
            'What they say sets them apart' => self::flatten($brand->unique_selling_propositions),
            'Differentiators' => $brand->competitor_differentiation,
            'Never say' => self::flatten($brand->do_not_use),
        ]);

        $lines = [];
        foreach ($parts as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        return "What we learned from their site:\n".implode("\n", $lines);
    }

    /**
     * Brand guideline fields are sometimes arrays, sometimes JSON strings,
     * sometimes plain text depending on what the extractor got back.
     */
    private static function flatten(mixed $value): ?string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(
                fn ($v) => is_scalar($v) ? (string) $v : null,
                $value,
            ))) ?: null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
