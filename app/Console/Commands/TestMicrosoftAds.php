<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\MicrosoftAds\BaseMicrosoftAdsService;
use App\Services\MicrosoftAds\CampaignService;
use App\Services\MicrosoftAds\AdGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestMicrosoftAds extends Command
{
    protected $signature = 'microsoftads:test {--create-campaign : Actually create a test campaign} {--cleanup : Delete test campaigns after creation}';
    protected $description = 'Test Microsoft Advertising API connectivity and optionally create a test campaign';

    public function handle(): int
    {
        $this->info('=== Microsoft Advertising API Test ===');
        $this->newLine();

        // Step 1: Verify config
        $this->info('Step 1: Checking configuration...');
        $config = config('microsoftads');
        $checks = [
            'client_id' => !empty($config['client_id']),
            'client_secret' => !empty($config['client_secret']),
            'developer_token' => !empty($config['developer_token']),
            'refresh_token' => !empty($config['refresh_token']),
            'manager_account_id' => !empty($config['manager_account_id']),
        ];

        foreach ($checks as $key => $ok) {
            $this->line(($ok ? '  ✓' : '  ✗') . " {$key}: " . ($ok ? 'Set' : 'MISSING'));
        }

        if (in_array(false, $checks, true)) {
            $this->error('Configuration incomplete. Set missing values in .env');
            return 1;
        }
        $this->info('  All config values present.');
        $this->newLine();

        // Step 2: Test OAuth token exchange
        $this->info('Step 2: Testing OAuth2 token exchange...');
        // Try multiple tenant endpoints to find the one that works
        $tenantId = $config['tenant_id'] ?? 'common';
        $endpoints = [
            'consumers' => 'consumers',
            'organizations' => 'organizations',
            'common' => 'common',
            'tenant-specific' => $tenantId,
        ];

        $accessToken = null;
        $workingEndpoint = null;

        foreach ($endpoints as $label => $tenant) {
            $this->line("  Trying '{$label}' endpoint...");
            $tokenResponse = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
                'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
            ]);

            if ($tokenResponse->successful() && $tokenResponse->json('access_token')) {
                $accessToken = $tokenResponse->json('access_token');
                $workingEndpoint = $label;

                // Quick API test with this token
                $testResp = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken}",
                    'DeveloperToken' => $config['developer_token'],
                    'Content-Type' => 'application/json',
                ])->post('https://clientcenter.api.bingads.microsoft.com/CustomerManagement/v13/User/Query', [
                    'UserId' => null,
                ]);

                if ($testResp->successful()) {
                    $this->info("  ✓ '{$label}' works! Token: " . strlen($accessToken) . " chars");
                    break;
                } else {
                    $body = $testResp->json();
                    $errCode = $body['Errors'][0]['Code'] ?? 'unknown';
                    $this->warn("  ⚠ '{$label}' token OK (" . strlen($accessToken) . " chars) but API returned error {$errCode}");
                    $accessToken = null; // Reset, try next
                }
            } else {
                $this->line("    Token failed: " . substr($tokenResponse->body(), 0, 100));
            }
        }

        if (!$accessToken) {
            $this->error('  ✗ No working endpoint found. You may need to link your work/school account at ads.microsoft.com');
            return 1;
        }
        $this->info("  ✓ Access token obtained via '{$workingEndpoint}' (" . strlen($accessToken) . ' chars)');

        if (!$tokenResponse->successful()) {
            $this->error('  ✗ Token exchange failed: HTTP ' . $tokenResponse->status());
            $this->error('  ' . $tokenResponse->body());
            return 1;
        }

        $accessToken = $tokenResponse->json('access_token');
        if (!$accessToken) {
            $this->error('  ✗ No access token in response');
            return 1;
        }
        $this->info('  ✓ Access token obtained (' . strlen($accessToken) . ' chars)');
        $this->newLine();

        // Step 3: Test Customer Management API - get user info
        $this->info('Step 3: Testing Customer Management API (GetUser)...');
        $userResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'DeveloperToken' => $config['developer_token'],
            'Content-Type' => 'application/json',
        ])->post('https://clientcenter.api.bingads.microsoft.com/CustomerManagement/v13/User/Query', [
            'UserId' => null,
        ]);

        if ($userResponse->successful()) {
            $userData = $userResponse->json();
            $userName = $userData['User']['UserName'] ?? $userData['User']['Name'] ?? 'Unknown';
            $this->info("  ✓ API authenticated as: {$userName}");
        } else {
            $this->warn('  ⚠ GetUser returned HTTP ' . $userResponse->status() . ' (may still work for ads API)');
            $this->line('    ' . substr($userResponse->body(), 0, 200));
        }
        $this->newLine();

        // Step 4: Get accounts under manager
        $this->info('Step 4: Listing accounts under manager (ID: ' . $config['manager_account_id'] . ')...');
        // First try to get the current user's ID for account search
        $userId = $userResponse->successful() ? ($userResponse->json('User')['Id'] ?? null) : null;

        $predicates = [];
        if ($userId) {
            $predicates[] = [
                'Field' => 'UserId',
                'Operator' => 'Equals',
                'Value' => (string) $userId,
            ];
        } else {
            // Fallback: search by account number from manager account
            $predicates[] = [
                'Field' => 'AccountLifeCycleStatus',
                'Operator' => 'Equals',
                'Value' => 'Active',
            ];
            $predicates[] = [
                'Field' => 'UserId',
                'Operator' => 'Equals',
                'Value' => '0',
            ];
        }

        $accountsResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'DeveloperToken' => $config['developer_token'],
            'Content-Type' => 'application/json',
        ])->post('https://clientcenter.api.bingads.microsoft.com/CustomerManagement/v13/Accounts/Search', [
            'Predicates' => $predicates,
            'Ordering' => null,
            'PageInfo' => ['Index' => 0, 'Size' => 100],
        ]);

        $accounts = [];
        if ($accountsResponse->successful()) {
            $accountsData = $accountsResponse->json();
            $accounts = $accountsData['Accounts']['AdvertiserAccount'] ?? $accountsData['Accounts'] ?? [];
            if (!is_array($accounts)) $accounts = [$accounts];

            $this->info("  ✓ Found " . count($accounts) . " account(s):");
            foreach ($accounts as $acct) {
                $acctId = $acct['Id'] ?? 'N/A';
                $acctName = $acct['Name'] ?? 'N/A';
                $acctNumber = $acct['Number'] ?? 'N/A';
                $acctStatus = $acct['AccountLifeCycleStatus'] ?? 'N/A';
                $customerId = $acct['ParentCustomerId'] ?? 'N/A';
                $this->line("    - [{$acctId}] {$acctName} ({$acctNumber}) Status: {$acctStatus} CustomerId: {$customerId}");
            }
        } else {
            $this->warn('  ⚠ SearchAccounts returned HTTP ' . $accountsResponse->status());
            $this->line('    ' . substr($accountsResponse->body(), 0, 300));
        }
        $this->newLine();

        // Step 5: Test Campaign Management API
        if (!empty($accounts)) {
            $testAccount = $accounts[0];
            $accountId = $testAccount['Id'] ?? null;
            $customerId = $testAccount['ParentCustomerId'] ?? null;

            if ($accountId && $customerId) {
                $this->info("Step 5: Testing Campaign Management API on account {$accountId}...");

                $campaignsResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken}",
                    'DeveloperToken' => $config['developer_token'],
                    'CustomerId' => (string) $customerId,
                    'CustomerAccountId' => (string) $accountId,
                    'Content-Type' => 'application/json',
                ])->post('https://campaign.api.bingads.microsoft.com/CampaignManagement/v13/Campaigns/QueryByAccountId', [
                    'AccountId' => $accountId,
                    'CampaignType' => 'Search',
                ]);

                if ($campaignsResponse->successful()) {
                    $campaigns = $campaignsResponse->json()['Campaigns']['Campaign'] ?? [];
                    if (!is_array($campaigns) || isset($campaigns['Id'])) $campaigns = $campaigns ? [$campaigns] : [];
                    $this->info("  ✓ Campaign Management API working! Found " . count($campaigns) . " existing campaign(s)");
                    foreach ($campaigns as $c) {
                        $this->line("    - [{$c['Id']}] {$c['Name']} Budget: \${$c['DailyBudget']}/day Status: {$c['Status']}");
                    }
                } else {
                    $this->warn('  ⚠ GetCampaigns returned HTTP ' . $campaignsResponse->status());
                    $this->line('    ' . substr($campaignsResponse->body(), 0, 300));
                }
                $this->newLine();

                // Step 6: Create test campaign (optional)
                if ($this->option('create-campaign')) {
                    $this->info('Step 6: Creating test campaign via CampaignService...');

                    // Create a temporary customer record for the service
                    $tempCustomer = Customer::firstOrCreate(
                        ['microsoft_ads_account_id' => (string) $accountId],
                        [
                            'name' => 'MS Ads Test',
                            'microsoft_ads_customer_id' => (string) $customerId,
                            'microsoft_ads_account_id' => (string) $accountId,
                        ]
                    );

                    try {
                        $campaignService = new CampaignService($tempCustomer);
                        $result = $campaignService->createSearchCampaign([
                            'name' => 'Spectra Test Campaign ' . date('Y-m-d H:i'),
                            'daily_budget' => 1.00,
                            'status' => 'Paused',
                        ]);

                        if ($result && isset($result['CampaignIds'])) {
                            $campaignId = is_array($result['CampaignIds']) ? ($result['CampaignIds']['long'] ?? $result['CampaignIds'][0] ?? 'unknown') : $result['CampaignIds'];
                            $this->info("  ✓ Campaign created! ID: {$campaignId}");

                            // Try creating an ad group
                            $this->info('  Creating test ad group...');
                            $adGroupService = new AdGroupService($tempCustomer);
                            $agResult = $adGroupService->createAdGroup((string) $campaignId, [
                                'name' => 'Test Ad Group',
                                'cpc_bid' => 1.00,
                                'status' => 'Paused',
                            ]);

                            if ($agResult && isset($agResult['AdGroupIds'])) {
                                $adGroupId = is_array($agResult['AdGroupIds']) ? ($agResult['AdGroupIds']['long'] ?? $agResult['AdGroupIds'][0] ?? 'unknown') : $agResult['AdGroupIds'];
                                $this->info("  ✓ Ad group created! ID: {$adGroupId}");

                                // Add keywords
                                $this->info('  Adding test keywords...');
                                $kwResult = $adGroupService->addKeywords((string) $adGroupId, [
                                    ['text' => 'spectra media test', 'match_type' => 'Exact', 'bid' => 0.50],
                                    ['text' => 'ai advertising platform', 'match_type' => 'Phrase', 'bid' => 0.75],
                                ]);

                                if ($kwResult) {
                                    $this->info('  ✓ Keywords added!');
                                } else {
                                    $this->warn('  ⚠ Keywords may not have been added');
                                }

                                // Add an ad
                                $this->info('  Creating test RSA ad...');
                                $adResult = $adGroupService->addExpandedTextAds((string) $adGroupId, [[
                                    'headlines' => ['AI Advertising Platform', 'Automate Your Ads', 'Spectra Media Test'],
                                    'descriptions' => ['Test ad from Spectra Media Agent.', 'Powered by AI automation.'],
                                    'path1' => 'test',
                                    'path2' => 'ads',
                                    'final_url' => 'https://example.com',
                                ]]);

                                if ($adResult) {
                                    $this->info('  ✓ RSA ad created!');
                                } else {
                                    $this->warn('  ⚠ Ad may not have been created');
                                }
                            } else {
                                $this->warn('  ⚠ Ad group creation may have failed');
                            }

                            // Cleanup
                            if ($this->option('cleanup')) {
                                $this->info('  Cleaning up - pausing test campaign...');
                                $campaignService->updateStatus((string) $campaignId, 'Deleted');
                                $this->info('  ✓ Test campaign deleted');
                            }
                        } else {
                            $this->error('  ✗ Campaign creation failed');
                            $this->line('  Response: ' . json_encode($result));
                        }
                    } catch (\Exception $e) {
                        $this->error('  ✗ Exception: ' . $e->getMessage());
                    }
                }
            }
        } else {
            $this->warn('Step 5: Skipped - no accounts found to test against');
        }

        $this->newLine();
        $this->info('=== Test Complete ===');
        return 0;
    }
}
