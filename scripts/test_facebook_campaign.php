<?php

/**
 * Test Facebook end-to-end: campaign → ad set → image upload → creative → ad
 *
 * Usage (from project root):
 *   php scripts/test_facebook_campaign.php [ad_account_id] [page_id] [landing_url]
 *
 * Falls back to first BM-owned customer if no args given.
 * All objects created are PAUSED and deleted at the end.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Services\FacebookAds\BusinessManagerService;

// ── resolve target account ────────────────────────────────────────────────────
$argAccountId = $argv[1] ?? null;
$argPageId    = $argv[2] ?? null;
$argLandingUrl = $argv[3] ?? null;

if ($argAccountId) {
    $accountId = ltrim($argAccountId, 'act_');
    $customer  = Customer::where('facebook_ads_account_id', $accountId)->first();
} else {
    $customer  = Customer::where('facebook_bm_owned', true)
        ->whereNotNull('facebook_ads_account_id')
        ->first();
    $accountId = $customer?->facebook_ads_account_id;
}

if (!$accountId) {
    echo "ERROR: No target ad account. Pass one: php scripts/test_facebook_campaign.php 1991968421347247\n";
    exit(1);
}

$pageId     = $argPageId     ?? $customer?->facebook_page_id;
$landingUrl = $argLandingUrl ?? $customer?->website ?? 'https://sitetospend.com.au';

$bm = new BusinessManagerService();
if (!$bm->isConfigured()) {
    echo "ERROR: FACEBOOK_SYSTEM_USER_TOKEN / FACEBOOK_BUSINESS_MANAGER_ID missing\n";
    exit(1);
}

$token   = $bm->getSystemUserToken();
$version = 'v18.0';
$base    = "https://graph.facebook.com/{$version}";

echo "=== Facebook End-to-End Ad Creation Test ===\n";
echo "Ad Account : act_{$accountId}\n";
echo "Page ID    : " . ($pageId ?: '(none — creative step will be skipped)') . "\n";
echo "Landing URL: {$landingUrl}\n\n";

$created = ['campaign' => null, 'adset' => null, 'creative' => null, 'ad' => null, 'image_hash' => null];

// ── Step 1: verify account access ────────────────────────────────────────────
echo "Step 1: Verify System User access...\n";
$verify = $bm->verifyAdAccountAccess($accountId);
if (!$verify['success']) { echo "FAILED: " . $verify['error'] . "\n"; exit(1); }
echo "  OK — \"{$verify['name']}\"\n\n";

// ── Step 2: create campaign (CBO, PAUSED) ────────────────────────────────────
echo "Step 2: Create PAUSED campaign...\n";
$r = \Illuminate\Support\Facades\Http::post("{$base}/act_{$accountId}/campaigns", [
    'name'                            => '[SPECTRA TEST] ' . date('Y-m-d H:i:s'),
    'objective'                       => 'OUTCOME_TRAFFIC',
    'status'                          => 'PAUSED',
    'special_ad_categories'           => [],
    'daily_budget'                    => 500,
    'is_adset_budget_sharing_enabled' => false,
    'bid_strategy'                    => 'LOWEST_COST_WITHOUT_CAP',
    'access_token'                    => $token,
]);
$b = $r->json();
if (!$r->successful() || !isset($b['id'])) {
    echo "FAILED:\n" . json_encode($b, JSON_PRETTY_PRINT) . "\n"; exit(1);
}
$created['campaign'] = $b['id'];
echo "  OK — campaign_id: {$created['campaign']}\n\n";

// ── Step 3: create ad set (no budget — CBO handles it) ───────────────────────
echo "Step 3: Create PAUSED ad set...\n";
$r = \Illuminate\Support\Facades\Http::post("{$base}/act_{$accountId}/adsets", [
    'campaign_id'      => $created['campaign'],
    'name'             => '[SPECTRA TEST] Ad Set',
    'billing_event'    => 'IMPRESSIONS',
    'optimization_goal'=> 'LANDING_PAGE_VIEWS',
    'targeting'        => json_encode([
        'geo_locations' => ['countries' => ['AU']],
        'age_min' => 18,
        'age_max' => 65,
    ]),
    'status'           => 'PAUSED',
    'access_token'     => $token,
]);
$b = $r->json();
if (!$r->successful() || !isset($b['id'])) {
    echo "FAILED:\n" . json_encode($b, JSON_PRETTY_PRINT) . "\n";
    goto cleanup;
}
$created['adset'] = $b['id'];
echo "  OK — adset_id: {$created['adset']}\n\n";

// ── Step 4: upload a test image ──────────────────────────────────────────────
echo "Step 4: Upload test image...\n";

// Generate a minimal 1200x628 JPEG in memory using GD
if (!function_exists('imagecreatetruecolor')) {
    echo "  SKIP — GD extension not available, using external placeholder\n";
    // Fall back to downloading a tiny placeholder
    $imgData = @file_get_contents('https://placehold.co/1200x628.jpg');
} else {
    $img  = imagecreatetruecolor(1200, 628);
    $blue = imagecolorallocate($img, 30, 100, 200);
    imagefill($img, 0, 0, $blue);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagestring($img, 5, 400, 280, 'SPECTRA TEST AD', $white);
    ob_start();
    imagejpeg($img, null, 85);
    $imgData = ob_get_clean();
    imagedestroy($img);
}

if (!$imgData) {
    echo "  SKIP — could not generate/download test image\n";
} else {
    $tempImg = sys_get_temp_dir() . '/spectra_test_' . uniqid() . '.jpg';
    file_put_contents($tempImg, $imgData);

    $r = \Illuminate\Support\Facades\Http::asMultipart()
        ->attach('source', fopen($tempImg, 'r'), 'test.jpg')
        ->post("{$base}/act_{$accountId}/adimages", ['access_token' => $token]);
    @unlink($tempImg);

    $b = $r->json();
    if ($r->successful() && isset($b['images'])) {
        $first = reset($b['images']);
        $created['image_hash'] = $first['hash'] ?? null;
        echo "  OK — image_hash: {$created['image_hash']}\n\n";
    } else {
        echo "  FAILED:\n" . json_encode($b, JSON_PRETTY_PRINT) . "\n";
    }
}

// ── Step 5: create ad creative ───────────────────────────────────────────────
if ($pageId && $created['image_hash']) {
    echo "Step 5: Create ad creative...\n";
    $r = \Illuminate\Support\Facades\Http::post("{$base}/act_{$accountId}/adcreatives", [
        'name'               => '[SPECTRA TEST] Creative',
        'object_story_spec'  => json_encode([
            'page_id'   => $pageId,
            'link_data' => [
                'image_hash'     => $created['image_hash'],
                'link'           => $landingUrl,
                'message'        => 'This is a Spectra platform test ad.',
                'name'           => 'Spectra Test',
                'call_to_action' => ['type' => 'LEARN_MORE'],
            ],
        ]),
        'access_token' => $token,
    ]);
    $b = $r->json();
    if ($r->successful() && isset($b['id'])) {
        $created['creative'] = $b['id'];
        echo "  OK — creative_id: {$created['creative']}\n\n";
    } else {
        echo "  FAILED:\n" . json_encode($b, JSON_PRETTY_PRINT) . "\n";
    }
} elseif (!$pageId) {
    echo "Step 5: SKIP creative — no Page ID (pass as 3rd arg or set facebook_page_id on customer)\n\n";
} else {
    echo "Step 5: SKIP creative — image upload failed\n\n";
}

// ── Step 6: create ad ────────────────────────────────────────────────────────
if ($created['adset'] && $created['creative']) {
    echo "Step 6: Create ad...\n";
    $r = \Illuminate\Support\Facades\Http::post("{$base}/act_{$accountId}/ads", [
        'name'         => '[SPECTRA TEST] Ad',
        'adset_id'     => $created['adset'],
        'creative'     => json_encode(['creative_id' => $created['creative']]),
        'status'       => 'PAUSED',
        'access_token' => $token,
    ]);
    $b = $r->json();
    if ($r->successful() && isset($b['id'])) {
        $created['ad'] = $b['id'];
        echo "  OK — ad_id: {$created['ad']}\n\n";
    } else {
        echo "  FAILED:\n" . json_encode($b, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "Step 6: SKIP ad — creative not available\n\n";
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
cleanup:
echo "Cleanup: deleting test objects...\n";

foreach (['ad' => $created['ad'], 'creative' => $created['creative'], 'adset' => $created['adset'], 'campaign' => $created['campaign']] as $type => $id) {
    if (!$id) continue;
    $del = \Illuminate\Support\Facades\Http::delete("{$base}/{$id}", ['access_token' => $token]);
    $ok  = $del->successful() && ($del->json()['success'] ?? false);
    echo "  {$type} {$id}: " . ($ok ? "deleted" : "could not delete — remove manually") . "\n";
}

echo "\n";

// ── Result ────────────────────────────────────────────────────────────────────
$allPassed = $created['campaign'] && $created['adset'];
$fullFlow  = $created['campaign'] && $created['adset'] && $created['creative'] && $created['ad'];

if ($fullFlow) {
    echo "✅  RESULT: Full ad creation flow works (campaign → ad set → creative → ad).\n";
    exit(0);
} elseif ($allPassed) {
    echo "⚠️   RESULT: Campaign + ad set work. Creative/ad skipped (need Page ID to proceed).\n";
    echo "    Re-run with page_id: php scripts/test_facebook_campaign.php {$accountId} <PAGE_ID>\n";
    exit(0);
} else {
    echo "❌  RESULT: Test failed — see errors above.\n";
    exit(1);
}
