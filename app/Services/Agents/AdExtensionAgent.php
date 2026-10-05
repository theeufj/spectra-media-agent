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
    private const MIN_SITELINKS = 4;

    private const MIN_CALLOUTS = 4;

    private const MIN_SNIPPETS = 1;

    private const ROTATION_IMPRESSIONS_THRESHOLD = 500;

    private const ROTATION_CTR_RATIO = 0.40;

    public function __construct(private GeminiService $gemini) {}

    public function manage(Campaign $campaign): array
    {
        $result = ['created' => [], 'rotated' => [], 'errors' => [], 'unresolved' => []];
        $guard = app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class);
        $strategy = $guard->strategyForCampaign($campaign);
        if (! $strategy || ($blocked = $guard->mutationBlocked($campaign, $strategy))) {
            return array_merge($result, ['skipped' => true, 'unresolved' => [$blocked ?? 'strategy_not_approved']]);
        }
        $customer = $campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        $reader = app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer]);

        try {
            $this->requireMutationAllowed($campaign, $strategy, $reader);
            $perf = app(GetExtensionPerformance::class, ['customer' => $customer]);
            $counts = [];
            foreach ([AssetFieldType::SITELINK, AssetFieldType::CALLOUT, AssetFieldType::STRUCTURED_SNIPPET, AssetFieldType::CALL] as $field) {
                $counts[$field] = $perf->countByFieldType($customerId, $resource, $field);
            }
            $context = $this->buildBusinessContext($campaign);
            if ($counts[AssetFieldType::SITELINK] < self::MIN_SITELINKS) {
                $links = $this->repairSitelinks($campaign, self::MIN_SITELINKS - $counts[AssetFieldType::SITELINK], $strategy);
                $result['created'] = $links['created'];
                $result['errors'] = $links['errors'];
                $result['unresolved'] = $links['unresolved'];
            }
            if ($counts[AssetFieldType::CALLOUT] < self::MIN_CALLOUTS) {
                $this->requireMutationAllowed($campaign, $strategy, $reader);
                $callouts = $this->generateCallouts($customer, $campaign, $context, self::MIN_CALLOUTS - $counts[AssetFieldType::CALLOUT]);
                foreach ($callouts as $text) {
                    $creator = app(CreateCalloutAsset::class, ['customer' => $customer]);
                    $this->attachAsset($campaign, $strategy, $reader, fn () => $creator($customerId, $text), AssetFieldType::CALLOUT);
                    $result['created'][] = ['type' => 'callout', 'text' => $text];
                }
            }
            if ($counts[AssetFieldType::STRUCTURED_SNIPPET] < self::MIN_SNIPPETS) {
                $this->requireMutationAllowed($campaign, $strategy, $reader);
                $snippet = $this->generateStructuredSnippet($customer, $campaign, $context);
                if ($snippet) {
                    $creator = app(CreateStructuredSnippetAsset::class, ['customer' => $customer]);
                    $this->attachAsset($campaign, $strategy, $reader, fn () => $creator($customerId, $snippet['header'], $snippet['values']), AssetFieldType::STRUCTURED_SNIPPET);
                    $result['created'][] = ['type' => 'structured_snippet', 'header' => $snippet['header']];
                }
            }
            if ($counts[AssetFieldType::CALL] < 1 && $customer->phone) {
                $creator = app(CreateCallAsset::class, ['customer' => $customer]);
                $this->attachAsset($campaign, $strategy, $reader, fn () => $creator($customerId, $customer->phone, $customer->country ?? 'AU'), AssetFieldType::CALL);
                $result['created'][] = ['type' => 'call', 'phone' => $customer->phone];
            }

            $assets = $perf($customerId, $resource);
            $threshold = ($assets ? array_sum(array_column($assets, 'ctr')) / count($assets) : 0) * self::ROTATION_CTR_RATIO;
            foreach ($assets as $asset) {
                if ($asset['impressions'] >= self::ROTATION_IMPRESSIONS_THRESHOLD && $asset['ctr'] < $threshold && $asset['field_type'] === AssetFieldType::CALLOUT) {
                    $this->requireMutationAllowed($campaign, $strategy, $reader);
                    $replacements = $this->generateCallouts($customer, $campaign, $context, 1);
                    if ($replacements) {
                        $creator = app(CreateCalloutAsset::class, ['customer' => $customer]);
                        $this->attachAsset($campaign, $strategy, $reader, fn () => $creator($customerId, $replacements[0]), AssetFieldType::CALLOUT);
                        $result['rotated'][] = ['type' => 'callout', 'replaced' => $asset['asset_name']];
                    }
                }
            }
        } catch (\DomainException $e) {
            $result['unresolved'][] = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $result['errors'][] = new AgentIssue('extension_maintenance_failed', $e->getMessage());
        }

        if ($result['created'] || $result['rotated'] || $result['errors']) {
            $total = count($result['created']) + count($result['rotated']);
            AgentActivity::record('extensions', 'extensions_managed', "Added/rotated {$total} confirmed ad extension(s) for \"{$campaign->name}\"",
                $campaign->customer_id, $campaign->id, $result, $result['errors'] ? 'needs_review' : 'completed');
        }

        return $result;
    }

    private function requireMutationAllowed(Campaign $campaign, \App\Models\Strategy $strategy, \App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration $reader): void
    {
        if ($blocked = app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class)->campaignMutationBlocked($campaign, $strategy, $reader)) {
            throw new \DomainException($blocked);
        }
    }

    /** Never count an asset as attached without an acknowledged link. */
    private function attachAsset(Campaign $campaign, \App\Models\Strategy $strategy, \App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration $reader, callable $create, int $field): void
    {
        $this->requireMutationAllowed($campaign, $strategy, $reader);
        $asset = $create();
        $this->requireMutationAllowed($campaign, $strategy, $reader);
        $linker = app(LinkCampaignAsset::class, ['customer' => $campaign->customer]);
        if (! $asset || ! $linker($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName(), $asset, $field)) {
            throw new \RuntimeException('Google did not confirm the extension link.');
        }
    }

    /** Address Google's explicit sitelink action item using distinct verified pages. */
    public function repairSitelinks(Campaign $campaign, int $additional, ?\App\Models\Strategy $strategy = null): array
    {
        $customer = $campaign->customer;
        $result = ['created' => [], 'errors' => [], 'unresolved' => []];
        if (! $customer || $additional < 1) {
            return $result;
        }
        $strategy ??= app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class)->strategyForCampaign($campaign);
        $guard = app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class);
        if (! $strategy || ($blocked = $guard->mutationBlocked($campaign, $strategy))) {
            $result['unresolved'][] = $blocked ?? 'strategy_not_approved';

            return $result;
        }
        $additional = min($additional, 6);
        $customerId = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        $reader = app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer]);
        if ($blocked = $guard->campaignMutationBlocked($campaign, $strategy, $reader)) {
            $result['unresolved'][] = $blocked;

            return $result;
        }
        $evidence = app(\App\Services\Campaigns\AdvertisingEvidence::class);
        $existing = $reader->sitelinks($customerId, $resource);
        $existingUrls = [];
        foreach ($existing as $row) {
            foreach ($row['asset']['finalUrls'] ?? [] as $url) {
                $existingUrls[] = $evidence->url($url);
            }
        }
        $links = array_values(array_filter($evidence->sitelinks($customer, [], 20), fn ($link) => ! in_array($evidence->url($link['url']), $existingUrls, true)));
        foreach (array_slice($links, 0, $additional) as $link) {
            try {
                if ($blocked = $guard->campaignMutationBlocked($campaign, $strategy, $reader)) {
                    $result['unresolved'][] = $blocked;
                    break;
                }
                $creator = app(CreateSitelinkAsset::class, ['customer' => $customer]);
                $asset = ($creator)($customerId, $link['text'], $link['desc1'], $link['desc2'], $link['url']);
                if ($blocked = $guard->campaignMutationBlocked($campaign, $strategy, $reader)) {
                    $result['unresolved'][] = $blocked;
                    break;
                }
                $linker = app(LinkCampaignAsset::class, ['customer' => $customer]);
                if (! $asset || ! ($linker)($customerId, $resource, $asset, AssetFieldType::SITELINK)) {
                    throw new \RuntimeException('Google did not confirm the verified sitelink link.');
                }
                $result['created'][] = ['type' => 'sitelink', 'text' => $link['text'], 'url' => $link['url'], 'asset_resource' => $asset];
            } catch (\Throwable $e) {
                report($e);
                $result['errors'][] = new AgentIssue('sitelink_repair_failed', $e->getMessage());
            }
        }
        $guard->syncSitelinks($strategy, $result['created']);
        if (count($result['created']) < $additional) {
            $result['unresolved'][] = 'Google requests additional sitelinks; there are not enough distinct verified destinations or successful links. Review source pages and asset coverage.';
        }

        return $result;
    }

    private function buildBusinessContext(Campaign $campaign): string
    {
        $customer = $campaign->customer;
        $pageContent = $customer->pages()
            ->limit(5)
            ->get(['title', 'content'])
            ->map(fn ($p) => trim("{$p->title}\n{$p->content}"))
            ->filter()
            ->implode("\n\n");

        return implode("\n", array_filter([
            'Business: '.$customer->name,
            $customer->description ? 'Description: '.$customer->description : null,
            'Campaign: '.$campaign->name,
            'Website: '.$customer->website,
            $pageContent ? "Website content:\n".$pageContent : null,
        ]));
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
        } catch (\Throwable $e) {
            report($e);
            Log::error('AdExtensionAgent: Callout generation failed: '.$e->getMessage());
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
        } catch (\Throwable $e) {
            report($e);
            Log::error('AdExtensionAgent: Structured snippet generation failed: '.$e->getMessage());
        }

        return null;
    }
}
