<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Mints the platform Google refresh token covering every Google capability
 * Spectra uses on behalf of customers.
 *
 * One credential, many customers — the management-account pattern the whole
 * codebase follows. Customers never complete an OAuth flow.
 *
 * The existing token carries adwords and tagmanager scopes but not Search
 * Console, so listing sites returns 403. Because that token already holds
 * tagmanager.publish, the same Google account can verify a customer's site in
 * Search Console via the GTM container we provisioned for them — no per-customer
 * consent, no DNS record, no file upload. Adding two scopes is the only thing
 * standing between here and first-party SEO data for every customer.
 *
 * Re-running this replaces the token; existing scopes are re-requested, so
 * nothing already working is lost.
 *
 * Usage:
 *   php artisan google:platform-token --authorize
 *   php artisan google:platform-token --code=PASTE_CODE_HERE
 *   php artisan google:platform-token --check
 */
class GeneratePlatformGoogleToken extends Command
{
    protected $signature = 'google:platform-token
                            {--authorize : Print the authorization URL to visit in a browser}
                            {--code= : Authorization code from the redirect URL}
                            {--check : Show the scopes the current token actually holds}';

    protected $description = 'Mint or inspect the platform Google refresh token (Ads, Tag Manager, Search Console).';

    private const REDIRECT_URI = 'https://localhost';

    /**
     * Every scope the platform account needs.
     *
     * webmasters.readonly — read Search Analytics for verified customer sites.
     * siteverification    — prove ownership via the customer's GTM container.
     *
     * Requested together with the existing scopes because Google issues a
     * refresh token for the consented set as a whole: asking for only the new
     * ones would return a token that can no longer touch Ads or Tag Manager.
     */
    private const SCOPES = [
        'https://www.googleapis.com/auth/adwords',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/siteverification',
        'https://www.googleapis.com/auth/datamanager',
    ];

    public function handle(): int
    {
        return match (true) {
            (bool) $this->option('authorize') => $this->printAuthUrl(),
            (bool) $this->option('code') => $this->exchangeCode(),
            (bool) $this->option('check') => $this->checkScopes(),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        $this->error('Specify --authorize, --code=XXX, or --check');

        return self::FAILURE;
    }

    private function printAuthUrl(): int
    {
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            $this->error('GOOGLE_OAUTH_CLIENT_ID is not configured.');

            return self::FAILURE;
        }

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => implode(' ', self::SCOPES),
            // Required to get a refresh token back at all, and 'consent' forces
            // Google to reissue one even though this account has authorised
            // before — without it you get an access token and no refresh token.
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        $this->info('Sign in as the PLATFORM Google account — the one that owns the GTM containers.');
        $this->warn('Not a customer account, and not a personal account.');
        $this->newLine();
        $this->line('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
        $this->newLine();
        $this->warn('The browser will fail to load https://localhost/?code=... — that is expected.');
        $this->warn('Copy the code value out of the address bar, then run:');
        $this->line('  php artisan google:platform-token --code=PASTE_CODE_HERE');

        return self::SUCCESS;
    }

    private function exchangeCode(): int
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $this->option('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        if (! $response->successful()) {
            $this->error('Token exchange failed: '.$response->status());
            $this->line(substr($response->body(), 0, 400));

            return self::FAILURE;
        }

        $refresh = $response->json('refresh_token');

        if (! $refresh) {
            $this->error('Google returned no refresh token.');
            $this->warn('That happens when the code was already used, or consent was skipped.');
            $this->warn('Run --authorize again and use a fresh code.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Refresh token issued. Set BOTH of these in the environment:');
        $this->newLine();
        $this->line('GTM_PLATFORM_REFRESH_TOKEN='.$refresh);
        $this->line('GOOGLE_ADS_MCC_REFRESH_TOKEN='.$refresh);
        $this->newLine();
        $this->warn('The token is not written to disk here on purpose — put it in Forge, then redeploy.');
        $this->warn('Verify afterwards with: php artisan google:platform-token --check');

        return self::SUCCESS;
    }

    private function checkScopes(): int
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => config('services.gtm.platform_refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $this->error('Could not exchange the stored refresh token: '.$response->status());

            return self::FAILURE;
        }

        $info = Http::get('https://www.googleapis.com/oauth2/v1/tokeninfo', [
            'access_token' => $response->json('access_token'),
        ]);

        $held = explode(' ', $info->json('scope') ?? '');
        $missing = [];

        foreach (self::SCOPES as $scope) {
            $has = in_array($scope, $held, true);
            $this->line(sprintf('  %-8s %s', $has ? '<info>have</info>' : '<error>MISSING</error>', $scope));

            if (! $has) {
                $missing[] = $scope;
            }
        }

        $this->newLine();

        if ($missing !== []) {
            $this->warn(count($missing).' scope(s) missing — re-run with --authorize to reissue the token.');

            return self::FAILURE;
        }

        $this->info('All platform scopes present.');

        return self::SUCCESS;
    }
}
