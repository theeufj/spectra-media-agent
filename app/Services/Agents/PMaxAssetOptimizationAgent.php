<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\PerformanceMaxServices\GetAssetGroupPerformance;
use App\Services\GoogleAds\PerformanceMaxServices\CreateTextAsset;
use Illuminate\Support\Facades\Log;

/**
 * PMaxAssetOptimizationAgent
 *
 * Detects low-performing PMax assets and takes automated action:
 *  - Text assets (HEADLINE/DESCRIPTION): generates AI replacements via Gemini
 *  - Image assets: flags for human review (cannot auto-generate in this flow)
 */
class PMaxAssetOptimizationAgent
{
    protected Customer $customer;
    protected GeminiService $gemini;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini   = app(GeminiService::class);
    }

    /**
     * Analyse a PMax campaign's asset groups and replace / flag low performers.
     *
     * @param  Campaign $campaign
     * @return array{low_detected: int, text_replaced: int, image_flagged: int, errors: array}
     */
    public function run(Campaign $campaign): array
    {
        $result = [
            'low_detected'  => 0,
            'text_replaced' => 0,
            'image_flagged' => 0,
            'errors'        => [],
        ];

        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (!$campaignResourceName) {
            Log::warning('PMaxAssetOptimizationAgent: No google_ads_campaign_id on campaign', [
                'campaign_id' => $campaign->id,
            ]);
            $result['errors'][] = 'No google_ads_campaign_id set on campaign';
            return $result;
        }

        $customerId = $this->customer->google_ads_customer_id;
        if (!$customerId) {
            $result['errors'][] = 'No google_ads_customer_id set on customer';
            return $result;
        }

        // Fetch low-performing assets
        $getPerformance = new GetAssetGroupPerformance($this->customer);
        $lowAssets      = $getPerformance->getLowPerformingAssets($customerId, $campaignResourceName);

        $result['low_detected'] = count($lowAssets);

        // Record that we detected low assets
        AgentActivity::record(
            'pmax_asset_optimization',
            'low_assets_detected',
            "Detected {$result['low_detected']} low-performing asset(s) in PMax campaign \"{$campaign->name}\"",
            $this->customer->id,
            $campaign->id,
            ['low_count' => $result['low_detected'], 'campaign_resource' => $campaignResourceName]
        );

        if (empty($lowAssets)) {
            return $result;
        }

        $businessName = $this->customer->name ?? 'the business';
        $industry     = $this->customer->industry ?? 'general';
        $createText   = new CreateTextAsset($this->customer);

        foreach ($lowAssets as $asset) {
            $fieldType = $asset['field_type'];

            // Text assets: auto-replace with Gemini-generated copy
            if (in_array($fieldType, ['HEADLINE', 'DESCRIPTION'], true)) {
                try {
                    $maxChars    = $fieldType === 'HEADLINE' ? 30 : 90;
                    $charHint    = $fieldType === 'HEADLINE'
                        ? 'Keep each under 30 chars for headlines'
                        : 'Keep each under 90 chars for descriptions';

                    $prompt = "The following Google Ads PMax asset has been rated LOW performance. "
                        . "Generate 3 alternative {$fieldType} variants for a {$industry} business named {$businessName}. "
                        . "{$charHint}. Return JSON array of strings.";

                    $response = $this->gemini->generateContent(
                        model: config('ai.models.default'),
                        prompt: $prompt,
                        config: ['temperature' => 0.85, 'maxOutputTokens' => 512],
                    );

                    if ($response && isset($response['text'])) {
                        $text = $response['text'];
                        if (preg_match('/\[.*\]/s', $text, $matches)) {
                            $variants = json_decode($matches[0], true);
                            if (is_array($variants)) {
                                foreach ($variants as $variant) {
                                    $variant = trim($variant);
                                    if (empty($variant)) {
                                        continue;
                                    }
                                    // Enforce character limits
                                    $variant = mb_substr($variant, 0, $maxChars);
                                    $resourceName = ($createText)($customerId, $variant);
                                    if ($resourceName) {
                                        $result['text_replaced']++;
                                        Log::info('PMaxAssetOptimizationAgent: Created replacement text asset', [
                                            'field_type'   => $fieldType,
                                            'text'         => $variant,
                                            'resource'     => $resourceName,
                                            'campaign_id'  => $campaign->id,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $errorMsg = "Failed to replace {$fieldType} asset: " . $e->getMessage();
                    $result['errors'][] = $errorMsg;
                    Log::warning('PMaxAssetOptimizationAgent: ' . $errorMsg, [
                        'campaign_id' => $campaign->id,
                        'asset'       => $asset,
                    ]);
                }

                continue;
            }

            // Image assets: log warning and record activity for human review
            if (in_array($fieldType, ['MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'LOGO', 'LANDSCAPE_LOGO'], true)) {
                $imageUrl = $asset['image_url'] ?? '(unknown)';

                Log::warning('PMaxAssetOptimizationAgent: Low-performing image asset needs human replacement', [
                    'field_type'  => $fieldType,
                    'image_url'   => $imageUrl,
                    'asset_group' => $asset['asset_group'] ?? null,
                    'campaign_id' => $campaign->id,
                ]);

                AgentActivity::record(
                    'pmax_asset_optimization',
                    'image_asset_flagged',
                    "Low-performing image asset ({$fieldType}) requires human replacement for campaign \"{$campaign->name}\"",
                    $this->customer->id,
                    $campaign->id,
                    [
                        'field_type'     => $fieldType,
                        'image_url'      => $imageUrl,
                        'asset_resource' => $asset['asset_resource'],
                        'asset_group'    => $asset['asset_group'],
                    ]
                );

                $result['image_flagged']++;
            }
        }

        return $result;
    }
}
