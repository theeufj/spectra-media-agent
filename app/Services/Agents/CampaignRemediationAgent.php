<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CreativeService as FacebookCreativeService;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use Google\Ads\GoogleAds\V22\Resources\AssetGroup;
use Google\Ads\GoogleAds\V22\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetGroupsRequest;
use Google\Protobuf\FieldMask;
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
            'fix_landing_page'      => $this->fixLandingPage($campaign, $finding, $results),
            'provision_conversions' => $this->provisionConversions($finding, $results),
            'refresh_meta_creative' => $this->refreshMetaCreative($campaign, $finding, $results),
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

        $customerId  = $customer->cleanGoogleCustomerId();
        $assetGroups = $finding['details']['asset_groups'] ?? $this->fetchAssetGroups($customer, $customerId, $campaign);

        if (empty($assetGroups)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // Only heal asset groups that genuinely have zero signals. Checking real state
        // (instead of a 7-day cache) makes this idempotent and self-correcting: a prior
        // partial/failed attempt retries next pass rather than being blocked for a week,
        // and we never pile signals onto groups that already have them.
        $withSignals  = $this->assetGroupsWithSignals($customer, $customerId, $campaign);
        $targetGroups = array_values(array_filter(
            $assetGroups,
            fn ($g) => !in_array($g['resource_name'], $withSignals, true)
        ));

        if (empty($targetGroups)) {
            return; // every asset group already has signals
        }

        // 1. Search themes — AI first, deterministic fallback so an empty AI response
        //    never dead-ends the fix into a customer alert.
        $themes = $this->generateSearchThemes($campaign, $customer);
        if (empty($themes)) {
            $themes = $this->fallbackSearchThemes($campaign, $customer);
        }

        // 2. Look up relevant in-market audience IDs
        $audienceResources = $this->findRelevantAudiences($customer, $customerId);

        if (empty($themes) && empty($audienceResources)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        $addSignals = new AddAudienceSignals($customer);
        $totalAdded = 0;

        foreach ($targetGroups as $assetGroup) {
            $groupResource = $assetGroup['resource_name'];

            if (!empty($themes)) {
                $totalAdded += $addSignals->addSearchThemes($customerId, $groupResource, $themes);
            }

            if (!empty($audienceResources)) {
                $totalAdded += $addSignals->addAudienceInterests($customerId, $groupResource, $audienceResources);
            }
        }

        if ($totalAdded > 0) {
            $results['actions_taken'][] = [
                'type'     => 'audience_signals_added',
                'platform' => 'google_ads',
                'message'  => "Added {$totalAdded} audience signal(s) across " . count($targetGroups) . ' asset group(s)',
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

    /**
     * Asset-group resource names in this campaign that already have ≥1 signal.
     */
    private function assetGroupsWithSignals(Customer $customer, string $customerId, Campaign $campaign): array
    {
        preg_match('/campaigns\/(\d+)$/', (string) $campaign->google_ads_campaign_id, $m);
        $campaignId = $m[1] ?? preg_replace('/\D/', '', (string) $campaign->google_ads_campaign_id);
        if (!$campaignId) {
            return [];
        }

        try {
            $service = new class($customer) extends BaseGoogleAdsService {
                public function get(string $customerId, string $campaignId): array
                {
                    $this->ensureClient();
                    $groups = [];
                    $resp = $this->searchQuery($customerId,
                        "SELECT asset_group_signal.asset_group FROM asset_group_signal WHERE campaign.id = {$campaignId}"
                    );
                    foreach ($resp->getIterator() as $row) {
                        $groups[] = $row->getAssetGroupSignal()->getAssetGroup();
                    }
                    return array_values(array_unique($groups));
                }
            };

            return $service->get($customerId, $campaignId);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Deterministic search themes derived from the customer/campaign — used when AI
     * generation returns nothing so a signal fix never dead-ends into an alert.
     */
    private function fallbackSearchThemes(Campaign $campaign, Customer $customer): array
    {
        $themes = $campaign->strategies()
            ->whereIn('deployment_status', ['deployed', 'verified', 'signed_off'])
            ->limit(3)->get()
            ->flatMap(fn ($s) => $s->keywords ?? [])
            ->all();

        foreach ([$customer->name, $customer->industry ?? null, $campaign->product_focus ?? null] as $v) {
            if (is_string($v) && trim($v) !== '') {
                $themes[] = trim($v);
            }
        }

        if (is_array($campaign->goals ?? null)) {
            $themes = array_merge($themes, array_filter($campaign->goals, 'is_string'));
        }

        $themes = array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_substr(trim((string) $t), 0, 80),
            $themes
        ))));

        return array_slice($themes, 0, 10);
    }

    // ─── Landing page fix ────────────────────────────────────────────────────

    private function fixLandingPage(Campaign $campaign, array $finding, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return;
        }

        $cacheKey = "remediation_landing_page:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $website     = rtrim($finding['details']['website'] ?? $customer->website ?? '', '/');
        $currentUrl  = $finding['details']['current_url'] ?? '';
        $assetGroups = $finding['details']['asset_groups'] ?? [];

        if (!$website || empty($assetGroups)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 1. Fetch and parse the sitemap
        $urls = $this->fetchSitemapUrls($website);
        if (empty($urls)) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 2. Use AI to identify the best conversion-focused URL
        $bestUrl = $this->selectBestLandingPage($campaign, $customer, $website, $urls, $currentUrl);
        if (!$bestUrl || $bestUrl === $currentUrl) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 3. Update every asset group's final URL
        $customerId = $customer->cleanGoogleCustomerId();
        $updated    = 0;

        try {
            $service = new class($customer) extends BaseGoogleAdsService {
                public function updateFinalUrl(string $customerId, string $assetGroupResource, string $url): bool
                {
                    $this->ensureClient();

                    $assetGroup = new AssetGroup([
                        'resource_name' => $assetGroupResource,
                        'final_urls'    => [$url],
                    ]);

                    $op = new AssetGroupOperation();
                    $op->setUpdate($assetGroup);
                    $op->setUpdateMask(new FieldMask(['paths' => ['final_urls']]));

                    $this->client->getAssetGroupServiceClient()->mutateAssetGroups(
                        new MutateAssetGroupsRequest([
                            'customer_id' => $customerId,
                            'operations'  => [$op],
                        ])
                    );

                    return true;
                }
            };

            foreach ($assetGroups as $ag) {
                try {
                    if ($service->updateFinalUrl($customerId, $ag['resource_name'], $bestUrl)) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    Log::warning('CampaignRemediationAgent: Failed to update final URL for asset group', [
                        'asset_group' => $ag['resource_name'],
                        'url'         => $bestUrl,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('CampaignRemediationAgent: Landing page fix failed', ['error' => $e->getMessage()]);
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        if ($updated > 0) {
            Cache::put($cacheKey, true, now()->addDays(30));

            $results['actions_taken'][] = [
                'type'        => 'landing_page_fixed',
                'platform'    => 'google_ads',
                'message'     => "Updated {$updated} asset group(s) from {$currentUrl} → {$bestUrl}",
                'old_url'     => $currentUrl,
                'new_url'     => $bestUrl,
                'urls_scanned' => count($urls),
            ];

            foreach ($customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'landing_page_fixed',
                    'Auto-Fixed: Landing Page Updated on "' . $campaign->name . '"',
                    "Your PMax campaign was sending paid traffic to {$currentUrl} — an informational page with no conversion path. "
                    . "We scanned your sitemap (" . count($urls) . " pages), identified the best conversion-focused URL, "
                    . "and updated {$updated} asset group(s) to point to: {$bestUrl}",
                    ['campaign_id' => $campaign->id, 'old_url' => $currentUrl, 'new_url' => $bestUrl]
                ));
            }
        } else {
            $this->alertCustomer($campaign, $finding, $results);
        }
    }

    private function fetchSitemapUrls(string $website): array
    {
        $urls = [];

        // Try standard sitemap locations
        $candidates = [
            $website . '/sitemap.xml',
            $website . '/sitemap_index.xml',
            $website . '/sitemap/',
        ];

        foreach ($candidates as $sitemapUrl) {
            try {
                $ctx  = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Spectra/1.0 SitemapBot']]);
                $xml  = @file_get_contents($sitemapUrl, false, $ctx);
                if (!$xml) {
                    continue;
                }

                libxml_use_internal_errors(true);
                $doc = simplexml_load_string($xml);
                if (!$doc) {
                    continue;
                }

                // Handle sitemap index — fetch child sitemaps
                if (isset($doc->sitemap)) {
                    foreach ($doc->sitemap as $child) {
                        $childUrl = (string) ($child->loc ?? '');
                        if ($childUrl) {
                            $childXml = @file_get_contents($childUrl, false, $ctx);
                            if ($childXml) {
                                $childDoc = simplexml_load_string($childXml);
                                if ($childDoc && isset($childDoc->url)) {
                                    foreach ($childDoc->url as $entry) {
                                        $loc = (string) ($entry->loc ?? '');
                                        if ($loc) {
                                            $urls[] = $loc;
                                        }
                                    }
                                }
                            }
                        }
                        if (count($urls) >= 200) {
                            break;
                        }
                    }
                }

                // Regular sitemap
                if (isset($doc->url)) {
                    foreach ($doc->url as $entry) {
                        $loc = (string) ($entry->loc ?? '');
                        if ($loc) {
                            $urls[] = $loc;
                        }
                    }
                }

                if (!empty($urls)) {
                    break;
                }
            } catch (\Exception $e) {
                Log::debug('CampaignRemediationAgent: Sitemap fetch failed', [
                    'url'   => $sitemapUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_unique($urls);
    }

    private function selectBestLandingPage(
        Campaign $campaign,
        Customer $customer,
        string $website,
        array $urls,
        string $currentUrl
    ): ?string {
        // Cap to 150 URLs to stay within prompt limits
        $urlList = implode("\n", array_slice($urls, 0, 150));

        $name     = $customer->name ?? 'the business';
        $industry = $customer->industry ?? 'SaaS';

        $prompt = <<<PROMPT
You are a conversion rate optimisation specialist analysing a website sitemap.

Business: "{$name}" ({$industry})
Current (wrong) landing page: {$currentUrl}
Problem: This is an informational page. Paid traffic landing here bounces without converting.

Here are all pages from the sitemap:
{$urlList}

Your task: identify the SINGLE best URL for paid ad traffic — the page most likely to convert a cold visitor into a lead or signup.

Prioritise in this order:
1. Homepage (usually the root URL — best for PMax if no dedicated landing page exists)
2. Dedicated trial/demo/signup landing page (/try, /demo, /get-started, /start, /signup, /free-trial)
3. Pricing page (/pricing, /plans) — only if no trial page exists
4. Product/features overview — last resort

Rules:
- Return ONLY the full URL string, nothing else
- Do NOT return the current wrong URL ({$currentUrl})
- Do NOT return /blog, /about, /team, /careers, /docs, /faq, /how-it-works, /learn pages
- If in doubt, return the homepage ({$website}/)
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                model:   config('ai.models.default'),
                prompt:  $prompt,
                config:  ['temperature' => 0.1], // low temperature — deterministic URL selection
                context: ['operation' => 'landing_page_selection', 'campaign_id' => $campaign->id],
            );

            if ($response && isset($response['text'])) {
                $url = trim(strip_tags($response['text']));
                // Validate it's actually from the sitemap or is the homepage
                if (filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, $website)) {
                    return $url;
                }
            }
        } catch (\Exception $e) {
            Log::warning('CampaignRemediationAgent: Landing page selection failed', ['error' => $e->getMessage()]);
        }

        // Safe fallback: homepage
        return $website . '/';
    }

    // ─── Meta creative refresh ────────────────────────────────────────────────

    private function refreshMetaCreative(Campaign $campaign, array $finding, array &$results): void
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return;
        }

        $cacheKey = "remediation_meta_creative:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $accountId = $customer->facebook_ads_account_id;
        if (!$accountId) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 1. Generate AI copy (Meta limits: primary text ≤125 chars, headline ≤40 chars)
        $copy = $this->generateMetaCopy($campaign, $customer);
        if (!$copy) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 2. Generate image
        $imagePrompt = "Professional advertising banner for {$customer->name}: {$copy['headline']}. "
            . "Clean, modern design suitable for Facebook/Instagram feed. No text overlay.";

        $imageResult = $this->gemini->generateImage($imagePrompt, context: ['campaign_id' => $campaign->id]);
        if (!$imageResult) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 3. Store image and get a public URL
        $filename  = "meta-creative/{$campaign->id}-" . now()->timestamp . '.jpg';
        $imageUrl  = StorageHelper::put($filename, base64_decode($imageResult['data']), $imageResult['mimeType'] ?? 'image/jpeg');
        if (!$imageUrl) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        // 4. Create new Meta creative
        $creativeService = new FacebookCreativeService($customer);
        $creative = $creativeService->createImageCreative(
            accountId:    $accountId,
            creativeName: 'Spectra Auto-Refresh — ' . $campaign->name . ' — ' . now()->toDateString(),
            imageUrl:     $imageUrl,
            headline:     $copy['headline'],
            description:  $copy['primary_text'],
            callToAction: 'LEARN_MORE',
            linkUrl:      $customer->website,
        );

        if (!$creative || empty($creative['id'])) {
            $this->alertCustomer($campaign, $finding, $results);
            return;
        }

        $creativeId = $creative['id'];

        // 5. Apply new creative to all active ads in this campaign
        $adSetService = new AdSetService($customer);
        $adService    = new FacebookAdService($customer);

        $adSets  = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];
        $updated = 0;

        foreach ($adSets as $adSet) {
            $ads = $adService->listAds($adSet['id']) ?? [];
            foreach ($ads as $ad) {
                if (in_array($ad['status'] ?? '', ['ACTIVE', 'PAUSED'])) {
                    if ($adService->updateAd($ad['id'], ['creative' => ['creative_id' => $creativeId]])) {
                        $updated++;
                    }
                }
            }
        }

        if ($updated > 0) {
            Cache::put($cacheKey, true, now()->addHours(72));

            $results['actions_taken'][] = [
                'type'        => 'meta_creative_refreshed',
                'platform'    => 'meta',
                'message'     => "Refreshed creative on {$updated} Meta ad(s) with new AI-generated copy and image",
                'creative_id' => $creativeId,
                'headline'    => $copy['headline'],
                'ads_updated' => $updated,
            ];

            foreach ($customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'meta_creative_refreshed',
                    'Auto-Fixed: Meta Ad Creative Refreshed on "' . $campaign->name . '"',
                    "We detected " . ($finding['type'] === 'meta_audience_fatigue'
                        ? "audience fatigue (frequency {$finding['details']['frequency']}x)"
                        : "conversion starvation ($" . number_format($finding['details']['spend'] ?? 0, 2) . " spent, 0 conversions)")
                    . ". A fresh creative has been generated and applied to {$updated} ad(s).\n\n"
                    . "New headline: \"{$copy['headline']}\"\nNew copy: \"{$copy['primary_text']}\"",
                    ['campaign_id' => $campaign->id, 'creative_id' => $creativeId]
                ));
            }
        } else {
            $this->alertCustomer($campaign, $finding, $results);
        }
    }

    private function generateMetaCopy(Campaign $campaign, Customer $customer): ?array
    {
        $name     = $customer->name ?? 'the business';
        $industry = $customer->industry ?? 'SaaS';
        $website  = $customer->website ?? '';

        $prompt = <<<PROMPT
You are a direct-response copywriter creating a Facebook/Instagram ad for a SaaS business.

Business: "{$name}" ({$industry})
Website: {$website}
Campaign: "{$campaign->name}"

Write ONE ad variation with:
- primary_text: 1-2 punchy sentences, max 125 characters. Focus on the problem solved or the outcome delivered. No fluff.
- headline: Max 40 characters. Benefit-driven, curiosity-triggering, or question-based.

Return ONLY valid JSON (no markdown, no explanation):
{"primary_text": "...", "headline": "..."}
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                model:   config('ai.models.default'),
                prompt:  $prompt,
                config:  ['temperature' => 0.7],
                context: ['operation' => 'meta_copy_generation', 'campaign_id' => $campaign->id],
            );

            if (!$response || !isset($response['text'])) {
                return null;
            }

            $text = trim(preg_replace('/^```json\s*|\s*```$/m', '', $response['text']));
            $data = json_decode($text, true);

            if (!$data || empty($data['headline']) || empty($data['primary_text'])) {
                return null;
            }

            return [
                'headline'     => mb_substr(trim($data['headline']), 0, 40),
                'primary_text' => mb_substr(trim($data['primary_text']), 0, 125),
            ];
        } catch (\Exception $e) {
            Log::warning('CampaignRemediationAgent: Meta copy generation failed', ['error' => $e->getMessage()]);
            return null;
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
