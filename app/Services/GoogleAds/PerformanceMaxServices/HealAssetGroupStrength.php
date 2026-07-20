<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\StorageHelper;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V22\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetGroupAssetsRequest;

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
    /**
     * Heal anything that isn't already GOOD (6) / EXCELLENT (7): POOR (4), AVERAGE (5),
     * plus PENDING (2) / NO_ADS (3) — a freshly-built or just-modified group reports
     * PENDING while Google recalculates, and a thin group should still be topped up.
     * healGroup() only acts when there's an actual asset deficit, so full groups are
     * a no-op regardless of strength.
     */
    private const HEAL_STRENGTHS = [2, 3, 4, 5];

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
            if ($campaign && ($campId = $campaign->googleCampaignNumericId())) {
                $filter .= ' AND campaign.id = ' . $campId;
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

        // Auto-generate the marketing images PMax needs for strength (landscape + square).
        // Idempotent: only fills when genuinely absent, so it won't regenerate each pass.
        // The logo is intentionally left for a human — it should be the real brand mark,
        // not an AI guess.
        $imagesAdded = $this->healImages($customerId, $group, $existing);

        $missingMedia = [];
        if (($existing['media']['LOGO'] ?? 0) === 0)          $missingMedia[] = 'logo (1:1)';
        if (($existing['media']['YOUTUBE_VIDEO'] ?? 0) === 0) $missingMedia[] = 'video';

        if (empty($needed)) {
            // Text is already full; strength is limited by media only.
            return ($missingMedia || $imagesAdded > 0) ? [
                'asset_group'   => $group['name'],
                'ad_strength'   => $this->strengthLabel($group['strength']),
                'added'         => $imagesAdded > 0 ? ['IMAGE' => $imagesAdded] : [],
                'missing_media' => $missingMedia,
            ] : null;
        }

        $generated = $this->generateAssets($needed, $existing['text']);

        // Create the assets (these don't touch the asset group), then link them ALL in
        // one atomic mutate. Linking one-at-a-time fires many modifications at the same
        // asset group and trips CONCURRENT_MODIFICATION against Google's ad-strength recalc.
        $added = [];
        $linkOps = [];
        $creator = new CreateTextAsset($this->customer);

        foreach (self::SPEC as $type => $spec) {
            foreach (($generated[$type] ?? []) as $text) {
                $assetRes = $creator($customerId, $text);
                if (!$assetRes) {
                    continue;
                }
                $op = new AssetGroupAssetOperation();
                $op->setCreate(new AssetGroupAsset([
                    'asset_group' => $group['res'],
                    'asset'       => $assetRes,
                    'field_type'  => $spec['field'],
                ]));
                $linkOps[] = $op;
                $added[$type] = ($added[$type] ?? 0) + 1;
            }
        }

        if (!empty($linkOps) && !$this->batchLink($customerId, $linkOps)) {
            $added = []; // link failed; assets created but not attached
        }

        if ($imagesAdded > 0) {
            $added['IMAGE'] = $imagesAdded;
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
     * Generate + link the marketing images an asset group is missing (landscape 1.91:1
     * and square 1:1). Images are AI-generated, then cropped to the exact ratio Google
     * requires before upload. Only fills genuine gaps (idempotent by count). Returns the
     * number of images added.
     */
    private function healImages(string $customerId, array $group, array $existing): int
    {
        $specs = [];
        if (($existing['media']['MARKETING_IMAGE'] ?? 0) === 0) {
            $specs[] = ['field' => AssetFieldType::MARKETING_IMAGE, 'w' => 1200, 'h' => 628, 'ratio' => '1.91:1'];
        }
        if (($existing['media']['SQUARE_IMAGE'] ?? 0) === 0) {
            $specs[] = ['field' => AssetFieldType::SQUARE_MARKETING_IMAGE, 'w' => 1200, 'h' => 1200, 'ratio' => '1:1'];
        }
        if (empty($specs)) {
            return 0;
        }

        $brand   = $this->customer->name ?? 'the brand';
        $creator = new CreateImageAsset($this->customer);
        $linker  = new LinkAssetGroupAsset($this->customer);
        $added   = 0;

        foreach ($specs as $spec) {
            try {
                $prompt = "Clean, professional marketing image for \"{$brand}\". Bold, modern, high-contrast, "
                    . "no text, no logos, no watermarks, no people's faces. Suitable as a Google Ads asset. "
                    . "Aspect ratio {$spec['ratio']}.";

                $result = $this->gemini->generateImage($prompt, config('ai.models.image', 'gemini-3.1-flash-image-preview'));
                if (!$result || empty($result['data'])) {
                    continue;
                }

                // Crop/resize to the exact dimensions Google validates against.
                $manager = new ImageManager(new Driver());
                $image   = $manager->read(base64_decode($result['data']))->cover($spec['w'], $spec['h']);
                $binary  = (string) $image->toJpeg(85);

                $path = 'ad-assets/' . $group['id'] . '/' . uniqid('img_', true) . '.jpg';
                [$s3Path, $publicUrl] = StorageHelper::put($path, $binary, 'image/jpeg');
                if (!$publicUrl) {
                    continue;
                }

                $assetRes = $creator($customerId, $publicUrl, 'Auto image ' . now()->format('Y-m-d'));
                if ($assetRes && $linker($customerId, $group['res'], $assetRes, $spec['field'])) {
                    $added++;
                }
            } catch (\Throwable $e) {
                $this->logError('HealAssetGroupStrength: image heal failed: ' . $e->getMessage());
            }
        }

        return $added;
    }

    /** Link all assets in one atomic mutate, retrying transient concurrent-modification. */
    private function batchLink(string $customerId, array $ops): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->client->getAssetGroupAssetServiceClient()->mutateAssetGroupAssets(
                    new MutateAssetGroupAssetsRequest(['customer_id' => $customerId, 'operations' => $ops])
                );
                return true;
            } catch (\Throwable $e) {
                if ($attempt < 3 && str_contains($e->getMessage(), 'CONCURRENT_MODIFICATION')) {
                    usleep(1_000_000 * $attempt); // 1s, then 2s backoff
                    continue;
                }
                $this->logError('HealAssetGroupStrength: batch link failed: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    /**
     * @return array{text: array<string, string[]>, media: array<string, int>}
     */
    private function existingAssets(string $customerId, int $assetGroupId): array
    {
        $text = ['HEADLINE' => [], 'LONG_HEADLINE' => [], 'DESCRIPTION' => []];
        $media = ['LOGO' => 0, 'SQUARE_IMAGE' => 0, 'MARKETING_IMAGE' => 0, 'YOUTUBE_VIDEO' => 0];

        $fieldName = [
            AssetFieldType::HEADLINE => 'HEADLINE',
            AssetFieldType::LONG_HEADLINE => 'LONG_HEADLINE',
            AssetFieldType::DESCRIPTION => 'DESCRIPTION',
            AssetFieldType::LOGO => 'LOGO',
            AssetFieldType::SQUARE_MARKETING_IMAGE => 'SQUARE_IMAGE',
            AssetFieldType::MARKETING_IMAGE => 'MARKETING_IMAGE',
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
