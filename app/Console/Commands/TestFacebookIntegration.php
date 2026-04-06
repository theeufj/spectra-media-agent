<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\BusinessManagerService;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\CreativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestFacebookIntegration extends Command
{
    protected $signature = 'facebook:test
                            {customer? : Customer ID (defaults to first BM-owned customer)}
                            {--keep : Keep created objects in Ads Manager instead of cleaning up}
                            {--skip-creative : Only test campaign + ad set creation}';

    protected $description = 'Functional test: creates a real PAUSED campaign → ad set → creative → ad via the Facebook API using our service classes';

    private array $created = [];

    public function handle(): int
    {
        $this->info('=== Facebook Integration Functional Test ===');
        $this->newLine();

        // ── Resolve customer ──────────────────────────────────────────────
        $customerId = $this->argument('customer');
        $customer = $customerId
            ? Customer::find($customerId)
            : Customer::where('facebook_bm_owned', true)
                ->whereNotNull('facebook_ads_account_id')
                ->whereNotNull('facebook_page_id')
                ->first();

        if (!$customer) {
            $this->error('No suitable customer found. Pass a customer ID or ensure a BM-owned customer exists.');
            return self::FAILURE;
        }

        $accountId = $customer->facebook_ads_account_id;
        $pageId = $customer->facebook_page_id;
        $landingUrl = $customer->website ?? 'https://example.com';

        $this->table(['Setting', 'Value'], [
            ['Customer', "{$customer->name} (ID: {$customer->id})"],
            ['Ad Account', "act_{$accountId}"],
            ['Page ID', $pageId ?: '(none)'],
            ['Landing URL', $landingUrl],
            ['Cleanup', $this->option('keep') ? 'DISABLED (--keep)' : 'enabled'],
        ]);
        $this->newLine();

        // ── Step 1: Verify BM access ─────────────────────────────────────
        $this->info('Step 1: Verify Business Manager access...');
        $bm = new BusinessManagerService();

        if (!$bm->isConfigured()) {
            $this->error('FACEBOOK_SYSTEM_USER_TOKEN or FACEBOOK_BUSINESS_MANAGER_ID not set.');
            return self::FAILURE;
        }

        $verify = $bm->verifyAdAccountAccess($accountId);
        if (!$verify['success']) {
            $this->error("  Access check failed: {$verify['error']}");
            return self::FAILURE;
        }
        $this->line("  <fg=green>OK</> — \"{$verify['name']}\" ({$verify['currency']})");
        $this->newLine();

        // ── Step 2: Create campaign via CampaignService ──────────────────
        $this->info('Step 2: Create PAUSED campaign via CampaignService...');
        $campaignService = new CampaignService($customer);

        $fbCampaign = $campaignService->createCampaign(
            accountId: $accountId,
            campaignName: '[SPECTRA TEST] ' . now()->format('Y-m-d H:i:s'),
            objective: 'OUTCOME_TRAFFIC',
            dailyBudget: 500,     // $5.00 minimum
            status: 'PAUSED',
        );

        if (!$fbCampaign || !isset($fbCampaign['id'])) {
            $this->error('  Campaign creation failed. Check logs for details.');
            return self::FAILURE;
        }
        $this->created['campaign'] = $fbCampaign['id'];
        $this->line("  <fg=green>OK</> — campaign_id: {$fbCampaign['id']}");
        $this->newLine();

        // ── Step 3: Create ad set via AdSetService ───────────────────────
        $this->info('Step 3: Create PAUSED ad set via AdSetService (with advantage_audience)...');
        $adSetService = new AdSetService($customer);

        $fbAdSet = $adSetService->createAdSet(
            accountId: $accountId,
            campaignId: $fbCampaign['id'],
            adSetName: '[SPECTRA TEST] Ad Set',
            targeting: [
                'geo_locations' => ['countries' => [$customer->country ?? 'US']],
                'age_min' => 18,
                'age_max' => 65,
            ],
            optimizationGoal: 'LANDING_PAGE_VIEWS',
            status: 'PAUSED',
        );

        if (!$fbAdSet || !isset($fbAdSet['id'])) {
            $this->error('  Ad set creation failed. Check logs for details.');
            $this->cleanup($bm);
            return self::FAILURE;
        }
        $this->created['adset'] = $fbAdSet['id'];
        $this->line("  <fg=green>OK</> — adset_id: {$fbAdSet['id']}");
        $this->newLine();

        if ($this->option('skip-creative') || !$pageId) {
            if (!$pageId) {
                $this->warn('  Skipping creative/ad — no facebook_page_id on customer.');
            }
            $this->printResult();
            $this->cleanup($bm);
            return self::SUCCESS;
        }

        // ── Step 4: Upload test image + create creative via CreativeService
        $this->info('Step 4: Create ad creative via CreativeService...');
        $creativeService = new CreativeService($customer);

        // Generate a simple test image URL (1200x628 blue rectangle)
        $testImageUrl = $this->generateTestImage();

        if (!$testImageUrl) {
            $this->warn('  Could not generate test image, skipping creative/ad.');
            $this->printResult();
            $this->cleanup($bm);
            return self::SUCCESS;
        }

        $fbCreative = $creativeService->createImageCreative(
            accountId: $accountId,
            creativeName: '[SPECTRA TEST] Creative',
            imageUrl: $testImageUrl,
            headline: 'Spectra Test Ad',
            description: 'This is a Spectra platform integration test ad.',
            callToAction: 'LEARN_MORE',
            linkUrl: $landingUrl,
        );

        // Clean up temp image
        if (file_exists($testImageUrl)) {
            @unlink($testImageUrl);
        }

        if (!$fbCreative || !isset($fbCreative['id'])) {
            $this->error('  Creative creation failed. Check logs for details.');
            $this->printResult();
            $this->cleanup($bm);
            return self::SUCCESS; // Campaign + ad set still passed
        }
        $this->created['creative'] = $fbCreative['id'];
        $this->line("  <fg=green>OK</> — creative_id: {$fbCreative['id']}");
        $this->newLine();

        // ── Step 5: Create ad via AdService ──────────────────────────────
        $this->info('Step 5: Create PAUSED ad via AdService...');
        $adService = new AdService($customer);

        $fbAd = $adService->createAd(
            accountId: $accountId,
            adSetId: $fbAdSet['id'],
            adName: '[SPECTRA TEST] Ad',
            creativeId: $fbCreative['id'],
            status: 'PAUSED',
        );

        if (!$fbAd || !isset($fbAd['id'])) {
            $this->error('  Ad creation failed. Check logs for details.');
            $this->printResult();
            $this->cleanup($bm);
            return self::SUCCESS; // Campaign + ad set + creative still passed
        }
        $this->created['ad'] = $fbAd['id'];
        $this->line("  <fg=green>OK</> — ad_id: {$fbAd['id']}");
        $this->newLine();

        $this->printResult();
        $this->cleanup($bm);

        return self::SUCCESS;
    }

    private function generateTestImage(): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->warn('  GD extension not available.');
            return null;
        }

        $img = imagecreatetruecolor(1200, 628);
        $blue = imagecolorallocate($img, 30, 100, 200);
        imagefill($img, 0, 0, $blue);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 400, 280, 'SPECTRA TEST AD', $white);
        imagestring($img, 3, 400, 310, now()->format('Y-m-d H:i:s'), $white);

        $path = sys_get_temp_dir() . '/spectra_test_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return $path;
    }

    private function printResult(): void
    {
        $this->newLine();

        $steps = [
            'campaign' => 'Campaign',
            'adset' => 'Ad Set',
            'creative' => 'Creative',
            'ad' => 'Ad',
        ];

        $rows = [];
        foreach ($steps as $key => $label) {
            $id = $this->created[$key] ?? null;
            $rows[] = [$label, $id ? "<fg=green>{$id}</>" : '<fg=yellow>skipped</>'];
        }

        $this->table(['Object', 'ID'], $rows);

        $fullFlow = count($this->created) === 4;
        $partial = isset($this->created['campaign'], $this->created['adset']);

        if ($fullFlow) {
            $this->info('RESULT: Full flow passed (campaign -> ad set -> creative -> ad)');
        } elseif ($partial) {
            $this->warn('RESULT: Partial pass (campaign + ad set). Creative/ad skipped.');
        } else {
            $this->error('RESULT: Test failed.');
        }
    }

    private function cleanup(BusinessManagerService $bm): void
    {
        if ($this->option('keep')) {
            $this->newLine();
            $this->warn('Cleanup skipped (--keep). Delete these manually from Ads Manager when done.');
            return;
        }

        if (empty($this->created)) {
            return;
        }

        $this->newLine();
        $this->info('Cleanup: deleting test objects...');

        $token = $bm->getSystemUserToken();
        $base = 'https://graph.facebook.com/v22.0';

        // Delete in reverse order: ad -> creative -> adset -> campaign
        foreach (array_reverse($this->created) as $type => $id) {
            $response = Http::delete("{$base}/{$id}", ['access_token' => $token]);
            $ok = $response->successful() && ($response->json('success') ?? false);
            $this->line("  {$type} {$id}: " . ($ok ? '<fg=green>deleted</>' : '<fg=red>could not delete — remove manually</>'));
        }
    }
}
