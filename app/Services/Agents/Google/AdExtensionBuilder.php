<?php

namespace App\Services\Agents\Google;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Campaigns\AdvertisingEvidence;
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
use Google\Ads\GoogleAds\V22\Enums\PriceExtensionTypeEnum\PriceExtensionType;
use Illuminate\Support\Facades\Log;

/**
 * Creates and links Google Ads assets (sitelinks, callouts, snippets, call,
 * promotion, price and location extensions) from the strategy and verified sources.
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
        $createSitelinkService = app(CreateSitelinkAsset::class, ['customer' => $this->customer]);
        $createCalloutService = app(CreateCalloutAsset::class, ['customer' => $this->customer]);
        $linkAssetService = app(LinkCampaignAsset::class, ['customer' => $this->customer]);

        $landingUrl = $strategy->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $this->customer->website
            ?? null;

        $evidence = app(AdvertisingEvidence::class);
        $extensions = $strategy->ad_extensions ?? [];
        // Read the same contract written by StrategyPrompt. Legacy data is accepted only as input to validation.
        $sitelinks = $evidence->sitelinks($this->customer, $extensions['sitelinks'] ?? $strategy->bidding_strategy['sitelinks'] ?? []);

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
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link sitelink: '.$e->getMessage());
            }
        }

        // 2. Callouts — use strategy-defined claims.
        $callouts = $extensions['callouts'] ?? $strategy->bidding_strategy['callouts'] ?? [];

        foreach ($callouts as $text) {
            try {
                $assetResourceName = ($createCalloutService)($customerId, $text);

                if ($assetResourceName) {
                    ($linkAssetService)($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::CALLOUT);
                    $result->addPlatformId('callout_asset', $assetResourceName);
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link callout: '.$e->getMessage());
            }
        }

        // 3. Structured Snippets — zero-cost, improves ad quality score and CTR
        $snippets = $extensions['structured_snippets'] ?? $strategy->bidding_strategy['structured_snippets'] ?? [];

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
            } catch (\Throwable $e) {
                report($e);
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
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GoogleAdsExecutionAgent: Failed to create/link call asset: '.$e->getMessage());
            }
        }

        // 5. Promotion Extension — verified catalogue sales only.
        $this->createPromotionExtension($customerId, $campaignResourceName, $strategy, $landingUrl, $result);

        // 6. Price Extension — verified catalogue prices only.
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
        $proposal = $strategy->ad_extensions['promotion'] ?? $strategy->bidding_strategy['promotion'] ?? [];
        $promotionData = app(AdvertisingEvidence::class)->promotion($this->customer, $proposal);
        if (! $promotionData) {
            if ($proposal) {
                $result->addWarning('unverified_promotion_omitted', 'Promotion omitted: no current, customer-owned catalogue offer supports it.');
            }

            return;
        }

        try {
            $createService = new CreatePromotionAsset($this->customer);
            $target = $promotionData['promotion_target'] ?? $promotionData['target'] ?? null;
            if (! $target) {
                return;
            }

            $payload = $promotionData;

            $assetResourceName = ($createService)($customerId, $target, $payload);
            if ($assetResourceName) {
                (app(LinkCampaignAsset::class, ['customer' => $this->customer]))($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::PROMOTION);
                $result->addPlatformId('promotion_asset', $assetResourceName);
                $result->metadata['verified_offer_details'][$assetResourceName] = ['kind' => 'PROMOTION', 'offer' => $promotionData];
                Log::info("GoogleAdsExecutionAgent: Created promotion extension: {$target}");
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Failed to create/link promotion asset: '.$e->getMessage());
        }
    }

    protected function createPriceExtension(
        string $customerId,
        string $campaignResourceName,
        Strategy $strategy,
        ?string $landingUrl,
        ExecutionResult $result
    ): void {
        $pricingData = $strategy->ad_extensions['pricing'] ?? $strategy->bidding_strategy['pricing'] ?? [];
        if (empty($pricingData['offerings'])) {
            return;
        }

        try {
            $evidence = app(AdvertisingEvidence::class);
            $offerings = array_values(array_filter(array_map(
                fn (array $offer) => $evidence->priceOffering($this->customer, $offer),
                array_slice($pricingData['offerings'], 0, 8)
            )));
            if (count($offerings) !== count($pricingData['offerings'])) {
                $result->addWarning('unverified_prices_omitted', 'Unverified price tiers were omitted. Advertising account currency is not a product price source.');
            }

            // Filter out offerings with no price or URL
            $offerings = array_values(array_filter($offerings, fn ($o) => $o['price_micros'] > 0 && $o['final_url']));
            $offerings = array_values(array_column($offerings, null, 'source_product_id'));

            if (count($offerings) < 3) {
                Log::info('GoogleAdsExecutionAgent: Skipping price extension — fewer than 3 valid offerings');

                return;
            }

            $createService = new CreatePriceAsset($this->customer);
            $type = PriceExtensionType::PRODUCT_CATEGORIES;
            $qualifier = $pricingData['qualifier'] ?? PriceExtensionPriceQualifier::FROM;

            $assetResourceName = ($createService)($customerId, $type, $qualifier, $offerings);
            if ($assetResourceName) {
                (app(LinkCampaignAsset::class, ['customer' => $this->customer]))($customerId, $campaignResourceName, $assetResourceName, AssetFieldType::PRICE);
                $result->addPlatformId('price_asset', $assetResourceName);
                $result->metadata['verified_offer_details'][$assetResourceName] = ['kind' => 'PRICE', 'offerings' => $offerings];
                Log::info('GoogleAdsExecutionAgent: Created price extension with '.count($offerings).' tiers');
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Failed to create/link price asset: '.$e->getMessage());
        }
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
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Location extension setup failed: '.$e->getMessage());
        }
    }
}
