<?php

/**
 * Test Facebook campaign creation in a BM-managed ad account.
 *
 * Usage (from project root):
 *   php scripts/test_facebook_campaign.php [ad_account_id]
 *
 * If no account ID is provided it falls back to the first customer
 * with facebook_bm_owned = 1.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Services\FacebookAds\BusinessManagerService;

// ── resolve target account ────────────────────────────────────────────────────
$argAccountId = $argv[1] ?? null;

if ($argAccountId) {
    $accountId = ltrim($argAccountId, 'act_');
    // create a throw-away stub customer so CampaignService can resolve the token
    $customer = Customer::where('facebook_ads_account_id', $accountId)->first();
    if (!$customer) {
        // Use SystemUser token directly via a fake customer-like call
        $customer = null;
    }
} else {
    $customer = Customer::where('facebook_bm_owned', true)
        ->whereNotNull('facebook_ads_account_id')
        ->first();
    $accountId = $customer?->facebook_ads_account_id;
}

if (!$accountId) {
    echo "ERROR: No target ad account found. Pass one as argument: php scripts/test_facebook_campaign.php 1991968421347247\n";
    exit(1);
}

$bm = new BusinessManagerService();

if (!$bm->isConfigured()) {
    echo "ERROR: Facebook BM not configured — FACEBOOK_SYSTEM_USER_TOKEN / FACEBOOK_BUSINESS_MANAGER_ID missing from .env\n";
    exit(1);
}

$token   = $bm->getSystemUserToken();
$version = 'v18.0';
$baseUrl = "https://graph.facebook.com/{$version}";

echo "=== Facebook Campaign Creation Test ===\n";
echo "Ad Account : act_{$accountId}\n\n";

// ── Step 1: verify account access ────────────────────────────────────────────
echo "Step 1: Verifying System User access to act_{$accountId}...\n";
$verify = $bm->verifyAdAccountAccess($accountId);
if (!$verify['success']) {
    echo "FAILED: " . $verify['error'] . "\n";
    exit(1);
}
echo "  OK — account name: \"" . ($verify['name'] ?? 'unknown') . "\", status: " . ($verify['account_status'] ?? 'unknown') . "\n\n";

// ── Step 2: create a PAUSED test campaign ────────────────────────────────────
echo "Step 2: Creating PAUSED test campaign...\n";

$campaignName = '[SPECTRA TEST] Campaign — ' . date('Y-m-d H:i:s');

$response = \Illuminate\Support\Facades\Http::post("{$baseUrl}/act_{$accountId}/campaigns", [
    'name'                              => $campaignName,
    'objective'                         => 'OUTCOME_TRAFFIC',
    'status'                            => 'PAUSED',
    'special_ad_categories'             => [],
    'daily_budget'                      => 500,  // $5.00 in cents
    // CBO mode: budget set at campaign level, is_adset_budget_sharing_enabled must be false
    'is_adset_budget_sharing_enabled'   => false,
    'access_token'                      => $token,
]);

$body = $response->json();

if ($response->successful() && isset($body['id'])) {
    $campaignId = $body['id'];
    echo "  SUCCESS — campaign_id: {$campaignId}\n\n";

    // ── Step 3: delete it immediately ────────────────────────────────────────
    echo "Step 3: Deleting test campaign ({$campaignId})...\n";
    $del = \Illuminate\Support\Facades\Http::delete("{$baseUrl}/{$campaignId}", [
        'access_token' => $token,
    ]);
    if ($del->successful() && ($del->json()['success'] ?? false)) {
        echo "  Deleted OK.\n\n";
    } else {
        echo "  Could not delete (you may need to remove it manually): " . json_encode($del->json()) . "\n\n";
    }

    echo "✅  RESULT: Campaign creation works. Facebook deployment is ready.\n";
    exit(0);
} else {
    echo "FAILED:\n";
    echo json_encode($body, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}
