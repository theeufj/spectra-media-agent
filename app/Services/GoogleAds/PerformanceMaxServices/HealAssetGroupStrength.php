<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;

/**
 * Detects PMax asset groups with POOR/AVERAGE ad strength and heals them by
 * generating the missing headlines, long headlines and descriptions (via Gemini)
 * and pushing them into the live asset group. Text-only — logos, square images
 * and video are reported as gaps but not auto-generated here.
 *
 * AssetGroup.ad_strength enum: 1=UNKNOWN 2=PENDING 3=NO_ADS 4=POOR 5=AVERAGE 6=GOOD 7=EXCELLENT
 */
class HealAssetGroupStrength extends BaseGoogleAdsService
{
    /** Heal groups at POOR (4) or AVERAGE (5). GOOD/EXCELLENT are left alone. */
    private const HEAL_STRENGTHS = [4, 5];

    /** Google's targets for a strong text asset set, plus per-asset character limits. */
    private const SPEC = [
        'HEADLINE'      => ['field' => AssetFieldType::HEADLINE,      'target' => 15, 'max' => 30],
        'LONG_HEADLINE' => ['field' => AssetFieldType::LONG_HEADLINE, 'target' => 5,  'max' => 90],
        'DESCRIPTION'   => ['field' => AssetFieldType::DESCRIPTION,   'target' => 5,  'max' => 90],
    ];

    private GeminiService $gemini;

    public function __construct(Customer $customer, ?GeminiService $gemini = null)
    {
        parent::__construct($customer);
        $this->gemini = $gemini ?? app(GeminiService::class);
    }

    /**
     * Heal weak asset groups. Scope to one campaign if given, else all enabled
     * PMax campaigns on the account.
     *
     * @return array<int, array{asset_group:string, ad_strength:string, added:array, missing_media:array}>
     */
    public function heal(?Campaign $campaign = null): array
    {
        $actions = [];
        $customerId = $this->customer->google_ads_customer_id;
        if (!$customerId) {
            return $actions;
        }

        try {
            $this->ensureClient();

            $filter = "campaign.status = 'ENABLED' AND asset_group.status = 'ENABLED'";
            if ($campaign && $campaign->google_ads_campaign_id) {
                $filter .= ' AND campaign.id = ' . (int) $campaign->google_ads_campaign_id;
            }

            $groups = [];
            $q = "SELECT asset_group.resource_name, asset_group.id, asset_group.name, asset_group.ad_strength, campaign.name FROM asset_group WHERE {$filter}";
            foreach ($this->searchQuery($customerId, $q)->iterateAllElements() as $row) {
                $ag = $row->getAssetGroup();
                $groups[] = [
                    'res'      => $ag->getResourceName(),
                    'id'       => $ag->getId(),
                    'name'     => $ag->getName(),
                    'strength' => $ag->getAdStrength(),
                ];
            }

            foreach ($groups as $g) {
                if (!in_array($g['strength'], self::HEAL_STRENGTHS, true)) {
                    continue;
                }
                $result = $this->healGroup($customerId, $g);
                if ($result) {
                    $actions[] = $result;
                }
            }
        } catch (\Throwable $e) {
            $this->logError('HealAssetGroupStrength: heal failed: ' . $e->getMessage());
        }

        return $actions;
    }

    private function healGroup(string $customerId, array $group): ?array
    {
        $existing = $this->existingAssets($customerId, $group['id']);

        // Work out how many of each text type we still need.
        $needed = [];
        foreach (self::SPEC as $type => $spec) {
            $have = count($existing['text'][$type] ?? []);
            $need = max(0, $spec['target'] - $have);
            if ($need > 0) {
                $needed[$type] = $need;
            }
        }

        // Media gaps we can't auto-generate here — surfaced for a human/other job.
        $missingMedia = [];
        if (($existing['media']['LOGO'] ?? 0) === 0)         $missingMedia[] = 'logo (1:1)';
        if (($existing['media']['SQUARE_IMAGE'] ?? 0) === 0) $missingMedia[] = 'square image (1:1)';
        if (($existing['media']['YOUTUBE_VIDEO'] ?? 0) === 0) $missingMedia[] = 'video';

        if (empty($needed)) {
            // Text is already full; strength is limited by media only.
            return $missingMedia ? [
                'asset_group'   => $group['name'],
                'ad_strength'   => $this->strengthLabel($group['strength']),
                'added'         => [],
                'missing_media' => $missingMedia,
            ] : null;
        }

        $generated = $this->generateAssets($needed, $existing['text']);

        $added = [];
        $creator = new CreateTextAsset($this->customer);
        $linker  = new LinkAssetGroupAsset($this->customer);

        foreach (self::SPEC as $type => $spec) {
            foreach (($generated[$type] ?? []) as $text) {
                $assetRes = $creator($customerId, $text);
                if (!$assetRes) {
                    continue;
                }
                $linkRes = $linker($customerId, $group['res'], $assetRes, $spec['field']);
                if ($linkRes) {
                    $added[$type] = ($added[$type] ?? 0) + 1;
                }
            }
        }

        if (empty($added) && empty($missingMedia)) {
            return null;
        }

        $this->logInfo('HealAssetGroupStrength: topped up asset group', [
            'asset_group' => $group['name'],
            'added'       => $added,
        ]);

        return [
            'asset_group'   => $group['name'],
            'ad_strength'   => $this->strengthLabel($group['strength']),
            'added'         => $added,
            'missing_media' => $missingMedia,
        ];
    }

    /**
     * @return array{text: array<string, string[]>, media: array<string, int>}
     */
    private function existingAssets(string $customerId, int $assetGroupId): array
    {
        $text = ['HEADLINE' => [], 'LONG_HEADLINE' => [], 'DESCRIPTION' => []];
        $media = ['LOGO' => 0, 'SQUARE_IMAGE' => 0, 'YOUTUBE_VIDEO' => 0];

        $fieldName = [
            AssetFieldType::HEADLINE => 'HEADLINE',
            AssetFieldType::LONG_HEADLINE => 'LONG_HEADLINE',
            AssetFieldType::DESCRIPTION => 'DESCRIPTION',
            AssetFieldType::LOGO => 'LOGO',
            AssetFieldType::SQUARE_MARKETING_IMAGE => 'SQUARE_IMAGE',
            AssetFieldType::YOUTUBE_VIDEO => 'YOUTUBE_VIDEO',
        ];

        $q = "SELECT asset_group_asset.field_type, asset.text_asset.text FROM asset_group_asset "
            . "WHERE asset_group.id = {$assetGroupId} AND asset_group_asset.status = 'ENABLED'";
        foreach ($this->searchQuery($customerId, $q)->iterateAllElements() as $row) {
            $ft = $row->getAssetGroupAsset()->getFieldType();
            $label = $fieldName[$ft] ?? null;
            if (!$label) {
                continue;
            }
            if (isset($text[$label])) {
                $t = $row->getAsset()?->getTextAsset()?->getText();
                if ($t !== null && $t !== '') {
                    $text[$label][] = $t;
                }
            } elseif (isset($media[$label])) {
                $media[$label]++;
            }
        }

        return ['text' => $text, 'media' => $media];
    }

    /**
     * Ask Gemini for the needed text assets, then enforce character limits and
     * dedupe against what already exists.
     *
     * @param  array<string, int>        $needed    type => count
     * @param  array<string, string[]>   $existing  current texts by type
     * @return array<string, string[]>
     */
    private function generateAssets(array $needed, array $existing): array
    {
        $brand = trim(($this->customer->name ?? '') . ' — ' . ($this->customer->website ?? ''));
        $existingSample = collect($existing)->flatten()->take(10)->implode(' | ');

        $ask = [];
        foreach ($needed as $type => $n) {
            $ask[] = "{$n} {$type}(s) (max " . self::SPEC[$type]['max'] . ' chars each)';
        }

        $prompt = "You are writing Google Ads Performance Max text assets for: {$brand}.\n"
            . 'Existing assets (do NOT repeat these, and match their tone): ' . ($existingSample ?: '(none)') . "\n\n"
            . 'Generate distinct, high-converting, benefit-led copy. Strictly respect character limits. '
            . 'Return ONLY a JSON object with keys headlines, long_headlines, descriptions (arrays of strings). '
            . 'Provide exactly: ' . implode('; ', $ask) . ". Omit keys you were not asked for.";

        $result = $this->gemini->generateContent(
            config('ai.models.default'),
            $prompt,
            [],
            null,
            false,
            false,
            null,
            null,
            'image/jpeg',
            ['customer_id' => $this->customer->id, 'operation' => 'heal_ad_strength', 'task_type' => 'creative']
        );

        $json = $this->extractJson($result['text'] ?? '');
        $map = ['HEADLINE' => 'headlines', 'LONG_HEADLINE' => 'long_headlines', 'DESCRIPTION' => 'descriptions'];

        $out = [];
        foreach ($needed as $type => $n) {
            $candidates = $json[$map[$type]] ?? [];
            $seen = array_map('mb_strtolower', $existing[$type] ?? []);
            $picked = [];
            foreach ($candidates as $c) {
                $c = trim((string) $c);
                if ($c === '' || mb_strlen($c) > self::SPEC[$type]['max']) {
                    continue;
                }
                if (in_array(mb_strtolower($c), $seen, true)) {
                    continue;
                }
                $seen[] = mb_strtolower($c);
                $picked[] = $c;
                if (count($picked) >= $n) {
                    break;
                }
            }
            $out[$type] = $picked;
        }

        return $out;
    }

    private function extractJson(string $text): array
    {
        if ($text === '') {
            return [];
        }
        // Strip code fences and grab the outermost JSON object.
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function strengthLabel(int $s): string
    {
        return [1 => 'UNKNOWN', 2 => 'PENDING', 3 => 'NO_ADS', 4 => 'POOR', 5 => 'AVERAGE', 6 => 'GOOD', 7 => 'EXCELLENT'][$s] ?? (string) $s;
    }
}
