<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use App\Services\GoogleAds\PerformanceMaxServices\AddAudienceSignals;
use App\Services\GoogleAds\PerformanceMaxServices\CreateImageAsset;
use App\Services\GoogleAds\PerformanceMaxServices\CreateTextAsset;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CampaignRemediationAgent
 *
 * Acts on structured findings from CampaignDiagnosticsAgent.
 * Auto-fixes what it safely can; sends actionable alerts for the rest.
 *
 * Auto-fixable findings:
 *   conversion_starvation     → refreshes PMax copy + images with conversion-focused variants
 *   conversion_labels_missing → runs conversions:provision to restore labels
 *
 * Alert-only findings:
 *   pmax_no_audience_signals  → notifies user with specific setup instructions
 *   pmax_bad_landing_page     → notifies user with URL fix instructions
 *   display_only_traffic      → notifies user to add a Search campaign
 */
class CampaignRemediationAgent
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Process all findings for a campaign, auto-fixing and/or alerting as appropriate.
     */
    public function remediate(Campaign $campaign, array $findings): array
    {
        if (empty($findings)) {
            return ['campaign_id' => $campaign->id, 'actions_taken' => [], 'alerts_sent' => [], 'errors' => []];
        }

        $results = [
            'campaign_id'   => $campaign->id,
            'actions_taken' => [],
            'alerts_sent'   => [],
            'errors'        => [],
        ];

        foreach ($findings as $finding) {
            try {
                if ($finding['can_auto_fix'] ?? false) {
                    $this->autoFix($campaign, $finding, $results);
                } else {
                    $this->alertCustomer($campaign, $finding, $results);
                }
            } catch (\Exception $e) {
                $results['errors'][] = "[{$finding['type']}] " . $e->getMessage();
                Log::error('CampaignRemediationAgent: Remediation failed', [
                    'campaign_id' => $campaign->id,
                    'finding'     => $finding['type'],
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if (!empty($results['actions_taken'])) {
            AgentActivity::record(
                'campaign_remediation',
                'auto_fixed',
                count($results['actions_taken']) . ' issue(s) auto-remediated in "' . $campaign->name . '"',
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $results['actions_taken']]
            );
        }

        return $results;
    }

    // ─── Routing ─────────────────────────────────────────────────────────────

    private function autoFix(Campaign $campaign, array $finding, array &$results): void
    {
        match ($finding['auto_fix_action'] ?? '') {
            'refresh_creative'      => $this->refreshCreative($campaign, $finding, $results),
            'add_audience_signals'  => $this->addAudienceSignals($campaign, $finding, $results),
            'provision_conversions' => $this->provisionConversions($finding, $results),
            default                 => $this->alertCustomer($campaign, $finding, $results),
        };
    }

    // ─── Audience signals ─────────────────────────────────────────────────────

    private function addAudienceSignals(Campaign $campaign, array $finding, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return;
        }

        // One signal pass per campaign per 7 days — signals are immutable once set
        $cacheKey = "remediation_audience_signals:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $customerId  = $customer->cleanGoogleCustomerId();
        $assetGroups = $finding['details']['asset_groups'] ?? $this->fetchAssetGroups($customer, $customerId, $campaign);

        if (empty($assetGroups)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 1. Generate search themes from the customer KB via AI
        $themes = $this->generateSearchThemes($campaign, $customer);

        // 2. Look up relevant in-market audience IDs
        $audienceResources = $this->findRelevantAudiences($customer, $customerId);

        if (empty($themes) && empty($audienceResources)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        $addSignals = new AddAudienceSignals($customer);
        $totalAdded = 0;

        foreach ($assetGroups as $assetGroup) {
            $groupResource = $assetGroup['resource_name'];

            if (!empty($themes)) {
                $totalAdded += $addSignals->addSearchThemes($customerId, $groupResource, $themes);
            }

            if (!empty($audienceResources)) {
                $totalAdded += $addSignals->addAudienceInterests($customerId, $groupResource, $audienceResources);
            }
        }

        if ($totalAdded > 0) {
            Cache::put($cacheKey, true, now()->addDays(7));

            $results['actions_taken'][] = [
                'type'     => 'audience_signals_added',
                'platform' => 'google_ads',
                'message'  => "Added {$totalAdded} audience signal(s) across " . count($assetGroups) . ' asset group(s)',
                'themes'   => $themes,
                'audiences' => $audienceResources,
            ];

            foreach ($customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'audience_signals_added',
                    'Auto-Fixed: Audience Signals Added to "' . $campaign->name . '"',
                    "Your PMax campaign had no audience signals — Google was guessing who to show ads to. "
                    . "We automatically added " . count($themes) . " search-theme signal(s) and "
                    . count($audienceResources) . " in-market audience(s) based on your business profile. "
                    . "Themes: " . implode(', ', array_slice($themes, 0, 5)) . (count($themes) > 5 ? '...' : '') . ". "
                    . "Google will now use these to find relevant, high-intent audiences.",
                    ['campaign_id' => $campaign->id, 'themes' => $themes, 'audiences' => $audienceResources]
                ));
            }
        } else {
            $this->alertCustomer($campaign, $finding, $results);
        }
    }

    private function generateSearchThemes(Campaign $campaign, Customer $customer): array
    {
        $name     = $customer->name ?? 'the business';
        $industry = $customer->industry ?? 'SaaS';
        $website  = $customer->website ?? '';

        // Pull keywords from existing strategies if available
        $strategyKeywords = $campaign->strategies()
            ->whereIn('deployment_status', ['deployed', 'verified', 'signed_off'])
            ->limit(3)
            ->get()
            ->flatMap(fn($s) => $s->keywords ?? [])
            ->filter()
            ->take(20)
            ->values()
            ->toArray();

        $keywordContext = !empty($strategyKeywords)
            ? "\n\nExisting campaign keywords for reference: " . implode(', ', $strategyKeywords)
            : '';

        $prompt = <<<PROMPT
You are a Google Ads PMax specialist.

Generate 10 search-theme signals for a Performance Max campaign. Search themes tell Google's AI what search intent to target — they're like keywords but guide PMax specifically.

Business: "{$name}"
Industry: {$industry}
Website: {$website}{$keywordContext}

Generate themes that represent the specific searches a high-intent buyer would make. Mix:
- Problem-aware searches ("google ads not converting", "wasting google ads budget")
- Solution-aware searches ("google ads management software", "automated google ads")
- Brand/competitor-aware searches ("better than wordstream", "google ads agency alternative")
- Outcome searches ("increase google ads roas", "lower google ads cpc")

Return ONLY a JSON array of strings. Max 10 themes, max 10 words each, no special characters:
["theme 1", "theme 2", ...]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                model:   config('ai.models.default'),
                prompt:  $prompt,
                config:  ['temperature' => 0.7],
                context: ['operation' => 'search_themes', 'campaign_id' => $campaign->id],
            );

            if ($response && isset($response['text'])) {
                if (preg_match('/\[.*?\]/s', $response['text'], $m)) {
                    $themes = json_decode($m[0], true);
                    if (is_array($themes)) {
                        return array_values(array_filter(array_map(
                            fn($t) => mb_substr(trim((string) $t), 0, 80),
                            $themes
                        )));
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('CampaignRemediationAgent: Search theme generation failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    private function findRelevantAudiences(Customer $customer, string $customerId): array
    {
        // Search for in-market audiences relevant to B2B SaaS / marketing software
        $searchTerms = ['business software', 'marketing', 'advertising'];
        $found       = [];

        try {
            $searcher = new SearchAudience($customer);
            foreach ($searchTerms as $term) {
                $audiences = ($searcher)($customerId, $term);
                foreach ($audiences as $a) {
                    if (isset($a['id'])) {
                        $found[] = $a['id'];
                    }
                    if (count($found) >= 3) {
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('CampaignRemediationAgent: Audience search failed', ['error' => $e->getMessage()]);
        }

        return $found;
    }

    // ─── Creative refresh ─────────────────────────────────────────────────────

    private function refreshCreative(Campaign $campaign, array $finding, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return;
        }

        // Only refresh once per campaign per 72 hours to avoid spamming assets
        $cacheKey = "remediation_creative_refresh:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            Log::info('CampaignRemediationAgent: Creative refresh skipped (cooldown)', [
                'campaign_id' => $campaign->id,
            ]);
            return;
        }

        $customerId  = $customer->cleanGoogleCustomerId();
        $assetGroups = $finding['details']['asset_groups']
            ?? $this->fetchAssetGroups($customer, $customerId, $campaign);

        if (empty($assetGroups)) {
            $results['errors'][] = 'conversion_starvation: no asset groups found for creative refresh';
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        $copySummary  = [];
        $imageSummary = [];

        foreach ($assetGroups as $assetGroup) {
            $groupResource = $assetGroup['resource_name'];

            // 1. AI-generated copy variants focused on conversion
            $copy = $this->generateConversionCopy($campaign, $customer);
            if ($copy) {
                $this->addTextAssets($customer, $customerId, $groupResource, $copy, $copySummary);
            }

            // 2. AI-generated image assets with fresh visual angles
            $imageUrls = $this->generateConversionImages($campaign, $customer);
            foreach ($imageUrls as $url) {
                $this->addImageAsset($customer, $customerId, $groupResource, $url, $imageSummary);
            }
        }

        $summaryParts = array_filter([
            !empty($copySummary)  ? count($copySummary)  . ' new copy variant(s)'  : null,
            !empty($imageSummary) ? count($imageSummary) . ' new image(s)'          : null,
        ]);

        if (!empty($summaryParts)) {
            Cache::put($cacheKey, true, now()->addHours(72));

            $results['actions_taken'][] = [
                'type'    => 'creative_refresh',
                'platform' => 'google_ads',
                'message' => 'Creative refresh: ' . implode(', ', $summaryParts),
                'copy'    => $copySummary,
                'images'  => $imageSummary,
            ];

            $spend = number_format($finding['details']['spend'] ?? 0, 2);
            foreach ($customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'creative_refresh',
                    'Auto-Fixed: Creative Refresh on "' . $campaign->name . '"',
                    "Your campaign spent \${$spend} with no conversions. We automatically added "
                    . implode(' and ', $summaryParts) . ' to give the algorithm fresh signals to optimise from. '
                    . 'Allow 3–5 days for Google to test the new variants before judging performance.',
                    ['campaign_id' => $campaign->id, 'actions' => array_merge($copySummary, $imageSummary)]
                ));
            }
        } else {
            // Nothing could be auto-generated, fall back to alert
            $this->alertCustomer($campaign, $finding, $results);
        }
    }

    private function generateConversionCopy(Campaign $campaign, Customer $customer): ?array
    {
        $name     = $customer->name ?? 'the business';
        $industry = $customer->industry ?? 'SaaS';

        $prompt = <<<PROMPT
You are a Google Ads direct-response copywriter.

A PMax campaign for "{$name}" ({$industry}) has spent money with ZERO conversions.
The current ads are not compelling people to act. Generate fresh conversion-focused variants.

Focus on:
- Problem/solution framing (not features — show the outcome for the user)
- Specificity (numbers, time saved, cost saved — avoid vague claims)
- Direct calls to action

Return ONLY valid JSON in this exact format:
{
  "headlines": ["...", "...", "...", "..."],
  "descriptions": ["...", "..."]
}

Rules:
- Headlines: max 30 characters each, exactly 4 variants
- Descriptions: max 90 characters each, exactly 2 variants
- No exclamation marks, no ALL-CAPS, no special characters (™ ® etc)
- Each variant must be meaningfully different — no paraphrasing
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                model:   config('ai.models.default'),
                prompt:  $prompt,
                config:  ['temperature' => 0.9],
                context: ['operation' => 'creative_refresh', 'campaign_id' => $campaign->id],
            );

            if ($response && isset($response['text'])) {
                if (preg_match('/\{.*?\}/s', $response['text'], $m)) {
                    $data = json_decode($m[0], true);
                    if (is_array($data) && !empty($data['headlines'])) {
                        return $data;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('CampaignRemediationAgent: Copy generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function addTextAssets(
        Customer $customer,
        string $customerId,
        string $assetGroupResource,
        array $copy,
        array &$summary
    ): void {
        $createText = new CreateTextAsset($customer);
        $linkAsset  = new LinkAssetGroupAsset($customer);

        foreach ($copy['headlines'] ?? [] as $text) {
            $text = mb_substr(trim($text), 0, 30);
            if (!$text) continue;

            $assetResource = ($createText)($customerId, $text);
            if ($assetResource) {
                ($linkAsset)($customerId, $assetGroupResource, $assetResource, AssetFieldType::HEADLINE);
                $summary[] = ['type' => 'headline', 'text' => $text];
            }
        }

        foreach ($copy['descriptions'] ?? [] as $text) {
            $text = mb_substr(trim($text), 0, 90);
            if (!$text) continue;

            $assetResource = ($createText)($customerId, $text);
            if ($assetResource) {
                ($linkAsset)($customerId, $assetGroupResource, $assetResource, AssetFieldType::DESCRIPTION);
                $summary[] = ['type' => 'description', 'text' => $text];
            }
        }
    }

    private function generateConversionImages(Campaign $campaign, Customer $customer): array
    {
        $urls = [];
        $name = $customer->name ?? 'the brand';

        $prompts = [
            "Professional digital marketing SaaS dashboard screenshot for {$name}. Shows campaign metrics with green upward trend graphs. Clean white UI, modern sans-serif font, blue accent color. Landscape 1.91:1 ratio.",
            "Minimalist ad creative for {$name}. Bold headline text on clean white background with blue accent block. No stock photos, no people. Landscape 1.91:1 ratio.",
        ];

        foreach ($prompts as $prompt) {
            try {
                $result = $this->gemini->generateImage(
                    prompt: $prompt,
                    model:  config('ai.models.image', 'gemini-3.1-flash-image-preview'),
                );

                if ($result && isset($result['data'])) {
                    $decoded     = base64_decode($result['data']);
                    $mimeType    = $result['mimeType'] ?? 'image/png';
                    $extension   = str_contains($mimeType, 'jpeg') ? 'jpg' : 'png';
                    $storagePath = 'remediation/' . $campaign->id . '/' . uniqid('img_', true) . '.' . $extension;

                    [$s3Path, $publicUrl] = StorageHelper::put($storagePath, $decoded, $mimeType);

                    if ($publicUrl) {
                        $urls[] = $publicUrl;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('CampaignRemediationAgent: Image generation failed', [
                    'campaign_id' => $campaign->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $urls;
    }

    private function addImageAsset(
        Customer $customer,
        string $customerId,
        string $assetGroupResource,
        string $imageUrl,
        array &$summary
    ): void {
        try {
            $createImage   = new CreateImageAsset($customer);
            $linkAsset     = new LinkAssetGroupAsset($customer);
            $assetName     = 'Auto-Refresh ' . now()->format('Y-m-d H:i');
            $assetResource = ($createImage)($customerId, $imageUrl, $assetName);

            if ($assetResource) {
                ($linkAsset)($customerId, $assetGroupResource, $assetResource, AssetFieldType::MARKETING_IMAGE);
                $summary[] = ['type' => 'image', 'url' => $imageUrl, 'asset' => $assetResource];
            }
        } catch (\Exception $e) {
            Log::warning('CampaignRemediationAgent: Image asset upload failed', [
                'url'   => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fetchAssetGroups(Customer $customer, string $customerId, Campaign $campaign): array
    {
        preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $m);
        $campaignId = $m[1] ?? $campaign->google_ads_campaign_id;

        try {
            $service = new class($customer) extends BaseGoogleAdsService {
                public function get(string $customerId, string $campaignId): array
                {
                    $this->ensureClient();
                    $groups = [];
                    $resp   = $this->searchQuery($customerId,
                        "SELECT asset_group.resource_name, asset_group.name FROM asset_group WHERE campaign.id = {$campaignId}"
                    );
                    foreach ($resp->getIterator() as $row) {
                        $ag       = $row->getAssetGroup();
                        $groups[] = ['resource_name' => $ag->getResourceName(), 'name' => $ag->getName()];
                    }
                    return $groups;
                }
            };

            return $service->get($customerId, $campaignId);
        } catch (\Exception) {
            return [];
        }
    }

    // ─── Conversion provisioning ──────────────────────────────────────────────

    private function provisionConversions(array $finding, array &$results): void
    {
        try {
            Artisan::call('conversions:provision');
            $results['actions_taken'][] = [
                'type'    => 'provision_conversions',
                'message' => 'Ran conversions:provision to restore missing conversion labels',
                'missing' => $finding['details']['missing'] ?? [],
            ];
            Log::info('CampaignRemediationAgent: Conversion labels re-provisioned', [
                'missing' => $finding['details']['missing'] ?? [],
            ]);
        } catch (\Exception $e) {
            $results['errors'][] = 'conversions:provision failed: ' . $e->getMessage();
        }
    }

    // ─── Alerting ────────────────────────────────────────────────────────────

    private function alertCustomer(Campaign $campaign, array $finding, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer?->users) {
            return;
        }

        // Deduplicate: one alert per finding type per campaign per 24 hours
        $cacheKey = "remediation_alert:{$campaign->id}:{$finding['type']}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(24));

        $body = $finding['message'];
        if (!empty($finding['recommended_action'])) {
            $body .= "\n\nWhat to do: " . $finding['recommended_action'];
        }

        foreach ($customer->users as $user) {
            $user->notify(new CriticalAgentAlert(
                $finding['type'],
                'Campaign Issue: ' . $campaign->name,
                $body,
                [
                    'campaign_id' => $campaign->id,
                    'severity'    => $finding['severity'],
                    'details'     => $finding['details'] ?? [],
                ]
            ));
        }

        $results['alerts_sent'][] = [
            'type'    => $finding['type'],
            'message' => $finding['message'],
        ];
    }
}
