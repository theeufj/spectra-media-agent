<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\GetExtensionPerformance;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Support\Facades\Log;

/**
 * Ensures every active Google Ads campaign has minimum extension coverage
 * and rotates underperforming assets using AI-generated replacements.
 *
 * Minimum coverage per campaign:
 *   - 4 sitelinks
 *   - 4 callouts
 *   - 1 call extension (if customer has a phone number)
 *   - 1 structured snippet
 *
 * Rotation threshold: assets with CTR < 40% of campaign average after 500+ impressions.
 */
class AdExtensionAgent
{
    private const MIN_SITELINKS  = 4;
    private const MIN_CALLOUTS   = 4;
    private const MIN_SNIPPETS   = 1;
    private const ROTATION_IMPRESSIONS_THRESHOLD = 500;
    private const ROTATION_CTR_RATIO = 0.40;

    public function __construct(private GeminiService $gemini) {}

    public function manage(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $customerId       = $customer->cleanGoogleCustomerId();
        $campaignResource = $campaign->google_ads_campaign_id;

        $created  = [];
        $rotated  = [];
        $errors   = [];

        $perfService  = new GetExtensionPerformance($customer);
        $linkService  = new LinkCampaignAsset($customer);

        // --- 1. Coverage check ---
        $sitelinkCount = $perfService->countByFieldType($customerId, $campaignResource, AssetFieldType::SITELINK);
        $calloutCount  = $perfService->countByFieldType($customerId, $campaignResource, AssetFieldType::CALLOUT);
        $snippetCount  = $perfService->countByFieldType($customerId, $campaignResource, AssetFieldType::STRUCTURED_SNIPPET);
        $callCount     = $perfService->countByFieldType($customerId, $campaignResource, AssetFieldType::CALL);

        $businessContext = $this->buildBusinessContext($campaign);

        if ($sitelinkCount < self::MIN_SITELINKS) {
            $needed = self::MIN_SITELINKS - $sitelinkCount;
            $generated = $this->generateSitelinks($customer, $campaign, $businessContext, $needed);
            foreach ($generated as $sitelink) {
                $service = new CreateSitelinkAsset($customer);
                $assetResource = ($service)(
                    $customerId,
                    $sitelink['link_text'],
                    $sitelink['description1'],
                    $sitelink['description2'],
                    $sitelink['url']
                );
                if ($assetResource) {
                    ($linkService)($customerId, $campaignResource, $assetResource, AssetFieldType::SITELINK);
                    $created[] = ['type' => 'sitelink', 'text' => $sitelink['link_text']];
                } else {
                    $errors[] = 'Failed to create sitelink: ' . $sitelink['link_text'];
                }
            }
        }

        if ($calloutCount < self::MIN_CALLOUTS) {
            $needed  = self::MIN_CALLOUTS - $calloutCount;
            $callouts = $this->generateCallouts($customer, $campaign, $businessContext, $needed);
            foreach ($callouts as $text) {
                $service = new CreateCalloutAsset($customer);
                $assetResource = ($service)($customerId, $text);
                if ($assetResource) {
                    ($linkService)($customerId, $campaignResource, $assetResource, AssetFieldType::CALLOUT);
                    $created[] = ['type' => 'callout', 'text' => $text];
                } else {
                    $errors[] = 'Failed to create callout: ' . $text;
                }
            }
        }

        if ($snippetCount < self::MIN_SNIPPETS) {
            $snippet = $this->generateStructuredSnippet($customer, $campaign, $businessContext);
            if ($snippet) {
                $service = new CreateStructuredSnippetAsset($customer);
                $assetResource = ($service)($customerId, $snippet['header'], $snippet['values']);
                if ($assetResource) {
                    ($linkService)($customerId, $campaignResource, $assetResource, AssetFieldType::STRUCTURED_SNIPPET);
                    $created[] = ['type' => 'structured_snippet', 'header' => $snippet['header']];
                } else {
                    $errors[] = 'Failed to create structured snippet';
                }
            }
        }

        if ($callCount < 1 && $customer->phone) {
            $service = new CreateCallAsset($customer);
            $assetResource = ($service)($customerId, $customer->phone, $customer->country ?? 'AU');
            if ($assetResource) {
                ($linkService)($customerId, $campaignResource, $assetResource, AssetFieldType::CALL);
                $created[] = ['type' => 'call', 'phone' => $customer->phone];
            } else {
                $errors[] = 'Failed to create call extension';
            }
        }

        // --- 2. Performance rotation ---
        $assets     = $perfService($customerId, $campaignResource);
        $avgCtr     = $assets ? array_sum(array_column($assets, 'ctr')) / count($assets) : 0;
        $threshold  = $avgCtr * self::ROTATION_CTR_RATIO;

        foreach ($assets as $asset) {
            if (
                $asset['impressions'] >= self::ROTATION_IMPRESSIONS_THRESHOLD &&
                $asset['ctr'] < $threshold &&
                in_array($asset['field_type'], [AssetFieldType::SITELINK, AssetFieldType::CALLOUT])
            ) {
                // Generate a replacement and link it; leave old asset (Google removes worst-performers automatically)
                if ($asset['field_type'] === AssetFieldType::CALLOUT) {
                    $replacements = $this->generateCallouts($customer, $campaign, $businessContext, 1);
                    if ($replacements) {
                        $service = new CreateCalloutAsset($customer);
                        $newAsset = ($service)($customerId, $replacements[0]);
                        if ($newAsset) {
                            ($linkService)($customerId, $campaignResource, $newAsset, AssetFieldType::CALLOUT);
                            $rotated[] = ['type' => 'callout', 'replaced' => $asset['asset_name']];
                        }
                    }
                }
                // Sitelink rotation intentionally skipped — requires URL context we don't auto-generate
            }
        }

        if (!empty($created) || !empty($rotated)) {
            $total = count($created) + count($rotated);
            AgentActivity::record(
                'extensions',
                'extensions_managed',
                "Added/rotated {$total} ad extension(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['created' => $created, 'rotated' => $rotated, 'errors' => $errors]
            );
        }

        return [
            'created' => $created,
            'rotated' => $rotated,
            'errors'  => $errors,
        ];
    }

    private function buildBusinessContext(Campaign $campaign): string
    {
        $customer = $campaign->customer;
        $pageContent = $customer->pages()
            ->limit(5)
            ->get(['title', 'content'])
            ->map(fn($p) => trim("{$p->title}\n{$p->content}"))
            ->filter()
            ->implode("\n\n");

        return implode("\n", array_filter([
            'Business: ' . $customer->name,
            $customer->description ? 'Description: ' . $customer->description : null,
            'Campaign: ' . $campaign->name,
            'Website: ' . $customer->website,
            $pageContent ? "Website content:\n" . $pageContent : null,
        ]));
    }

    private function generateSitelinks(object $customer, Campaign $campaign, string $context, int $count): array
    {
        $landingPage = $customer->website ?? null;
        if (!$landingPage) {
            Log::warning('[AdExtensionAgent] No website set for customer, skipping sitelink generation', [
                'customer_id' => $customer->id,
            ]);
            return [];
        }

        $prompt = <<<PROMPT
You are an expert Google Ads copywriter. Generate {$count} sitelink extension(s) for the following business.

{$context}

Requirements:
- link_text: max 25 characters, action-oriented
- description1: max 35 characters
- description2: max 35 characters
- url: use the business website or a relevant page if inferable

Return ONLY valid JSON array:
[{"link_text":"...","description1":"...","description2":"...","url":"..."}]
PROMPT;

        try {
            $response = $this->gemini->generateContent(config('ai.models.default'), $prompt);
            $text = $response['text'] ?? '';
            $text = preg_replace('/```json\s*|\s*```/', '', $text);
            $data = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                foreach ($data as &$item) {
                    $item['url'] = $item['url'] ?? $landingPage;
                }
                return array_slice($data, 0, $count);
            }
        } catch (\Exception $e) {
            Log::error('AdExtensionAgent: Sitelink generation failed: ' . $e->getMessage());
        }

        return [];
    }

    private function generateCallouts(object $customer, Campaign $campaign, string $context, int $count): array
    {
        $prompt = <<<PROMPT
You are an expert Google Ads copywriter. Generate {$count} callout extension text(s) for the following business.

{$context}

Requirements:
- Each callout max 25 characters
- Highlight unique selling points, features, or benefits
- No punctuation at the end

Return ONLY a valid JSON array of strings: ["...", "..."]
PROMPT;

        try {
            $response = $this->gemini->generateContent(config('ai.models.default'), $prompt);
            $text = $response['text'] ?? '';
            $text = preg_replace('/```json\s*|\s*```/', '', $text);
            $data = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return array_values(array_filter(array_slice($data, 0, $count), 'is_string'));
            }
        } catch (\Exception $e) {
            Log::error('AdExtensionAgent: Callout generation failed: ' . $e->getMessage());
        }

        return [];
    }

    private function generateStructuredSnippet(object $customer, Campaign $campaign, string $context): ?array
    {
        $prompt = <<<PROMPT
You are an expert Google Ads copywriter. Generate one structured snippet extension for the following business.

{$context}

Choose the most appropriate header from: Services, Types, Brands, Styles, Courses, Degree programs, Destinations, Featured hotels, Insurance coverage, Models, Neighborhoods, Service catalog, Shows, Amenities

Return ONLY valid JSON: {"header":"...","values":["value1","value2","value3","value4"]}
- 3 to 10 values, each max 25 characters
PROMPT;

        try {
            $response = $this->gemini->generateContent(config('ai.models.default'), $prompt);
            $text = $response['text'] ?? '';
            $text = preg_replace('/```json\s*|\s*```/', '', $text);
            $data = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['header'], $data['values'])) {
                return $data;
            }
        } catch (\Exception $e) {
            Log::error('AdExtensionAgent: Structured snippet generation failed: ' . $e->getMessage());
        }

        return null;
    }
}
