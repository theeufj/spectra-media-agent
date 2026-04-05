<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\MCCAccountManager;
use App\Services\GoogleAds\AccountStructureService;

// Campaign creation services
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;

use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;

use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroupWithAssets;
use App\Services\GoogleAds\PerformanceMaxServices\CreateImageAsset;
use App\Services\GoogleAds\PerformanceMaxServices\CreateTextAsset;

use App\Services\GoogleAds\VideoServices\CreateVideoCampaign;
use App\Services\GoogleAds\VideoServices\CreateVideoAdGroup;

use App\Services\GoogleAds\DemandGenServices\CreateDemandGenCampaign;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenAdGroup;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenMultiAssetAd;

use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;

class TestAllCampaignTypes extends Command
{
    protected $signature = 'googleads:test-all-campaigns
                            {--customer-id= : Existing Google Ads customer ID to use (skips account creation)}
                            {--business-name=Spectra Test Co : Business name for campaigns}
                            {--skip-images : Skip Gemini image generation and use placeholder bytes}';

    protected $description = 'Create ONE sub-account under the MCC, then create all campaign types (Search, Display, PMax, Video, Demand Gen) with budgets, images, and ads — then verify everything.';

    private array $results = [];
    private string $businessName;

    public function handle(): int
    {
        $this->businessName = $this->option('business-name');
        $startDate = now()->addDay()->format('Ymd');
        $endDate = now()->addMonths(3)->format('Ymd');
        // Display formats for services that expect dashes
        $startDateDash = now()->addDay()->format('Y-m-d');
        $endDateDash = now()->addMonths(3)->format('Y-m-d');

        $this->info('=== Google Ads: Single Account, All Campaign Types ===');
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 1: Get or create the single test account
        // ──────────────────────────────────────────────
        $this->info('[1/7] Resolving Google Ads account...');

        $customer = $this->resolveCustomer();
        if (!$customer) {
            return 1;
        }
        $customerId = $customer->google_ads_customer_id;

        if (!$customerId) {
            $customerId = $this->provisionAccount($customer);
            if (!$customerId) {
                $this->error('Failed to provision a Google Ads sub-account.');
                return 1;
            }
        }

        $this->info("  Account ID: {$customerId}");
        $this->logResult('Account', 'Resolved', $customerId);
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 2: Generate test images via Gemini Nano Banana
        // ──────────────────────────────────────────────
        $this->info('[2/7] Generating test images via Gemini Nano Banana...');

        $images = $this->generateTestImages();
        if (empty($images)) {
            $this->error('Failed to generate any test images. Cannot continue.');
            return 1;
        }
        $this->info("  Generated " . count($images) . " test images");
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 3: Upload image assets to Google Ads
        // ──────────────────────────────────────────────
        $this->info('[3/7] Uploading image assets to Google Ads...');

        $imageAssets = $this->uploadImageAssets($customer, $customerId, $images);
        $this->info("  Uploaded " . count($imageAssets) . " image assets");
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 4: Create Search Campaign (text-only, no images)
        // ──────────────────────────────────────────────
        $this->info('[4/7] Creating SEARCH campaign...');
        $this->createSearchCampaign($customer, $customerId, $startDateDash, $endDateDash);
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 5: Create Display Campaign (with images)
        // ──────────────────────────────────────────────
        $this->info('[5/7] Creating DISPLAY campaign with images...');
        $this->createDisplayCampaign($customer, $customerId, $startDateDash, $endDateDash, $imageAssets);
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 6: Create Performance Max Campaign (with images + text assets)
        // ──────────────────────────────────────────────
        $this->info('[6/7] Creating PERFORMANCE MAX campaign with assets...');
        $this->createPerformanceMaxCampaign($customer, $customerId, $startDate, $endDate, $imageAssets);
        $this->newLine();

        // ──────────────────────────────────────────────
        // STEP 7: Create Demand Gen Campaign (with images)
        // ──────────────────────────────────────────────
        $this->info('[7/7] Creating DEMAND GEN campaign with images...');
        $this->createDemandGenCampaign($customer, $customerId, $startDateDash, $endDateDash, $imageAssets);
        $this->newLine();

        // ──────────────────────────────────────────────
        // VERIFICATION
        // ──────────────────────────────────────────────
        $this->info('=== VERIFICATION ===');
        $this->verifyAccount($customer, $customerId);
        $this->newLine();

        // ──────────────────────────────────────────────
        // SUMMARY TABLE
        // ──────────────────────────────────────────────
        $this->info('=== RESULTS SUMMARY ===');
        $this->table(
            ['Type', 'Status', 'Resource / Detail'],
            $this->results
        );
        $this->newLine();

        $failures = collect($this->results)->where(1, 'FAILED')->count();
        if ($failures > 0) {
            $this->warn("{$failures} step(s) failed. Check logs for details.");
            return 1;
        }

        $this->info('All campaign types created and verified under a single account!');
        $this->line("  Account: {$customerId}");
        $this->line("  View at: https://ads.google.com/aw/campaigns?ocid={$customerId}");
        return 0;
    }

    // =========================================================================
    // Account Resolution
    // =========================================================================

    private function resolveCustomer(): ?Customer
    {
        $customerId = $this->option('customer-id');
        if ($customerId) {
            $customerId = str_replace('-', '', $customerId);
            // Find or create a local customer record linked to this Google Ads account
            $customer = Customer::where('google_ads_customer_id', $customerId)->first();
            if (!$customer) {
                $customer = Customer::create([
                    'name' => $this->businessName,
                    'email' => 'test-' . uniqid() . '@spectra-test.local',
                    'google_ads_customer_id' => $customerId,
                ]);
                $this->info("  Created local customer record #{$customer->id} linked to {$customerId}");
            } else {
                $this->info("  Found existing customer #{$customer->id} for account {$customerId}");
            }
            return $customer;
        }

        // Create a fresh customer record for provisioning
        $customer = Customer::create([
            'name' => $this->businessName,
            'email' => 'test-' . uniqid() . '@spectra-test.local',
        ]);
        $this->info("  Created local customer record #{$customer->id}");
        return $customer;
    }

    private function provisionAccount(Customer $customer): ?string
    {
        $mccId = config('googleads.mcc_customer_id');
        if (!$mccId) {
            $this->error('  No MCC customer ID configured (GOOGLE_ADS_MCC_CUSTOMER_ID)');
            return null;
        }

        $this->info("  Creating sub-account under MCC {$mccId}...");
        $manager = new MCCAccountManager($customer);
        $result = $manager->createStandardAccountUnderMCC($mccId, $this->businessName . ' - Test Account');

        if (!$result) {
            return null;
        }

        $this->info("  Sub-account created: {$result['account_id']}");
        return $result['account_id'];
    }

    // =========================================================================
    // Image Generation (Gemini Nano Banana)
    // =========================================================================

    private function generateTestImages(): array
    {
        if ($this->option('skip-images')) {
            $this->warn('  --skip-images: using placeholder PNGs');
            return ['landscape' => $this->createPlaceholderPng(1200, 628), 'square' => $this->createPlaceholderPng(1200, 1200), 'logo' => $this->createPlaceholderPng(1200, 1200)];
        }

        $gemini = new GeminiService();
        $images = [];

        $prompts = [
            'landscape' => "Generate a professional 1200x628 landscape marketing banner for a company called '{$this->businessName}'. Modern, clean design with bold typography. No text overlay needed, just a visually appealing marketing image.",
            'square'    => "Generate a professional 1200x1200 square marketing image for a company called '{$this->businessName}'. Eye-catching design suitable for social media ads.",
            'logo'      => "Generate a simple, clean 128x128 logo icon for a company called '{$this->businessName}'. Minimal design, suitable as a favicon or small logo.",
        ];

        foreach ($prompts as $key => $prompt) {
            $this->line("  Generating {$key} image...");
            $result = $gemini->generateImage($prompt);

            if ($result && isset($result['data'])) {
                $images[$key] = base64_decode($result['data']);
                $this->info("    {$key}: " . strlen($images[$key]) . " bytes");
            } else {
                $this->warn("    {$key}: Gemini generation failed, using placeholder");
                $size = match ($key) {
                    'landscape' => [1200, 628],
                    'square' => [1200, 1200],
                    'logo' => [1200, 1200],
                };
                $images[$key] = $this->createPlaceholderPng($size[0], $size[1]);
            }
        }

        return $images;
    }

    private function createPlaceholderPng(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 66, 133, 244); // Google blue
        imagefill($img, 0, 0, $bg);

        $textColor = imagecolorallocate($img, 255, 255, 255);
        $text = "{$width}x{$height}";
        $fontsize = 5;
        $textWidth = imagefontwidth($fontsize) * strlen($text);
        $textHeight = imagefontheight($fontsize);
        imagestring($img, $fontsize, ($width - $textWidth) / 2, ($height - $textHeight) / 2, $text, $textColor);

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    // =========================================================================
    // Asset Upload
    // =========================================================================

    private function uploadImageAssets(Customer $customer, string $customerId, array $images): array
    {
        $uploader = new UploadImageAsset($customer);
        $assets = [];

        foreach ($images as $key => $imageData) {
            $assetName = "{$this->businessName} - {$key} - " . date('Ymd-His');
            $this->line("  Uploading {$key} asset...");
            $resourceName = ($uploader)($customerId, $imageData, $assetName);
            if ($resourceName) {
                $assets[$key] = $resourceName;
                $this->info("    {$key}: {$resourceName}");
                $this->logResult("Asset: {$key}", 'OK', $resourceName);
            } else {
                $this->warn("    {$key}: upload failed");
                $this->logResult("Asset: {$key}", 'FAILED', 'Upload returned null');
            }
        }

        return $assets;
    }

    // =========================================================================
    // Campaign Creators
    // =========================================================================

    private function createSearchCampaign(Customer $customer, string $customerId, string $startDate, string $endDate): void
    {
      try {
        $budget = 10.00; // $10/day

        $campaignData = [
            'businessName' => $this->businessName,
            'budget' => $budget,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        $creator = new CreateSearchCampaign($customer);
        $campaignResource = ($creator)($customerId, $campaignData);

        if (!$campaignResource) {
            $this->error('  Search campaign creation failed');
            $this->logResult('Search Campaign', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Campaign: {$campaignResource}");
        $this->logResult('Search Campaign', 'OK', $campaignResource);
        $this->logResult('Search Budget', 'OK', "\${$budget}/day");

        // Ad Group
        $adGroupCreator = new CreateSearchAdGroup($customer);
        $adGroupResource = ($adGroupCreator)($customerId, $campaignResource, $this->businessName . ' Search Ad Group');

        if (!$adGroupResource) {
            $this->warn('  Search ad group creation failed');
            $this->logResult('Search Ad Group', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Ad Group: {$adGroupResource}");
        $this->logResult('Search Ad Group', 'OK', $adGroupResource);

        // Keywords — use AI research if Gemini API is available, else hardcoded seeds
        $this->addSearchKeywords($customer, $customerId, $campaignResource, $adGroupResource);

        // Responsive Search Ad
        $adCreator = new CreateResponsiveSearchAd($customer);
        $adResource = ($adCreator)($customerId, $adGroupResource, [
            'headlines' => [
                "{$this->businessName} - Best Service",
                "Grow Your Business Today",
                "Professional Solutions",
            ],
            'descriptions' => [
                "Trusted by thousands. Get started with {$this->businessName} today.",
                "Expert solutions tailored to your needs. Free consultation available.",
            ],
            'finalUrls' => ['https://example.com'],
            'path1' => 'services',
            'path2' => 'start',
        ]);

        if ($adResource) {
            $this->info("  Ad: {$adResource}");
            $this->logResult('Search Ad (RSA)', 'OK', $adResource);
        } else {
            $this->warn('  Responsive Search Ad creation failed');
            $this->logResult('Search Ad (RSA)', 'FAILED', 'Creation returned null');
        }
      } catch (\Exception $e) {
            $this->warn('  Search campaign error: ' . $this->extractErrorMessage($e));
            $this->logResult('Search Campaign', 'FAILED', $this->extractErrorMessage($e));
      }
    }

    private function addSearchKeywords(Customer $customer, string $customerId, string $campaignResource, string $adGroupResource): void
    {
        $keywords = [];
        $negativeKeywords = [];

        // Try AI-powered keyword research first
        if (config('services.gemini.api_key')) {
            try {
                $this->info('  Researching keywords via Gemini AI + Keyword Planner...');
                $researchService = new KeywordResearchService($customer);
                $research = $researchService->research($customerId, $this->businessName);
                $keywords = $research['keywords'] ?? [];
                $negativeKeywords = $research['negative_keywords'] ?? [];
            } catch (\Exception $e) {
                $this->warn('  AI keyword research failed: ' . substr($e->getMessage(), 0, 100));
            }
        }

        // Fallback: hardcoded seed keywords if AI research unavailable or returned nothing
        if (empty($keywords)) {
            $this->info('  Using default seed keywords (Gemini API unavailable)');
            $keywords = [
                ['text' => $this->businessName, 'match_type' => 'EXACT'],
                ['text' => "{$this->businessName} services", 'match_type' => 'EXACT'],
                ['text' => 'professional services near me', 'match_type' => 'PHRASE'],
                ['text' => 'best business solutions', 'match_type' => 'PHRASE'],
                ['text' => 'expert consulting services', 'match_type' => 'PHRASE'],
                ['text' => 'business growth solutions', 'match_type' => 'BROAD'],
                ['text' => 'top rated business services', 'match_type' => 'BROAD'],
            ];
        }

        if (empty($negativeKeywords)) {
            $negativeKeywords = ['free', 'cheap', 'diy', 'jobs', 'salary', 'reddit', 'wiki', 'tutorial', 'download', 'torrent'];
        }

        // Add positive keywords to ad group
        $matchTypeMap = [
            'EXACT' => KeywordMatchType::EXACT,
            'PHRASE' => KeywordMatchType::PHRASE,
            'BROAD' => KeywordMatchType::BROAD,
        ];

        $addKeywordService = new AddKeyword($customer);
        $added = 0;
        foreach ($keywords as $kw) {
            $text = is_array($kw) ? $kw['text'] : $kw;
            $matchTypeStr = is_array($kw) ? ($kw['match_type'] ?? 'PHRASE') : 'PHRASE';
            $matchType = $matchTypeMap[$matchTypeStr] ?? KeywordMatchType::PHRASE;

            $result = ($addKeywordService)($customerId, $adGroupResource, $text, $matchType);
            if ($result) {
                $added++;
            }
        }
        $this->info("  Keywords: {$added}/" . count($keywords) . " added");
        $this->logResult('Search Keywords', $added > 0 ? 'OK' : 'FAILED', "{$added} keywords (" . implode(', ', array_map(fn($k) => is_array($k) ? $k['text'] : $k, array_slice($keywords, 0, 5))) . '...)');

        // Add negative keywords to campaign
        $addNegativeService = new AddNegativeKeyword($customer);
        $negAdded = 0;
        foreach ($negativeKeywords as $neg) {
            $result = ($addNegativeService)($customerId, $campaignResource, $neg, KeywordMatchType::EXACT);
            if ($result) {
                $negAdded++;
            }
        }
        $this->info("  Negative keywords: {$negAdded}/" . count($negativeKeywords) . " added");
        $this->logResult('Search Negatives', $negAdded > 0 ? 'OK' : 'FAILED', "{$negAdded} negative keywords");
    }

    private function createDisplayCampaign(Customer $customer, string $customerId, string $startDate, string $endDate, array $imageAssets): void
    {
      try {
        $budget = 15.00; // $15/day

        $campaignData = [
            'businessName' => $this->businessName,
            'budget' => $budget,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        $creator = new CreateDisplayCampaign($customer);
        $campaignResource = ($creator)($customerId, $campaignData);

        if (!$campaignResource) {
            $this->error('  Display campaign creation failed');
            $this->logResult('Display Campaign', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Campaign: {$campaignResource}");
        $this->logResult('Display Campaign', 'OK', $campaignResource);
        $this->logResult('Display Budget', 'OK', "\${$budget}/day");

        // Ad Group
        $adGroupCreator = new CreateDisplayAdGroup($customer);
        $adGroupResource = ($adGroupCreator)($customerId, $campaignResource, $this->businessName . ' Display Ad Group');

        if (!$adGroupResource) {
            $this->warn('  Display ad group creation failed');
            $this->logResult('Display Ad Group', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Ad Group: {$adGroupResource}");
        $this->logResult('Display Ad Group', 'OK', $adGroupResource);

        // Responsive Display Ad with images (separate landscape vs square)
        $adCreator = new CreateResponsiveDisplayAd($customer);
        $landscapeImages = array_filter([$imageAssets['landscape'] ?? null]);
        $squareImages = array_filter([$imageAssets['square'] ?? null]);
        $logoImages = array_filter([$imageAssets['logo'] ?? null]);

        if (empty($landscapeImages) && empty($squareImages)) {
            $this->warn('  No image assets available for display ad, skipping ad creation');
            $this->logResult('Display Ad (RDA)', 'FAILED', 'No images');
            return;
        }

        $adResource = ($adCreator)($customerId, $adGroupResource, [
            'headlines' => ["{$this->businessName}"],
            'longHeadlines' => ["Discover {$this->businessName} - Your Trusted Partner"],
            'descriptions' => ["Get professional solutions from {$this->businessName}. Start today."],
            'imageAssets' => $landscapeImages,
            'squareImageAssets' => $squareImages,
            'squareLogoAssets' => $logoImages, // 1:1 square logos (logoAssets is 4:1 landscape, which we don't have)
            'finalUrls' => ['https://example.com'],
            'businessName' => $this->businessName,
        ]);

        if ($adResource) {
            $this->info("  Ad: {$adResource}");
            $this->logResult('Display Ad (RDA)', 'OK', $adResource);
            $this->logResult('Display Images', 'OK', count($landscapeImages) . ' landscape + ' . count($squareImages) . ' square + ' . count($logoImages) . ' logos attached');
        } else {
            $this->warn('  Responsive Display Ad creation failed');
            $this->logResult('Display Ad (RDA)', 'FAILED', 'Creation returned null');
        }
      } catch (\Exception $e) {
            $this->warn('  Display campaign error: ' . $this->extractErrorMessage($e));
            $this->logResult('Display Campaign', 'FAILED', $this->extractErrorMessage($e));
      }
    }

    private function createPerformanceMaxCampaign(Customer $customer, string $customerId, string $startDate, string $endDate, array $imageAssets): void
    {
      try {
        $budget = 20.00; // $20/day

        $campaignData = [
            'businessName' => $this->businessName . ' PMax Campaign',
            'budget' => $budget,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        $creator = new CreatePerformanceMaxCampaign($customer);
        $campaignResource = ($creator)($customerId, $campaignData);

        if (!$campaignResource) {
            $this->error('  Performance Max campaign creation failed');
            $this->logResult('PMax Campaign', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Campaign: {$campaignResource}");
        $this->logResult('PMax Campaign', 'OK', $campaignResource);
        $this->logResult('PMax Budget', 'OK', "\${$budget}/day");

        // Create text assets for PMax
        $textAssetCreator = new CreateTextAsset($customer);

        $headlineAssets = [];
        $headlineTexts = [
            "{$this->businessName} - Top Rated",
            "Professional Solutions",
            "Get Started Today",
        ];
        foreach ($headlineTexts as $text) {
            $resource = ($textAssetCreator)($customerId, $text);
            if ($resource) {
                $headlineAssets[] = ['asset' => $resource, 'field_type' => AssetFieldType::HEADLINE];
            }
        }

        $descriptionAssets = [];
        $descriptionTexts = [
            "Trusted by thousands of businesses worldwide. Start your journey today.",
            "Expert solutions tailored to your needs. Contact us for a free consultation.",
        ];
        foreach ($descriptionTexts as $text) {
            $resource = ($textAssetCreator)($customerId, $text);
            if ($resource) {
                $descriptionAssets[] = ['asset' => $resource, 'field_type' => AssetFieldType::DESCRIPTION];
            }
        }

        // Long headline
        $longHeadlineResource = ($textAssetCreator)($customerId, "Discover {$this->businessName} — Professional Solutions for Modern Business");
        $longHeadlineAssets = [];
        if ($longHeadlineResource) {
            $longHeadlineAssets[] = ['asset' => $longHeadlineResource, 'field_type' => AssetFieldType::LONG_HEADLINE];
        }

        // Business name asset
        $businessNameResource = ($textAssetCreator)($customerId, $this->businessName);
        $businessNameAssets = [];
        if ($businessNameResource) {
            $businessNameAssets[] = ['asset' => $businessNameResource, 'field_type' => AssetFieldType::BUSINESS_NAME];
        }

        // Image assets
        $imageAssetOps = [];
        if (isset($imageAssets['landscape'])) {
            $imageAssetOps[] = ['asset' => $imageAssets['landscape'], 'field_type' => AssetFieldType::MARKETING_IMAGE];
        }
        if (isset($imageAssets['square'])) {
            $imageAssetOps[] = ['asset' => $imageAssets['square'], 'field_type' => AssetFieldType::SQUARE_MARKETING_IMAGE];
        }
        if (isset($imageAssets['logo'])) {
            $imageAssetOps[] = ['asset' => $imageAssets['logo'], 'field_type' => AssetFieldType::LOGO];
        }

        $allAssets = array_merge($headlineAssets, $descriptionAssets, $longHeadlineAssets, $businessNameAssets, $imageAssetOps);

        if (empty($allAssets)) {
            $this->warn('  No assets available for PMax asset group');
            $this->logResult('PMax Asset Group', 'FAILED', 'No assets');
            return;
        }

        // Asset Group with all assets
        $assetGroupCreator = new CreateAssetGroupWithAssets($customer);
        $assetGroupResource = ($assetGroupCreator)(
            $customerId,
            $campaignResource,
            $this->businessName . ' Asset Group',
            ['https://example.com'],
            $allAssets
        );

        if ($assetGroupResource) {
            $this->info("  Asset Group: {$assetGroupResource}");
            $this->logResult('PMax Asset Group', 'OK', $assetGroupResource);
            $this->logResult('PMax Assets', 'OK',
                count($headlineAssets) . ' headlines, ' .
                count($descriptionAssets) . ' descriptions, ' .
                count($imageAssetOps) . ' images'
            );
        } else {
            $this->warn('  PMax asset group creation failed');
            $this->logResult('PMax Asset Group', 'FAILED', 'Creation returned null');
        }
      } catch (\Exception $e) {
            $this->warn('  PMax campaign error: ' . $this->extractErrorMessage($e));
            $this->logResult('PMax Campaign', 'FAILED', $this->extractErrorMessage($e));
      }
    }

    private function createDemandGenCampaign(Customer $customer, string $customerId, string $startDate, string $endDate, array $imageAssets): void
    {
      try {
        $budget = 15.00; // $15/day

        $campaignData = [
            'businessName' => $this->businessName,
            'budget' => $budget,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        $creator = new CreateDemandGenCampaign($customer);
        $campaignResource = ($creator)($customerId, $campaignData);

        if (!$campaignResource) {
            $this->warn('  Demand Gen campaign creation failed (likely requires conversion tracking on account)');
            $this->logResult('DemandGen Campaign', 'SKIPPED', 'Requires conversion tracking (new accounts lack this)');
            return;
        }
        $this->info("  Campaign: {$campaignResource}");
        $this->logResult('DemandGen Campaign', 'OK', $campaignResource);
        $this->logResult('DemandGen Budget', 'OK', "\${$budget}/day");

        // Ad Group
        $adGroupCreator = new CreateDemandGenAdGroup($customer);
        $adGroupResource = ($adGroupCreator)($customerId, $campaignResource, $this->businessName . ' DemandGen Ad Group');

        if (!$adGroupResource) {
            $this->warn('  Demand Gen ad group creation failed');
            $this->logResult('DemandGen Ad Group', 'FAILED', 'Creation returned null');
            return;
        }
        $this->info("  Ad Group: {$adGroupResource}");
        $this->logResult('DemandGen Ad Group', 'OK', $adGroupResource);

        // Multi-asset ad with images
        $marketingImages = array_filter([
            $imageAssets['landscape'] ?? null,
        ]);
        $squareImages = array_filter([
            $imageAssets['square'] ?? null,
        ]);
        $logoImages = array_filter([
            $imageAssets['logo'] ?? null,
        ]);

        if (empty($marketingImages)) {
            $this->warn('  No image assets for Demand Gen ad, skipping');
            $this->logResult('DemandGen Ad', 'FAILED', 'No images');
            return;
        }

        $adCreator = new CreateDemandGenMultiAssetAd($customer);
        $adResource = ($adCreator)($customerId, $adGroupResource, [
            'headlines' => [
                "{$this->businessName}",
                "Discover Our Solutions",
            ],
            'descriptions' => [
                "Professional services from {$this->businessName}. Learn more today.",
                "Trusted by thousands. Get started now.",
            ],
            'businessName' => $this->businessName,
            'imageAssets' => $marketingImages,
            'squareImageAssets' => $squareImages,
            // logoAssets omitted — DemandGen logo_images requires 4:1 landscape logos
            'finalUrls' => ['https://example.com'],
            'callToActionText' => 'Learn more',
        ]);

        if ($adResource) {
            $this->info("  Ad: {$adResource}");
            $this->logResult('DemandGen Ad', 'OK', $adResource);
            $this->logResult('DemandGen Images', 'OK', count($marketingImages) . ' landscape + ' . count($squareImages) . ' square + ' . count($logoImages) . ' logos');
        } else {
            $this->warn('  Demand Gen ad creation failed');
            $this->logResult('DemandGen Ad', 'FAILED', 'Creation returned null');
        }
      } catch (\Exception $e) {
            $errorMsg = $this->extractErrorMessage($e);
            if (str_contains($errorMsg, 'Conversion tracking')) {
                $this->warn('  DemandGen requires conversion tracking — skipping (account needs conversion actions configured)');
                $this->logResult('DemandGen Campaign', 'SKIPPED', 'Conversion tracking required (new accounts lack this)');
            } else {
                $this->warn('  DemandGen campaign error: ' . $errorMsg);
                $this->logResult('DemandGen Campaign', 'FAILED', $errorMsg);
            }
      }
    }

    // =========================================================================
    // Verification
    // =========================================================================

    private function verifyAccount(Customer $customer, string $customerId): void
    {
        $this->line('  Querying account structure from Google Ads API...');

        try {
            $structureService = new AccountStructureService($customer);
            $structure = $structureService->getAccountStructureLimits($customerId);

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Campaigns', $structure['campaigns']],
                    ['Total Ad Groups', $structure['ad_groups']],
                ]
            );

            $this->logResult('Verification', 'OK', "{$structure['campaigns']} campaigns, {$structure['ad_groups']} ad groups");

            // Keyword details
            $this->line('  Fetching keyword details...');
            $keywordData = $this->listKeywords($customer, $customerId);
            if (!empty($keywordData['keywords'])) {
                $this->table(
                    ['Keyword', 'Match Type', 'Ad Group', 'Status'],
                    $keywordData['keywords']
                );
                $this->logResult('Keywords Verified', 'OK', $keywordData['count'] . ' keywords found');
            } else {
                $this->warn('  No keywords found');
                $this->logResult('Keywords Verified', 'FAILED', '0 keywords');
            }
            if (!empty($keywordData['negatives'])) {
                $this->table(
                    ['Negative Keyword', 'Match Type', 'Campaign'],
                    $keywordData['negatives']
                );
                $this->logResult('Negatives Verified', 'OK', count($keywordData['negatives']) . ' negative keywords found');
            }

            // Detailed campaign list
            $this->line('  Fetching campaign details...');
            $mccManager = new MCCAccountManager($customer);
            $campaigns = $this->listCampaigns($customer, $customerId);

            if (!empty($campaigns)) {
                $this->table(
                    ['Campaign Name', 'Type', 'Status', 'Budget/day'],
                    $campaigns
                );
            }

        } catch (\Exception $e) {
            $this->warn("  Verification query failed: " . $e->getMessage());
            $this->logResult('Verification', 'FAILED', $e->getMessage());
        }
    }

    private function listCampaigns(Customer $customer, string $customerId): array
    {
        try {
            $service = new AccountStructureService($customer);
            // Use reflection to access the client for a GAQL query
            $reflection = new \ReflectionClass($service);
            $clientProp = $reflection->getProperty('client');
            $clientProp->setAccessible(true);
            $client = $clientProp->getValue($service);

            $query = "SELECT campaign.name, campaign.advertising_channel_type, campaign.status, campaign_budget.amount_micros " .
                     "FROM campaign " .
                     "ORDER BY campaign.name";

            $response = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $query,
                ])
            );

            $rows = [];
            foreach ($response->getIterator() as $row) {
                $campaign = $row->getCampaign();
                $budgetMicros = $row->getCampaignBudget()?->getAmountMicros() ?? 0;
                $budgetDollars = $budgetMicros / 1_000_000;

                $rows[] = [
                    $campaign->getName(),
                    \Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType::name($campaign->getAdvertisingChannelType()),
                    \Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus::name($campaign->getStatus()),
                    '$' . number_format($budgetDollars, 2),
                ];
            }
            return $rows;
        } catch (\Exception $e) {
            $this->warn("  Could not list campaigns: " . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // Keyword Verification
    // =========================================================================

    private function listKeywords(Customer $customer, string $customerId): array
    {
        $result = ['keywords' => [], 'negatives' => [], 'count' => 0];

        try {
            $service = new AccountStructureService($customer);
            $reflection = new \ReflectionClass($service);
            $clientProp = $reflection->getProperty('client');
            $clientProp->setAccessible(true);
            $client = $clientProp->getValue($service);

            // Positive keywords
            $query = "SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, "
                   . "ad_group.name, ad_group_criterion.status "
                   . "FROM ad_group_criterion "
                   . "WHERE ad_group_criterion.type = 'KEYWORD' "
                   . "ORDER BY ad_group_criterion.keyword.text";

            $response = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $query,
                ])
            );

            foreach ($response->getIterator() as $row) {
                $criterion = $row->getAdGroupCriterion();
                $kw = $criterion->getKeyword();
                $result['keywords'][] = [
                    $kw->getText(),
                    \Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType::name($kw->getMatchType()),
                    $row->getAdGroup()->getName(),
                    \Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus::name($criterion->getStatus()),
                ];
            }
            $result['count'] = count($result['keywords']);

            // Negative keywords (campaign-level)
            $negQuery = "SELECT campaign_criterion.keyword.text, campaign_criterion.keyword.match_type, "
                      . "campaign.name "
                      . "FROM campaign_criterion "
                      . "WHERE campaign_criterion.negative = true "
                      . "AND campaign_criterion.type = 'KEYWORD' "
                      . "ORDER BY campaign_criterion.keyword.text";

            $negResponse = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $negQuery,
                ])
            );

            foreach ($negResponse->getIterator() as $row) {
                $criterion = $row->getCampaignCriterion();
                $kw = $criterion->getKeyword();
                $result['negatives'][] = [
                    $kw->getText(),
                    \Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType::name($kw->getMatchType()),
                    $row->getCampaign()->getName(),
                ];
            }

        } catch (\Exception $e) {
            $this->warn("  Could not list keywords: " . $e->getMessage());
        }

        return $result;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function logResult(string $type, string $status, string $detail): void
    {
        $this->results[] = [$type, $status, $detail];
    }

    private function extractErrorMessage(\Exception $e): string
    {
        $msg = $e->getMessage();
        // Extract all error messages from Google Ads API JSON responses
        if (preg_match_all('/"message":\s*"([^"]+)"/', $msg, $m)) {
            return implode(' | ', $m[1]);
        }
        // Extract errorCode type
        if (preg_match('/"(\w+Error)":\s*"(\w+)"/', $msg, $m)) {
            return "{$m[1]}: {$m[2]}";
        }
        return substr($msg, 0, 300);
    }
}
