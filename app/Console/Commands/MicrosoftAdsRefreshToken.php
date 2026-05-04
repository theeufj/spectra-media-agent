<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Helper to exchange a Microsoft OAuth2 authorization code for a refresh token,
 * or to verify an existing refresh token and list accessible accounts.
 *
 * Usage:
 *   1. Run: php artisan microsoftads:refresh-token --authorize
 *      Copy the URL it prints into your browser, sign in with the Microsoft Ads account,
 *      then copy the `code=` value from the redirect URL.
 *
 *   2. Run: php artisan microsoftads:refresh-token --code=PASTE_CODE_HERE --secret=NEW_CLIENT_SECRET
 *      It will print the new refresh token and account IDs to put in .env
 *
 *   3. Run: php artisan microsoftads:refresh-token --list-accounts
 *      To verify current credentials and see all accounts with their IDs.
 */
class MicrosoftAdsRefreshToken extends Command
{
    protected $signature = 'microsoftads:refresh-token
                            {--authorize : Print the authorization URL to visit in browser}
                            {--code= : Authorization code from the redirect URL}
                            {--secret= : New client secret (if rotating)}
                            {--list-accounts : Verify current token and list account IDs}';

    protected $description = 'Get or refresh Microsoft Ads OAuth tokens and list account IDs';

    private const REDIRECT_URI = 'https://localhost';
    private const SCOPE = 'https://ads.microsoft.com/msads.manage offline_access';

    public function handle(): int
    {
        if ($this->option('authorize')) {
            return $this->printAuthUrl();
        }

        if ($this->option('code')) {
            return $this->exchangeCode();
        }

        if ($this->option('list-accounts')) {
            return $this->listAccounts();
        }

        $this->error('Specify --authorize, --code=XXX, or --list-accounts');
        return 1;
    }

    private function printAuthUrl(): int
    {
        $clientId = config('microsoftads.client_id');
        $params = http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => self::REDIRECT_URI,
            'scope'         => self::SCOPE,
            'response_mode' => 'query',
        ]);

        $url = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?{$params}";

        $this->info("Open this URL in your browser and sign in with the Microsoft Ads account:");
        $this->line('');
        $this->line($url);
        $this->line('');
        $this->warn("After sign-in, you'll be redirected to https://localhost/?code=XXXX (will error in browser — that's fine)");
        $this->warn("Copy the full code= value from the address bar, then run:");
        $this->line("  php artisan microsoftads:refresh-token --code=PASTE_CODE_HERE --secret=YOUR_NEW_CLIENT_SECRET");

        return 0;
    }

    private function exchangeCode(): int
    {
        $code      = $this->option('code');
        $secret    = $this->option('secret') ?: config('microsoftads.client_secret');
        $clientId  = config('microsoftads.client_id');
        $tenantId  = 'common';

        $this->info("Exchanging authorization code for tokens...");

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT_URI,
            'grant_type'    => 'authorization_code',
            'scope'         => self::SCOPE,
        ]);

        if (!$response->successful() || !$response->json('refresh_token')) {
            $this->error("Token exchange failed: HTTP {$response->status()}");
            $this->line($response->body());
            return 1;
        }

        $refreshToken = $response->json('refresh_token');
        $accessToken  = $response->json('access_token');

        $this->info("Success! Update your .env on the server with:");
        $this->line('');
        $this->line("MICROSOFT_ADS_CLIENT_SECRET={$secret}");
        $this->line("MICROSOFT_ADS_REFRESH_TOKEN={$refreshToken}");
        $this->line('');

        // Immediately use the access token to list accounts
        $this->info("Listing accounts with the new token...");
        return $this->listAccountsWithToken($accessToken);
    }

    private function listAccounts(): int
    {
        $config  = config('microsoftads');
        $secret  = $config['client_secret'];
        $tenantId = $config['tenant_id'] ?? 'common';

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'client_id'     => $config['client_id'],
            'client_secret' => $secret,
            'refresh_token' => $config['refresh_token'],
            'grant_type'    => 'refresh_token',
            'scope'         => self::SCOPE,
        ]);

        if (!$response->successful() || !$response->json('access_token')) {
            $this->error("Token refresh failed — credentials may be stale.");
            $this->error("Run: php artisan microsoftads:refresh-token --authorize");
            $this->line($response->body());
            return 1;
        }

        return $this->listAccountsWithToken($response->json('access_token'));
    }

    private function listAccountsWithToken(string $accessToken): int
    {
        $config = config('microsoftads');

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'DeveloperToken' => $config['developer_token'],
            'Content-Type'   => 'application/json',
        ])->post('https://clientcenter.api.bingads.microsoft.com/CustomerManagement/v13/Accounts/Search', [
            'Predicates' => [
                ['Field' => 'AccountLifeCycleStatus', 'Operator' => 'Equals', 'Value' => 'Active'],
            ],
            'PageInfo' => ['Index' => 0, 'Size' => 100],
        ]);

        if (!$response->successful()) {
            $this->warn("Couldn't list accounts: HTTP {$response->status()}");
            $this->line($response->body());
            return 1;
        }

        $accounts = $response->json('Accounts.AdvertiserAccount') ?? $response->json('Accounts') ?? [];
        if (!is_array($accounts) || isset($accounts['Id'])) {
            $accounts = $accounts ? [$accounts] : [];
        }

        if (empty($accounts)) {
            $this->warn("No active accounts found. You may need to create one at ads.microsoft.com");
            return 0;
        }

        $this->info("Found " . count($accounts) . " account(s):");
        $this->line('');
        $this->line(str_pad('Account ID', 15) . str_pad('Customer ID', 15) . str_pad('Name', 40) . 'Status');
        $this->line(str_repeat('-', 85));

        foreach ($accounts as $acct) {
            $id         = $acct['Id'] ?? 'N/A';
            $customerId = $acct['ParentCustomerId'] ?? 'N/A';
            $name       = $acct['Name'] ?? 'N/A';
            $status     = $acct['AccountLifeCycleStatus'] ?? 'N/A';
            $this->line(str_pad($id, 15) . str_pad($customerId, 15) . str_pad($name, 40) . $status);
        }

        $this->line('');
        $this->info("To link an account to a customer, run:");
        $this->line("  php artisan microsoftads:link-account --customer-id=8 --account-id=ACCOUNT_ID --ms-customer-id=CUSTOMER_ID");

        return 0;
    }
}
