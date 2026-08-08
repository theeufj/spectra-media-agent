<?php

namespace App\Services\Agents\Google;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\GetAndLinkLocationAssets;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Enums\PriceExtensionPriceQualifierEnum\PriceExtensionPriceQualifier;
use Google\Ads\GoogleAds\V22\Enums\PriceExtensionPriceUnitEnum\PriceExtensionPriceUnit;
use Google\Ads\GoogleAds\V22\Enums\PriceExtensionTypeEnum\PriceExtensionType;
use Illuminate\Support\Facades\Log;

/**
 * Creates and links Google Ads assets (sitelinks, callouts, snippets, call,
 * promotion, price and location extensions), generating copy with Gemini where
 * the strategy does not supply it.
 */
class AdExtensionBuilder
{
    public function __construct(
        protected Customer $customer,
        protected GeminiService $gemini,
    ) {}

    public function createAndLinkAdExtensions(
        string $customerId,
        string $campaignResourceName,
        Strategy $strategy,
        ExecutionResult $result
    ): void {
        $createSitelinkService = new CreateSitelinkAsset($this->customer);
        $createCalloutService = new CreateCalloutAsset($this->customer);
        $linkAssetService = new LinkCampaignAsset($this->customer);

        $landingUrl = $strategy->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $this->customer->website
            ?? null;

        // 1. Sitelinks — prefer strategy-defined ones, fall back to business-relevant defaults
        $sitelinks = $strategy->bidding_strategy['sitelinks'] ?? [];

        if (empty($sitelinks)) {
            $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
            $descriptions = $adCopy?->descriptions ?? [];

            // Build sitelinks from ad copy descriptions where available, otherwise use
            // product-appropriate defaults (not generic retail placeholders).
            $sitelinks = [
                [
                    'text' => 'How It Works',
                    'desc1' => $descriptions[0] ?? 'See the AI in action',
                    'desc2' => 'Setup takes under 5 minutes',
                ],
                [
                    'text' => 'Pricing',
                    'desc1' => 'Transparent, no retainer fees',
                    'desc2' => 'Pay only for what you use',
                ],
                [
                    'text' => 'Start Free Trial',
                    'desc1' => $descriptions[1] ?? 'No credit card required',
                    'desc2' => 'Cancel anytime',
                ],
                [
                    'text' => 'Case Studies',
                    'desc1' => 'Real results from real customers',
                    'desc2' => 'See the ROI data',
                ],
            ];
        }

        foreach ($sitelinks as $sitelink) {
            try {
                $url = $sitelink['url'] ?? $landingUrl;
                if (! $url) {
                    Log::warning('GoogleAdsExecutionAgent: Skipping sitelink — no URL available and customer has no website');

                    continue;
                }

                $assetResourceName = ($createSitelinkService)(
                    $customerId,
                    $sitelink['text'],
                    $sitelink['desc1'],
                    $sitelink['desc2'],
                    $url
                );

                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::SITELINK);
                    $result->addPlatformId('sitelink_asset', $assetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link sitelink: '.$e->getMessage());
            }
        }

        // 2. Callouts — prefer strategy-defined ones, fall back to product-appropriate defaults
        $callouts = $strategy->bidding_strategy['callouts'] ?? [
            'No Agency Retainers',
            'AI-Powered Ad Management',
            'Cancel Anytime',
            'Setup in Minutes',
        ];

        foreach ($callouts as $text) {
            try {
                $assetResourceName = ($createCalloutService)($customerId, $text);

                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::CALLOUT);
                    $result->addPlatformId('callout_asset', $assetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link callout: '.$e->getMessage());
            }
        }

        // 3. Structured Snippets — zero-cost, improves ad quality score and CTR
        $snippets = $strategy->bidding_strategy['structured_snippets'] ?? [];

        if (empty($snippets)) {
            // Build from strategy bidding_strategy service list or ad copy, else use business-appropriate defaults
            $serviceList = $strategy->bidding_strategy['services'] ?? [];
            $snippets = [
                [
                    'header' => 'Services',
                    'values' => ! empty($serviceList)
                        ? array_slice($serviceList, 0, 10)
                        : ['Google Ads', 'Facebook Ads', 'LinkedIn Ads', 'Microsoft Ads', 'AI Optimisation'],
                ],
            ];
        }

        $createSnippetService = new CreateStructuredSnippetAsset($this->customer);
        foreach ($snippets as $snippet) {
            try {
                $values = array_slice($snippet['values'] ?? [], 0, 10);
                if (empty($values) || empty($snippet['header'])) {
                    continue;
                }
                $assetResourceName = ($createSnippetService)($customerId, $snippet['header'], $values);
                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::STRUCTURED_SNIPPET);
                    $result->addPlatformId('structured_snippet_asset', $assetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link structured snippet: '.$e->getMessage());
            }
        }

        // 4. Call Extension — only when customer has a phone number on file
        $phone = $this->customer->phone ?? null;
        if ($phone) {
            try {
                $createCallService = new CreateCallAsset($this->customer);
                $countryCode = $strategy->bidding_strategy['call_country_code'] ?? 'US';
                $callAssetResourceName = ($createCallService)($customerId, $phone, $countryCode);
                if ($callAssetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $callAssetResourceName, AssetFieldType::CALL);
                    $result->addPlatformId('call_asset', $callAssetResourceName);
                }
            } catch (\Exception $e) {
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link call asset: '.$e->getMessage());
            }
        }

        // 5. Promotion Extension — strategy-defined or AI-generated
        $this->createPromotionExtension($customerId, $campaignResourceName, $strategy, $landingUrl, $result);

        // 6. Price Extension — strategy-defined or AI-generated pricing tiers
        $this->createPriceExtension($customerId, $campaignResourceName, $strategy, $landingUrl, $result);

        // 7. Location Extension — links synced Business Profile location assets if GBP is connected
        $this->createLocationExtension($customerId, $campaignResourceName, $result);
    }

    protected function createPromotionExtension(
        string $customerId,
        string $campaignResourceName,
        Strategy $strategy,
        ?string $landingUrl,
        ExecutionResult $result
    ): void {
        // Strategy can define explicit promotion; fall back to AI generation
        $promotionData = $strategy->bidding_strategy['promotion'] ?? null;

        if (! $promotionData) {
            $promotionData = $this->generatePromotionWithAI($strategy);
        }

        if (! $promotionData || ($promotionData['skip'] ?? false)) {
            return;
        }

        try {
            $createService = new CreatePromotionAsset($this->customer);
            $target = $promotionData['promotion_target'] ?? $promotionData['target'] ?? null;
            if (! $target) {
                return;
            }

            $payload = [
                'language_code' => $promotionData['language_code'] ?? 'en',
            ];
            if (! empty($promotionData['percent_off'])) {
                $payload['percent_off'] = (int) $promotionData['percent_off'];
            } elseif (! empty($promotionData['money_amount_off'])) {
                $payload['money_amount_off'] = $promotionData['money_amount_off'];
            } else {
                return; // Google requires one discount type
            }
            if (! empty($promotionData['promotion_code'])) {
                $payload['promotion_code'] = $promotionData['promotion_code'];
            }
            if (! empty($promotionData['start_date'])) {
                $payload['start_date'] = $promotionData['start_date'];
            }
            if (! empty($promotionData['end_date'])) {
                $payload['end_date'] = $promotionData['end_date'];
            }
            if ($landingUrl) {
                $payload['final_url'] = $landingUrl;
            }

            $assetResourceName = ($createService)($customerId, $target, $payload);
            if ($assetResourceName) {
                (new LinkCampaignAsset($this->customer))($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::PROMOTION);
                $result->addPlatformId('promotion_asset', $assetResourceName);
                Log::info("GoogleAdsExecutionAgent: Created promotion extension: {$target}");
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Failed to create/link promotion asset: '.$e->getMessage());
        }
    }

    protected function generatePromotionWithAI(Strategy $strategy): ?array
    {
        $businessName = $this->customer->name;
        $businessType = $this->customer->business_type ?? 'business';
        $adCopyStrategy = $strategy->ad_copy_strategy ?? '';

        $prompt = <<<PROMPT
Generate a Google Ads Promotion Extension for this business.

Business: {$businessName}
Type: {$businessType}
Ad Copy Strategy: {$adCopyStrategy}

Rules:
- Only suggest a promotion that a business of this type would realistically offer
- For SaaS/software: use percent_off 1000000 (100%) for a free trial period
- For service businesses: use percent_off between 100000 (10%) and 300000 (30%)
- For e-commerce: use a realistic percentage or money amount
- If no believable promotion fits this business, set skip to true
- percent_off is in millionths (20% = 200000, 100% = 1000000)
- promotion_code is optional but recommended

Return JSON only:
{
  "skip": false,
  "promotion_target": "14-Day Free Trial",
  "percent_off": 1000000,
  "promotion_code": "TRIAL14",
  "language_code": "en"
}
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                config('ai.models.default'),
                $prompt,
                ['temperature' => 0.3, 'responseMimeType' => 'application/json'],
            );
            if ($response && isset($response['text'])) {
                return json_decode($response['text'], true) ?: null;
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: AI promotion generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function createPriceExtension(
        string $customerId,
        string $campaignResourceName,
        Strategy $strategy,
        ?string $landingUrl,
        ExecutionResult $result
    ): void {
        // Strategy can define explicit pricing; fall back to AI generation
        $pricingData = $strategy->bidding_strategy['pricing'] ?? null;

        if (! $pricingData) {
            $pricingData = $this->generatePricingWithAI($strategy, $landingUrl);
        }

        if (! $pricingData || ($pricingData['skip'] ?? false) || empty($pricingData['offerings'])) {
            return;
        }

        try {
            $currencyCode = $this->customer->currency_code ?? 'USD';

            $offerings = array_map(function (array $o) use ($landingUrl, $currencyCode): array {
                return [
                    'header' => substr($o['header'] ?? '', 0, 25),
                    'description' => substr($o['description'] ?? '', 0, 25),
                    'price_micros' => (int) ($o['price_micros'] ?? 0),
                    'currency_code' => $o['currency_code'] ?? $currencyCode,
                    'unit' => $o['unit'] ?? PriceExtensionPriceUnit::PER_MONTH,
                    'final_url' => $o['final_url'] ?? $landingUrl ?? '',
                ];
            }, array_slice($pricingData['offerings'], 0, 8));

            // Filter out offerings with no price or URL
            $offerings = array_values(array_filter($offerings, fn ($o) => $o['price_micros'] > 0 && $o['final_url']));

            if (count($offerings) < 3) {
                Log::info('GoogleAdsExecutionAgent: Skipping price extension — fewer than 3 valid offerings');

                return;
            }

            $createService = new CreatePriceAsset($this->customer);
            $type = $pricingData['type'] ?? PriceExtensionType::SERVICE_TIERS;
            $qualifier = $pricingData['qualifier'] ?? PriceExtensionPriceQualifier::FROM;

            $assetResourceName = ($createService)($customerId, $type, $qualifier, $offerings);
            if ($assetResourceName) {
                (new LinkCampaignAsset($this->customer))($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::PRICE);
                $result->addPlatformId('price_asset', $assetResourceName);
                Log::info('GoogleAdsExecutionAgent: Created price extension with '.count($offerings).' tiers');
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Failed to create/link price asset: '.$e->getMessage());
        }
    }

    protected function generatePricingWithAI(Strategy $strategy, ?string $landingUrl): ?array
    {
        $businessName = $this->customer->name;
        $businessType = $this->customer->business_type ?? 'business';
        $industry = $this->customer->industry ?? '';
        $currencyCode = $this->customer->currency_code ?? 'USD';
        $adCopyStrategy = $strategy->ad_copy_strategy ?? '';

        $prompt = <<<PROMPT
Generate Google Ads Price Extension data for this business.

Business: {$businessName}
Type: {$businessType}
Industry: {$industry}
Currency: {$currencyCode}
Ad Copy Strategy: {$adCopyStrategy}
Landing URL: {$landingUrl}

Rules:
- Only generate prices you can confidently infer from the business type/industry
- price_micros is price in micros (e.g. \$99/month = 99000000)
- Provide 3-5 service tiers or product categories
- unit: 5 = PER_MONTH, 6 = PER_YEAR, 2 = PER_HOUR, 0 = UNSPECIFIED
- type: 10 = SERVICE_TIERS, 8 = SERVICES, 6 = PRODUCT_CATEGORIES
- qualifier: 2 = FROM, 3 = UP_TO
- If you cannot confidently determine realistic pricing, set skip to true
- header max 25 characters, description max 25 characters

Return JSON only:
{
  "skip": false,
  "type": 10,
  "qualifier": 2,
  "offerings": [
    {"header": "Starter", "description": "3 campaigns", "price_micros": 99000000, "currency_code": "USD", "unit": 5, "final_url": "https://example.com/pricing"},
    {"header": "Professional", "description": "10 campaigns", "price_micros": 299000000, "currency_code": "USD", "unit": 5, "final_url": "https://example.com/pricing"},
    {"header": "Enterprise", "description": "Unlimited", "price_micros": 999000000, "currency_code": "USD", "unit": 5, "final_url": "https://example.com/pricing"}
  ]
}
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                config('ai.models.default'),
                $prompt,
                ['temperature' => 0.3, 'responseMimeType' => 'application/json'],
            );
            if ($response && isset($response['text'])) {
                return json_decode($response['text'], true) ?: null;
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: AI pricing generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function createLocationExtension(
        string $customerId,
        string $campaignResourceName,
        ExecutionResult $result
    ): void {
        try {
            $service = new GetAndLinkLocationAssets($this->customer);
            $linked = ($service)($customerId, $campaignResourceName);

            if ($linked > 0) {
                $result->addPlatformId('location_assets_linked', (string) $linked);
                Log::info("GoogleAdsExecutionAgent: Linked {$linked} Business Profile location asset(s)", [
                    'campaign' => $campaignResourceName,
                ]);
            } else {
                Log::info('GoogleAdsExecutionAgent: No Business Profile location assets found — connect Business Profile in Google Ads UI (Tools → Linked accounts → Business Profile) to enable location extensions');
            }
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Location extension setup failed: '.$e->getMessage());
        }
    }
}
