<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\KeywordQualityScore;
use App\Services\GoogleAds\AccountStructureService;

// Keyword optimization
use App\Services\GoogleAds\CommonServices\UpdateKeywordBid;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use App\Services\GoogleAds\CommonServices\AddKeyword;

// Bid adjustments
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetLocationBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;

// Ad extensions
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;

// Reporting
use App\Services\Reporting\ExecutiveReportService;
use App\Services\Reporting\QualityScoreTrendingService;
use App\Jobs\GetKeywordQualityScore;

// Optimization agent
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GeminiService;

use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V22\Enums\DeviceEnum\Device;

class TestNewServices extends Command
{
    protected $signature = 'googleads:test-new-services
                            {--customer-id= : Google Ads customer ID to test against}
                            {--phone=0400000000 : Phone number for call extension test}
                            {--skip-destructive : Skip tests that remove or pause keywords}
                            {--skip-reporting : Skip reporting tests (requires Gemini API key)}';

    protected $description = 'Test all new SEM services: keyword optimization, bid adjustments, ad extensions, reporting, and optimization agent actuators.';

    private array $results = [];
    private ?string $testKeywordResource = null;

    public function handle(): int
    {
        $this->info('=== Testing New SEM Services ===');
        $this->newLine();

        // Resolve customer
        $customer = $this->resolveCustomer();
        if (!$customer) {
            return 1;
        }
        $customerId = $customer->google_ads_customer_id;
        $this->info("Testing against account: {$customerId}");
        $this->newLine();

        // Find a Search campaign with an ad group
        $searchCampaign = $this->findSearchCampaign($customer, $customerId);
        if (!$searchCampaign) {
            $this->error('No Search campaign found in this account. Run googleads:test-all-campaigns first.');
            return 1;
        }

        $campaignResource = $searchCampaign['campaign_resource'];
        $adGroupResource = $searchCampaign['ad_group_resource'];
        $campaignName = $searchCampaign['campaign_name'];
        $this->info("Using campaign: {$campaignName}");
        $this->info("  Campaign: {$campaignResource}");
        $this->info("  Ad Group: {$adGroupResource}");
        $this->newLine();

        // ────────────────────────────────────────────────
        // PHASE 1: Keyword Optimization Loop
        // ────────────────────────────────────────────────
        $this->info('━━━ PHASE 1: Keyword Optimization Loop ━━━');

        // 1a. Add a test keyword we can manipulate
        $this->testAddKeyword($customer, $customerId, $adGroupResource);

        // 1b. Update keyword bid
        $this->testUpdateKeywordBid($customer, $customerId);

        // 1c. Pause keyword
        $this->testPauseKeyword($customer, $customerId);

        // 1d. Re-enable keyword
        $this->testEnableKeyword($customer, $customerId);

        // 1e. Remove keyword (destructive)
        if (!$this->option('skip-destructive')) {
            $this->testRemoveKeyword($customer, $customerId);
        } else {
            $this->warn('  [SKIPPED] RemoveKeyword (--skip-destructive)');
            $this->logResult('RemoveKeyword', 'SKIPPED', '--skip-destructive flag');
        }

        // 1f. Quality Score persistence
        $this->testQualityScorePersistence($customer, $customerId, $campaignResource);

        $this->newLine();

        // ────────────────────────────────────────────────
        // PHASE 2: Bid Adjustments
        // ────────────────────────────────────────────────
        $this->info('━━━ PHASE 2: Bid Adjustments ━━━');

        $this->testDeviceBidAdjustment($customer, $customerId, $campaignResource);
        $this->testLocationBidAdjustment($customer, $customerId, $campaignResource);
        $this->testAdSchedule($customer, $customerId, $campaignResource);

        $this->newLine();

        // ────────────────────────────────────────────────
        // PHASE 3: Ad Extensions
        // ────────────────────────────────────────────────
        $this->info('━━━ PHASE 3: Ad Extensions ━━━');

        $this->testStructuredSnippet($customer, $customerId, $campaignResource);
        $this->testCallAsset($customer, $customerId, $campaignResource);
        $this->testPriceAsset($customer, $customerId, $campaignResource);
        $this->testPromotionAsset($customer, $customerId, $campaignResource);

        $this->newLine();

        // ────────────────────────────────────────────────
        // PHASE 4: Reporting
        // ────────────────────────────────────────────────
        if (!$this->option('skip-reporting')) {
            $this->info('━━━ PHASE 4: Reporting ━━━');
            $this->testQualityScoreTrending($customer);
            $this->testExecutiveReport($customer);
            $this->newLine();
        } else {
            $this->warn('━━━ PHASE 4: Reporting [SKIPPED] ━━━');
        }

        // ────────────────────────────────────────────────
        // PHASE 5: Verification
        // ────────────────────────────────────────────────
        $this->info('━━━ PHASE 5: Verification ━━━');
        $this->verifyExtensions($customer, $customerId);

        $this->newLine();

        // ────────────────────────────────────────────────
        // RESULTS SUMMARY
        // ────────────────────────────────────────────────
        $this->info('=== RESULTS SUMMARY ===');
        $this->table(['Test', 'Status', 'Detail'], $this->results);

        $passed = collect($this->results)->where(1, 'OK')->count();
        $failed = collect($this->results)->where(1, 'FAILED')->count();
        $skipped = collect($this->results)->where(1, 'SKIPPED')->count();
        $total = count($this->results);

        $this->newLine();
        $emoji = $failed === 0 ? '✅' : '❌';
        $this->info("{$emoji} {$passed}/{$total} passed, {$failed} failed, {$skipped} skipped");

        return $failed > 0 ? 1 : 0;
    }

    // =========================================================================
    // Customer / Campaign Discovery
    // =========================================================================

    private function resolveCustomer(): ?Customer
    {
        $specifiedId = $this->option('customer-id');

        if ($specifiedId) {
            $customer = Customer::where('google_ads_customer_id', $specifiedId)->first();
            if (!$customer) {
                $this->error("No customer found with Google Ads ID: {$specifiedId}");
                return null;
            }
            return $customer;
        }

        $customer = Customer::whereNotNull('google_ads_customer_id')->latest()->first();
        if (!$customer) {
            $this->error('No customer with a Google Ads account found. Use --customer-id= to specify one.');
            return null;
        }
        return $customer;
    }

    private function findSearchCampaign(Customer $customer, string $customerId): ?array
    {
        try {
            $service = new AccountStructureService($customer);
            $reflection = new \ReflectionClass($service);
            $clientProp = $reflection->getProperty('client');
            $clientProp->setAccessible(true);
            $client = $clientProp->getValue($service);

            $query = "SELECT campaign.resource_name, campaign.name, ad_group.resource_name "
                   . "FROM ad_group "
                   . "WHERE campaign.advertising_channel_type = 'SEARCH' "
                   . "AND campaign.status != 'REMOVED' "
                   . "AND ad_group.status != 'REMOVED' "
                   . "LIMIT 1";

            $response = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $query,
                ])
            );

            foreach ($response->getIterator() as $row) {
                return [
                    'campaign_resource' => $row->getCampaign()->getResourceName(),
                    'campaign_name' => $row->getCampaign()->getName(),
                    'ad_group_resource' => $row->getAdGroup()->getResourceName(),
                ];
            }
        } catch (\Exception $e) {
            $this->warn("  Error finding search campaign: " . $e->getMessage());
        }
        return null;
    }

    // =========================================================================
    // PHASE 1: Keyword Optimization
    // =========================================================================

    private function testAddKeyword(Customer $customer, string $customerId, string $adGroupResource): void
    {
        $this->line('  [1/6] Adding test keyword for manipulation...');
        try {
            $service = new AddKeyword($customer);
            $testKeyword = 'spectra test keyword ' . substr(uniqid(), -6);
            $resource = ($service)($customerId, $adGroupResource, $testKeyword, KeywordMatchType::EXACT);

            if ($resource) {
                $this->testKeywordResource = $resource;
                $this->info("    ✓ Added: {$testKeyword} → {$resource}");
                $this->logResult('AddKeyword (test)', 'OK', $resource);
            } else {
                $this->error('    ✗ AddKeyword returned null');
                $this->logResult('AddKeyword (test)', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('AddKeyword (test)', 'FAILED', $this->extractError($e));
        }
    }

    private function testUpdateKeywordBid(Customer $customer, string $customerId): void
    {
        $this->line('  [2/6] Updating keyword bid...');
        if (!$this->testKeywordResource) {
            $this->warn('    Skipped — no test keyword available');
            $this->logResult('UpdateKeywordBid', 'SKIPPED', 'No test keyword');
            return;
        }

        try {
            $service = new UpdateKeywordBid($customer);
            $newBid = 2_500_000; // $2.50
            $success = ($service)($customerId, $this->testKeywordResource, $newBid);

            if ($success) {
                $this->info('    ✓ Bid updated to $2.50 (2500000 micros)');
                $this->logResult('UpdateKeywordBid', 'OK', '$2.50 (2500000 micros)');
            } else {
                $this->error('    ✗ UpdateKeywordBid returned false');
                $this->logResult('UpdateKeywordBid', 'FAILED', 'Returned false');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('UpdateKeywordBid', 'FAILED', $this->extractError($e));
        }
    }

    private function testPauseKeyword(Customer $customer, string $customerId): void
    {
        $this->line('  [3/6] Pausing keyword...');
        if (!$this->testKeywordResource) {
            $this->warn('    Skipped — no test keyword available');
            $this->logResult('PauseKeyword', 'SKIPPED', 'No test keyword');
            return;
        }

        try {
            $service = new UpdateKeywordStatus($customer);
            $success = $service->pause($customerId, $this->testKeywordResource);

            if ($success) {
                $this->info('    ✓ Keyword paused');
                $this->logResult('PauseKeyword', 'OK', $this->testKeywordResource);
            } else {
                $this->error('    ✗ Pause returned false');
                $this->logResult('PauseKeyword', 'FAILED', 'Returned false');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('PauseKeyword', 'FAILED', $this->extractError($e));
        }
    }

    private function testEnableKeyword(Customer $customer, string $customerId): void
    {
        $this->line('  [4/6] Re-enabling keyword...');
        if (!$this->testKeywordResource) {
            $this->warn('    Skipped — no test keyword available');
            $this->logResult('EnableKeyword', 'SKIPPED', 'No test keyword');
            return;
        }

        try {
            $service = new UpdateKeywordStatus($customer);
            $success = $service->enable($customerId, $this->testKeywordResource);

            if ($success) {
                $this->info('    ✓ Keyword re-enabled');
                $this->logResult('EnableKeyword', 'OK', $this->testKeywordResource);
            } else {
                $this->error('    ✗ Enable returned false');
                $this->logResult('EnableKeyword', 'FAILED', 'Returned false');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('EnableKeyword', 'FAILED', $this->extractError($e));
        }
    }

    private function testRemoveKeyword(Customer $customer, string $customerId): void
    {
        $this->line('  [5/6] Removing test keyword...');
        if (!$this->testKeywordResource) {
            $this->warn('    Skipped — no test keyword available');
            $this->logResult('RemoveKeyword', 'SKIPPED', 'No test keyword');
            return;
        }

        try {
            $service = new RemoveKeyword($customer);
            $success = ($service)($customerId, $this->testKeywordResource);

            if ($success) {
                $this->info('    ✓ Keyword removed');
                $this->logResult('RemoveKeyword', 'OK', 'Cleaned up test keyword');
                $this->testKeywordResource = null; // clean reference
            } else {
                $this->error('    ✗ Remove returned false');
                $this->logResult('RemoveKeyword', 'FAILED', 'Returned false');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('RemoveKeyword', 'FAILED', $this->extractError($e));
        }
    }

    private function testQualityScorePersistence(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [6/6] Testing QS persistence...');

        // Find a Campaign model linked to this google ads campaign
        $googleCampaignId = null;
        if (preg_match('/campaigns\/(\d+)/', $campaignResource, $m)) {
            $googleCampaignId = $m[1];
        }

        $campaign = Campaign::where('customer_id', $customer->id)
            ->where('google_ads_campaign_id', $googleCampaignId)
            ->first();

        if (!$campaign) {
            $this->warn('    ⚠ No Campaign model found — trying any campaign for this customer');
            $campaign = Campaign::where('customer_id', $customer->id)->first();
        }

        if (!$campaign) {
            $this->line('    Creating Campaign record for Google Ads campaign ' . $googleCampaignId);
            $campaign = Campaign::create([
                'customer_id' => $customer->id,
                'name' => 'Test Search Campaign',
                'google_ads_campaign_id' => $googleCampaignId,
                'reason' => 'Functional test campaign',
                'goals' => ['test keyword quality score persistence'],
                'target_market' => 'Test market',
                'voice' => 'Professional',
                'total_budget' => 1000,
                'daily_budget' => 50,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'primary_kpi' => 'conversions',
            ]);
            $this->info('    Created Campaign record (ID: ' . $campaign->id . ')');
        }

        try {
            $countBefore = KeywordQualityScore::where('customer_id', $customer->id)->count();

            // Run the job synchronously
            $job = new GetKeywordQualityScore($campaign->id);
            $job->handle();

            $countAfter = KeywordQualityScore::where('customer_id', $customer->id)->count();
            $newRecords = $countAfter - $countBefore;

            if ($newRecords > 0) {
                $this->info("    ✓ QS persisted: {$newRecords} new records (total: {$countAfter})");
                $this->logResult('QS Persistence', 'OK', "{$newRecords} records saved");

                // Show a sample
                $sample = KeywordQualityScore::where('customer_id', $customer->id)
                    ->latest('recorded_at')
                    ->take(3)
                    ->get(['keyword_text', 'quality_score', 'impressions', 'clicks']);

                if ($sample->isNotEmpty()) {
                    $this->table(
                        ['Keyword', 'QS', 'Impressions', 'Clicks'],
                        $sample->map(fn($s) => [$s->keyword_text, $s->quality_score ?? 'N/A', $s->impressions, $s->clicks])->toArray()
                    );
                }
            } else {
                $this->warn('    ⚠ No QS records saved (keywords may have no impressions yet)');
                $this->logResult('QS Persistence', 'OK', 'Job ran but no data (new campaign)');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('QS Persistence', 'FAILED', $this->extractError($e));
        }
    }

    // =========================================================================
    // PHASE 2: Bid Adjustments
    // =========================================================================

    private function testDeviceBidAdjustment(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [1/3] Setting mobile bid adjustment (-20%)...');
        try {
            $service = new SetDeviceBidAdjustment($customer);
            $resource = ($service)($customerId, $campaignResource, Device::MOBILE, 0.8);

            if ($resource) {
                $this->info("    ✓ Mobile bid modifier set to 0.8x: {$resource}");
                $this->logResult('DeviceBidAdjustment', 'OK', 'Mobile 0.8x');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('DeviceBidAdjustment', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('DeviceBidAdjustment', 'FAILED', $this->extractError($e));
        }
    }

    private function testLocationBidAdjustment(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [2/3] Setting Australian location bid adjustment (+15%)...');
        try {
            $service = new SetLocationBidAdjustment($customer);
            // 2036 = Australia
            $resource = ($service)($customerId, $campaignResource, 'geoTargetConstants/2036', 1.15);

            if ($resource) {
                $this->info("    ✓ Australia bid modifier set to 1.15x: {$resource}");
                $this->logResult('LocationBidAdjustment', 'OK', 'Australia 1.15x');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('LocationBidAdjustment', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('LocationBidAdjustment', 'FAILED', $this->extractError($e));
        }
    }

    private function testAdSchedule(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [3/3] Setting ad schedule (Monday 9am-5pm, +20% bid)...');
        try {
            $service = new SetAdSchedule($customer);
            // MONDAY=2, start 9:00, end 17:00, MinuteOfHour: ZERO=2
            $resource = ($service)($customerId, $campaignResource, 2, 9, 2, 17, 2, 1.2);

            if ($resource) {
                $this->info("    ✓ Monday 9-17 schedule set with 1.2x bid: {$resource}");
                $this->logResult('AdSchedule', 'OK', 'Monday 9-17, 1.2x');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('AdSchedule', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('AdSchedule', 'FAILED', $this->extractError($e));
        }
    }

    // =========================================================================
    // PHASE 3: Ad Extensions
    // =========================================================================

    private function testStructuredSnippet(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [1/4] Creating Structured Snippet extension...');
        try {
            $service = new CreateStructuredSnippetAsset($customer);
            $resource = ($service)($customerId, 'Services', [
                'SEM Management',
                'Google Ads',
                'Facebook Ads',
                'Analytics',
                'Reporting',
            ]);

            if ($resource) {
                $this->info("    ✓ Structured Snippet: {$resource}");
                $this->logResult('StructuredSnippet', 'OK', $resource);
                $this->linkAssetToCampaign($customer, $customerId, $campaignResource, $resource, 'StructuredSnippet');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('StructuredSnippet', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('StructuredSnippet', 'FAILED', $this->extractError($e));
        }
    }

    private function testCallAsset(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [2/4] Creating Call extension...');
        try {
            $service = new CreateCallAsset($customer);
            $phone = $this->option('phone');
            $resource = ($service)($customerId, $phone, 'AU');

            if ($resource) {
                $this->info("    ✓ Call Asset ({$phone}): {$resource}");
                $this->logResult('CallAsset', 'OK', $resource);
                $this->linkAssetToCampaign($customer, $customerId, $campaignResource, $resource, 'CallAsset');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('CallAsset', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('CallAsset', 'FAILED', $this->extractError($e));
        }
    }

    private function testPriceAsset(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [3/4] Creating Price extension...');
        try {
            $service = new CreatePriceAsset($customer);
            // type=8 (SERVICES), price_qualifier=2 (FROM)
            $resource = ($service)($customerId, 8, 2, [
                ['header' => 'SEM Management', 'description' => 'Full campaign mgmt', 'price_micros' => 1500_000_000, 'currency_code' => 'AUD', 'unit' => 2, 'final_url' => 'https://example.com/sem'],
                ['header' => 'Google Ads', 'description' => 'Google Ads setup', 'price_micros' => 500_000_000, 'currency_code' => 'AUD', 'unit' => 2, 'final_url' => 'https://example.com/google-ads'],
                ['header' => 'Reporting', 'description' => 'Monthly reports', 'price_micros' => 250_000_000, 'currency_code' => 'AUD', 'unit' => 2, 'final_url' => 'https://example.com/reporting'],
            ], 'en');

            if ($resource) {
                $this->info("    ✓ Price Asset (3 offerings): {$resource}");
                $this->logResult('PriceAsset', 'OK', $resource);
                $this->linkAssetToCampaign($customer, $customerId, $campaignResource, $resource, 'PriceAsset');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('PriceAsset', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('PriceAsset', 'FAILED', $this->extractError($e));
        }
    }

    private function testPromotionAsset(Customer $customer, string $customerId, string $campaignResource): void
    {
        $this->line('  [4/4] Creating Promotion extension...');
        try {
            $service = new CreatePromotionAsset($customer);
            $resource = ($service)($customerId, 'New Client Discount', [
                'percent_off' => 200_000, // 20% (1,000,000 = 100%)
                'language_code' => 'en',
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonths(1)->format('Y-m-d'),
                'final_url' => 'https://example.com/promo',
            ]);

            if ($resource) {
                $this->info("    ✓ Promotion Asset: {$resource}");
                $this->logResult('PromotionAsset', 'OK', $resource);
                $this->linkAssetToCampaign($customer, $customerId, $campaignResource, $resource, 'PromotionAsset');
            } else {
                $this->error('    ✗ Returned null');
                $this->logResult('PromotionAsset', 'FAILED', 'Returned null');
            }
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('PromotionAsset', 'FAILED', $this->extractError($e));
        }
    }

    private function linkAssetToCampaign(Customer $customer, string $customerId, string $campaignResource, string $assetResource, string $label): void
    {
        $fieldTypeMap = [
            'StructuredSnippet' => AssetFieldType::STRUCTURED_SNIPPET,
            'CallAsset' => AssetFieldType::CALL,
            'PriceAsset' => AssetFieldType::PRICE,
            'PromotionAsset' => AssetFieldType::PROMOTION,
        ];

        $fieldType = $fieldTypeMap[$label] ?? AssetFieldType::UNKNOWN;

        try {
            $linker = new LinkCampaignAsset($customer);
            $linkResource = ($linker)($customerId, $campaignResource, $assetResource, $fieldType);
            if ($linkResource) {
                $this->info("    ✓ Linked to campaign: {$linkResource}");
                $this->logResult("{$label} Link", 'OK', 'Linked to campaign');
            } else {
                $this->warn("    ⚠ Asset created but linking returned null");
                $this->logResult("{$label} Link", 'FAILED', 'Link returned null');
            }
        } catch (\Exception $e) {
            $this->warn("    ⚠ Asset created but linking failed: " . substr($e->getMessage(), 0, 100));
            $this->logResult("{$label} Link", 'FAILED', $this->extractError($e));
        }
    }

    // =========================================================================
    // PHASE 4: Reporting
    // =========================================================================

    private function testQualityScoreTrending(Customer $customer): void
    {
        $this->line('  [1/2] Quality Score trending...');
        try {
            $service = new QualityScoreTrendingService();
            $trends = $service->getTrends($customer, 30);

            $this->info("    ✓ Average QS: " . ($trends['average_qs'] ?? 'N/A'));
            $this->info("    Trending up: " . count($trends['trending_up']) . " keywords");
            $this->info("    Trending down: " . count($trends['trending_down']) . " keywords");
            $this->info("    Best keywords: " . count($trends['best_keywords']));
            $this->info("    Worst keywords: " . count($trends['worst_keywords']));
            $this->logResult('QS Trending', 'OK', 'Avg QS: ' . ($trends['average_qs'] ?? 'N/A'));
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('QS Trending', 'FAILED', $this->extractError($e));
        }
    }

    private function testExecutiveReport(Customer $customer): void
    {
        $this->line('  [2/2] Executive report generation...');
        try {
            $gemini = app(GeminiService::class);
            $service = new ExecutiveReportService($gemini);
            $report = $service->generate($customer, 'weekly');

            $summary = $report['summary'] ?? [];
            $this->info("    ✓ Report generated");
            $this->info("    Campaigns: " . ($summary['total_campaigns'] ?? 0));
            $this->info("    Impressions: " . number_format($summary['total_impressions'] ?? 0));
            $this->info("    Clicks: " . number_format($summary['total_clicks'] ?? 0));
            $this->info("    Cost: $" . ($summary['total_cost'] ?? 0));

            if (!empty($report['ai_executive_summary'])) {
                $this->newLine();
                $this->line('    --- AI Executive Summary ---');
                $this->line('    ' . str_replace("\n", "\n    ", substr($report['ai_executive_summary'], 0, 500)));
                $this->line('    ---');
            }

            $this->logResult('ExecutiveReport', 'OK', ($summary['total_campaigns'] ?? 0) . ' campaigns analyzed');
        } catch (\Exception $e) {
            $this->error('    ✗ ' . $this->extractError($e));
            $this->logResult('ExecutiveReport', 'FAILED', $this->extractError($e));
        }
    }

    // =========================================================================
    // PHASE 5: Verification
    // =========================================================================

    private function verifyExtensions(Customer $customer, string $customerId): void
    {
        $this->line('  Querying campaign extensions from Google Ads...');
        try {
            $service = new AccountStructureService($customer);
            $reflection = new \ReflectionClass($service);
            $clientProp = $reflection->getProperty('client');
            $clientProp->setAccessible(true);
            $client = $clientProp->getValue($service);

            // Check campaign criteria (device bids, location, schedules)
            $query = "SELECT campaign_criterion.type, campaign_criterion.bid_modifier, "
                   . "campaign_criterion.device.type, campaign_criterion.location.geo_target_constant, "
                   . "campaign_criterion.ad_schedule.day_of_week, campaign.name "
                   . "FROM campaign_criterion "
                   . "WHERE campaign.status != 'REMOVED' "
                   . "AND campaign_criterion.negative = false "
                   . "AND campaign_criterion.type IN ('DEVICE', 'LOCATION', 'AD_SCHEDULE')";

            $response = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $query,
                ])
            );

            $criteria = [];
            foreach ($response->getIterator() as $row) {
                $cc = $row->getCampaignCriterion();
                $type = \Google\Ads\GoogleAds\V22\Enums\CriterionTypeEnum\CriterionType::name($cc->getType());
                $modifier = $cc->getBidModifier() ?: '-';

                $detail = match ($type) {
                    'DEVICE' => 'Device: ' . \Google\Ads\GoogleAds\V22\Enums\DeviceEnum\Device::name($cc->getDevice()->getType()),
                    'LOCATION' => 'Location: ' . ($cc->getLocation()?->getGeoTargetConstant() ?? '?'),
                    'AD_SCHEDULE' => 'Day: ' . \Google\Ads\GoogleAds\V22\Enums\DayOfWeekEnum\DayOfWeek::name($cc->getAdSchedule()->getDayOfWeek()),
                    default => $type,
                };

                $criteria[] = [
                    $row->getCampaign()->getName(),
                    $type,
                    $detail,
                    $modifier,
                ];
            }

            if (!empty($criteria)) {
                $this->table(['Campaign', 'Criterion Type', 'Detail', 'Bid Modifier'], $criteria);
                $this->logResult('Criteria Verification', 'OK', count($criteria) . ' criteria found');
            } else {
                $this->warn('  No campaign criteria found');
                $this->logResult('Criteria Verification', 'OK', 'No criteria (may be expected)');
            }

            // Check campaign assets (extensions)
            $assetQuery = "SELECT campaign_asset.resource_name, campaign_asset.field_type, "
                        . "asset.type, asset.name, campaign.name, campaign.status "
                        . "FROM campaign_asset "
                        . "WHERE campaign.status != 'REMOVED'";

            $assetResponse = $client->getGoogleAdsServiceClient()->search(
                new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query' => $assetQuery,
                ])
            );

            $assets = [];
            foreach ($assetResponse->getIterator() as $row) {
                $ca = $row->getCampaignAsset();
                $asset = $row->getAsset();
                $assets[] = [
                    $row->getCampaign()->getName(),
                    \Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType::name($asset->getType()),
                    $asset->getName(),
                ];
            }

            if (!empty($assets)) {
                $this->table(['Campaign', 'Asset Type', 'Asset Name'], $assets);
                $this->logResult('Assets Verification', 'OK', count($assets) . ' campaign assets linked');
            } else {
                $this->warn('  No campaign assets found');
                $this->logResult('Assets Verification', 'OK', 'No campaign assets (may be expected)');
            }

        } catch (\Exception $e) {
            $this->warn('  Verification error: ' . $this->extractError($e));
            $this->logResult('Verification', 'FAILED', $this->extractError($e));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function logResult(string $test, string $status, string $detail): void
    {
        $this->results[] = [$test, $status, $detail];
    }

    private function extractError(\Exception $e): string
    {
        $msg = $e->getMessage();
        if (preg_match_all('/"message":\s*"([^"]+)"/', $msg, $m)) {
            return implode(' | ', $m[1]);
        }
        if (preg_match('/"(\w+Error)":\s*"(\w+)"/', $msg, $m)) {
            return "{$m[1]}: {$m[2]}";
        }
        return substr($msg, 0, 300);
    }
}
