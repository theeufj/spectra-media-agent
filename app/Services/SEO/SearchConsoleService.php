<?php

namespace App\Services\SEO;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * First-party search performance for customer sites, via Google Search Console.
 *
 * Replaces SERP scraping for rank tracking, and is a better instrument rather
 * than merely a cheaper one. Scraping told you where a domain sat in one
 * snapshot of one result page from one location; Search Console reports the
 * average position across every real impression, plus the clicks, impressions
 * and CTR behind it. It is also free and has no credit to run out — the failure
 * that left 4,264 ranking rows with a null position.
 *
 * Authentication follows the management-account pattern: one platform Google
 * account, no per-customer OAuth. Ownership is proven through the GTM container
 * Spectra already provisions and publishes for the customer, which Search
 * Console accepts as a verification method. The customer does nothing.
 *
 * Requires webmasters.readonly and siteverification on the platform token —
 * see GeneratePlatformGoogleToken.
 */
class SearchConsoleService
{
    private const SITES_ENDPOINT = 'https://www.googleapis.com/webmasters/v3/sites';

    private const VERIFICATION_ENDPOINT = 'https://www.googleapis.com/siteVerification/v1/webResource';

    /**
     * Search Console reports on a two-to-three day delay, so "today" and
     * yesterday are always empty. Ending the window here avoids reporting a
     * fresh zero as though the site lost all its traffic.
     */
    private const REPORTING_LAG_DAYS = 3;

    private ?string $token = null;

    /**
     * Search performance for a customer's site.
     *
     * @param  string  $dimension  'query', 'page', 'country' or 'device'
     * @return array{success: bool, rows?: list<array<string, mixed>>, error?: string}
     */
    public function performance(Customer $customer, string $dimension = 'query', int $days = 28, int $limit = 100): array
    {
        $site = $this->siteUrlFor($customer);

        if (! $site) {
            return ['success' => false, 'error' => 'Customer has no website configured'];
        }

        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not authenticate with Search Console'];
        }

        $end = now()->subDays(self::REPORTING_LAG_DAYS);
        $start = $end->copy()->subDays($days);

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->post(self::SITES_ENDPOINT.'/'.urlencode($site).'/searchAnalytics/query', [
                    'startDate' => $start->toDateString(),
                    'endDate' => $end->toDateString(),
                    'dimensions' => [$dimension],
                    'rowLimit' => $limit,
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $this->explain($response->status(), $response->body()),
                ];
            }

            return [
                'success' => true,
                'rows' => array_map(fn ($row) => [
                    'key' => $row['keys'][0] ?? null,
                    'clicks' => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr' => $row['ctr'] ?? 0.0,
                    // Google reports position as a float average; keep it that
                    // way rather than rounding, since a move from 8.4 to 7.6 is
                    // real progress that rounding to 8 would hide.
                    'position' => $row['position'] ?? null,
                ], $response->json('rows') ?? []),
            ];
        } catch (\Throwable $e) {
            Log::warning('SearchConsoleService: performance query failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Is this customer's site available to us in Search Console?
     */
    public function isVerified(Customer $customer): bool
    {
        $verified = $this->verifiedSites();

        foreach ($this->candidateSiteUrls($customer) as $candidate) {
            if (in_array($candidate, $verified, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sites the platform account can read, cached briefly.
     *
     * @return list<string>
     */
    public function verifiedSites(): array
    {
        $token = $this->accessToken();
        if (! $token) {
            return [];
        }

        return Cache::remember('search_console_sites', now()->addMinutes(30), function () use ($token) {
            $response = Http::withToken($token)->timeout(30)->get(self::SITES_ENDPOINT);

            if (! $response->successful()) {
                Log::warning('SearchConsoleService: could not list sites', ['status' => $response->status()]);

                return [];
            }

            return collect($response->json('siteEntry') ?? [])
                // siteUnverifiedUser entries are listed but cannot be queried.
                ->filter(fn ($s) => ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser')
                ->pluck('siteUrl')
                ->values()
                ->all();
        });
    }

    /**
     * Claim ownership of a customer's site using their GTM container.
     *
     * Works because the platform account holds tagmanager.publish on the
     * container it created for them, which is what Search Console checks. The
     * customer is not involved — but the container snippet must actually be on
     * the site, so this fails for customers who never installed it.
     *
     * @return array{success: bool, error?: string}
     */
    public function verifyViaTagManager(Customer $customer): array
    {
        $site = $this->siteUrlFor($customer);

        if (! $site) {
            return ['success' => false, 'error' => 'Customer has no website configured'];
        }

        if (! $customer->gtm_container_id) {
            return ['success' => false, 'error' => 'Customer has no GTM container to verify with'];
        }

        if (! $customer->gtm_installed) {
            return ['success' => false, 'error' => 'GTM container is not installed on the site yet'];
        }

        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not authenticate with Search Console'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->post(self::VERIFICATION_ENDPOINT.'?verificationMethod=TAG_MANAGER', [
                    'site' => ['type' => 'SITE', 'identifier' => $site],
                    'verificationMethod' => 'TAG_MANAGER',
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'error' => $this->explain($response->status(), $response->body())];
            }

            // Verifying grants ownership; the site still has to be added to
            // Search Console before it can be queried.
            Http::withToken($token)->timeout(30)->put(self::SITES_ENDPOINT.'/'.urlencode($site));

            Cache::forget('search_console_sites');

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The Search Console property for this customer, preferring one we are
     * actually verified on.
     *
     * Search Console identifies properties by exact URL prefix, and
     * https://example.com/ and https://www.example.com/ are genuinely different
     * properties with separate data. So the bare host cannot simply be
     * normalised — both forms are tried and whichever is verified wins. Falling
     * back to the non-www form only matters when neither is verified, where the
     * value is used for an error message rather than a query.
     */
    private function siteUrlFor(Customer $customer): ?string
    {
        $candidates = $this->candidateSiteUrls($customer);

        if ($candidates === []) {
            return null;
        }

        $verified = $this->verifiedSites();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $verified, true)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * Both property forms Google might hold for this customer's domain.
     *
     * @return list<string>
     */
    private function candidateSiteUrls(Customer $customer): array
    {
        $website = trim((string) $customer->website);

        if ($website === '') {
            return [];
        }

        if (! str_starts_with($website, 'http')) {
            $website = 'https://'.$website;
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! $host) {
            return [];
        }

        $bare = preg_replace('/^www\./i', '', strtolower($host));

        return array_values(array_unique([
            'https://'.$bare.'/',
            'https://www.'.$bare.'/',
        ]));
    }

    private function accessToken(): ?string
    {
        if ($this->token) {
            return $this->token;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => config('services.gtm.platform_refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::error('SearchConsoleService: token exchange failed', ['status' => $response->status()]);

            return null;
        }

        return $this->token = $response->json('access_token');
    }

    /**
     * Turn Google's status codes into something a human can act on.
     *
     * A 403 here almost always means the platform token lacks the Search
     * Console scopes rather than the site being genuinely forbidden — that is
     * the state this account is in until the token is reissued.
     */
    private function explain(int $status, string $body): string
    {
        return match ($status) {
            403 => 'Forbidden — the platform token is probably missing webmasters.readonly. Run: php artisan google:platform-token --check',
            404 => 'Site is not in Search Console. Verify it first via verifyViaTagManager().',
            429 => 'Search Console rate limit reached; try again later.',
            default => 'HTTP '.$status.': '.mb_substr($body, 0, 200),
        };
    }
}
