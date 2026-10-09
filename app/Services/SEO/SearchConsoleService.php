<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\GTM\GTMDetectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * Reports use webmasters.readonly on the platform token. Customer ownership
 * verification additionally needs siteverification and webmasters (write)
 * permissions. Existing properties can instead be bound by an administrator
 * after explicit ownership checks; that does not require broader OAuth scopes.
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

    private ?string $sitesError = null;

    /**
     * Search performance for a customer's site.
     *
     * @param  string  $dimension  'query', 'page', 'country' or 'device'
     * @return array{success: bool, rows?: list<array<string, mixed>>, error?: string, source?: string, property?: string, verified_host?: string, aggregation?: string, reporting_start?: string, reporting_end?: string}
     */
    public function performance(Customer $customer, string $dimension = 'query', int $days = 28, int $limit = 100): array
    {
        $binding = $this->binding($customer);
        if (! $binding) {
            return ['success' => false, 'error' => $customer->website ? 'This customer has no verified Search Console binding for the current website. Verify this customer’s assigned tracking container on their website first.' : 'Customer has no website configured'];
        }
        $site = $binding['property'];

        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not authenticate with Search Console'];
        }

        $end = now()->subDays(self::REPORTING_LAG_DAYS);
        $days = max(1, min($days, 365));
        $start = $end->copy()->subDays($days - 1);
        $dimensions = $dimension === 'query_page' ? ['query', 'page'] : [$dimension];
        if (array_diff($dimensions, ['query', 'page', 'country', 'device', 'date'])) {
            return ['success' => false, 'error' => 'Unsupported Search Console dimension'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->post(self::SITES_ENDPOINT.'/'.urlencode($site).'/searchAnalytics/query', [
                    'startDate' => $start->toDateString(),
                    'endDate' => $end->toDateString(),
                    'dimensions' => $dimensions,
                    'rowLimit' => max(1, min($limit, 25000)),
                    'type' => 'web',
                    'dataState' => 'final',
                    // A domain property also covers sandbox and unrelated
                    // subdomains. Customer reports are restricted to the exact
                    // verified host, with page-level aggregation made explicit.
                    'aggregationType' => 'auto',
                    'dimensionFilterGroups' => [['groupType' => 'and', 'filters' => [[
                        'dimension' => 'page', 'operator' => 'includingRegex',
                        'expression' => '^https?://'.preg_quote($binding['host']).'(?::(?:80|443))?/',
                    ]]]],
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $this->explain($response->status(), $response->body()),
                ];
            }

            return [
                'success' => true,
                'source' => 'google_search_console',
                'property' => $site,
                'verified_host' => $binding['host'],
                'aggregation' => $response->json('responseAggregationType') ?? 'byPage',
                'reporting_start' => $start->toDateString(),
                'reporting_end' => $end->toDateString(),
                'rows' => array_map(fn ($row) => [
                    'key' => $row['keys'][0] ?? null,
                    'page' => $dimension === 'query_page' ? ($row['keys'][1] ?? null) : null,
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
            report($e);
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
        $binding = $this->binding($customer);

        return $binding !== null && in_array($binding['property'], $this->verifiedSites(), true);
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

        $cached = Cache::get('search_console_sites');
        if (is_array($cached)) {
            return $cached;
        }

        $response = Http::withToken($token)->timeout(30)->get(self::SITES_ENDPOINT);
        if (! $response->successful()) {
            $this->sitesError = $this->explain($response->status(), $response->body());
            Log::warning('SearchConsoleService: could not list sites', ['status' => $response->status()]);

            return [];
        }

        $sites = collect($response->json('siteEntry') ?? [])
            ->filter(fn ($s) => ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser')
            ->pluck('siteUrl')->values()->all();
        Cache::put('search_console_sites', $sites, now()->addMinutes(30));

        return $sites;
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
        $customer = $customer->fresh();
        if (! $customer) {
            return ['success' => false, 'error' => 'Customer no longer exists.'];
        }
        $host = $this->websiteHost((string) $customer->website);
        $site = $host ? 'https://'.$host.'/' : null;
        if (! $site) {
            return ['success' => false, 'error' => 'Customer has no website configured'];
        }
        $assigned = $this->assignedContainer($customer);
        if (! $assigned) {
            return ['success' => false, 'error' => 'This customer has no exclusive platform-assigned GTM container. A detected container on another website cannot prove customer ownership.'];
        }

        try {
            // Do not trust the saved gtm_installed flag or a scrape-detected ID.
            // Re-read the target website through the public, DNS-pinned fetcher.
            $page = app(PublicWebsiteFetcher::class)->get($site);
            if (! $page->successful() || ! in_array($assigned, (new GTMDetectionService)->detectAllContainers($page->body()), true)) {
                return ['success' => false, 'error' => 'This customer’s assigned GTM container is not installed on the target website.'];
            }
            $token = $this->accessToken();
            if (! $token) {
                return ['success' => false, 'error' => 'Could not authenticate with Search Console'];
            }
            $response = Http::withToken($token)->timeout(60)
                ->post(self::VERIFICATION_ENDPOINT.'?verificationMethod=TAG_MANAGER', [
                    'site' => ['type' => 'SITE', 'identifier' => $site],
                    'verificationMethod' => 'TAG_MANAGER',
                ]);
            if (! $response->successful()) {
                return ['success' => false, 'error' => $this->explain($response->status(), $response->body(), true)];
            }
            $added = Http::withToken($token)->timeout(30)->put(self::SITES_ENDPOINT.'/'.urlencode($site));
            if (! $added->successful()) {
                return ['success' => false, 'error' => 'Site ownership was verified, but adding the Search Console property failed. '.$this->explain($added->status(), $added->body(), true)];
            }
            // A profile change while Google is responding must not bind the old
            // host or a newly swapped container to the customer’s current site.
            $bound = DB::transaction(function () use ($customer, $host, $site, $assigned) {
                $current = Customer::whereKey($customer->id)->lockForUpdate()->first();
                if (! $current || $this->websiteHost((string) $current->website) !== $host || $this->assignedContainer($current) !== $assigned) {
                    return false;
                }
                $current->forceFill([
                    'search_console_property' => $site,
                    'search_console_verified_host' => $host,
                    'search_console_verified_at' => now(),
                ])->save();

                return true;
            });
            Cache::forget('search_console_sites');

            return $bound ? ['success' => true] : ['success' => false, 'error' => 'The website or assigned container changed during verification. Repeat verification for the current website.'];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => 'Website ownership verification could not finish. Retry after checking the target website and platform API access.'];
        }
    }

    private function assignedContainer(Customer $customer): ?string
    {
        $config = $customer->gtm_config ?? [];
        $id = $customer->gtm_container_id;
        $account = (string) config('services.gtm.platform_account_id');
        if (! $id || ! preg_match('/^GTM-[A-Z0-9]+$/', $id)
            || $account === '' || (string) $customer->gtm_account_id !== $account
            || ($config['container_id'] ?? null) !== $id || empty($config['provisioned_at'])
            || (string) ($config['account_id'] ?? '') !== $account
            || ! preg_match('#^accounts/'.preg_quote($account, '#').'/containers/[0-9]+$#', (string) ($config['container_path'] ?? ''))) {
            return null;
        }
        // A copied or automatically adopted container cannot grant a second
        // customer access. Include deleted records: deletion is not a transfer.
        if (Customer::withTrashed()->whereKeyNot($customer->id)->where(function ($query) use ($id) {
            $query->where('gtm_container_id', $id)->orWhere('gtm_config->container_id', $id);
        })->exists()) {
            return null;
        }

        return $id;
    }

    /** Read the indexed version, not a live crawl; never submit indexing requests. */
    public function inspectUrl(Customer $customer, string $url): array
    {
        try {
            $binding = $this->binding($customer);
            if (! $binding) {
                return ['success' => false, 'error' => 'This customer has no verified Search Console binding for the current website.'];
            }
            $site = $binding['property'];
            if ($this->websiteHost($url) !== $binding['host'] || ! $this->containsUrl($site, $url)) {
                return ['success' => false, 'error' => 'This URL is outside the website’s Search Console property.'];
            }
            if (! $this->isVerified($customer)) {
                return ['success' => false, 'error' => $this->sitesError ?? 'The platform Google account does not have access to this Search Console property. Grant it property access in Search Console.'];
            }
            $token = $this->accessToken();
            if (! $token) {
                return ['success' => false, 'error' => 'Could not authenticate with Search Console.'];
            }
            $response = Http::withToken($token)->timeout(20)
                ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                    'inspectionUrl' => $url, 'siteUrl' => $site, 'languageCode' => 'en-US',
                ]);
            if (! $response->successful()) {
                return ['success' => false, 'error' => $this->explain($response->status(), $response->body())];
            }
            $index = $response->json('inspectionResult.indexStatusResult');
            if (! is_array($index) || ! isset($index['verdict'])) {
                return ['success' => false, 'error' => 'Search Console returned no index status for this URL.'];
            }

            return [
                'success' => true, 'url' => $url, 'property' => $site,
                'checked_at' => now()->toIso8601String(),
                'inspection_link' => $response->json('inspectionResult.inspectionResultLink'),
                'verdict' => $index['verdict'],
                'coverage_state' => $index['coverageState'] ?? null,
                'robots_state' => $index['robotsTxtState'] ?? null,
                'indexing_state' => $index['indexingState'] ?? null,
                'page_fetch_state' => $index['pageFetchState'] ?? null,
                'last_crawl_time' => $index['lastCrawlTime'] ?? null,
                'google_canonical' => $index['googleCanonical'] ?? null,
                'user_canonical' => $index['userCanonical'] ?? null,
                'sitemaps' => $index['sitemap'] ?? [],
            ];
        } catch (\Throwable $e) {
            report($e);
            Log::warning('SearchConsoleService: URL inspection failed', ['customer_id' => $customer->id, 'url' => $url, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Search Console inspection could not finish. Retry later.'];
        }
    }

    private function containsUrl(string $site, string $url): bool
    {
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return false;
        }
        if (str_starts_with($site, 'sc-domain:')) {
            $domain = substr($site, 10);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            return $host === $domain || str_ends_with($host, '.'.$domain);
        }

        return str_starts_with($url, $site);
    }

    /** Local setup state only; never reveal the platform’s global property list. */
    public function connectionStatus(Customer $customer): array
    {
        return [
            'bound' => $this->binding($customer) !== null,
            'can_verify' => $this->assignedContainer($customer) !== null,
            'host' => $this->websiteHost((string) $customer->website),
            'customer_uuid' => $customer->getRouteKey(),
        ];
    }

    /** No customer receives access just because the platform can read a domain. */
    private function binding(Customer $customer): ?array
    {
        $stored = $customer->fresh();
        if (! $stored || ! $stored->search_console_verified_at || ! $stored->search_console_property || ! $stored->search_console_verified_host) {
            return null;
        }
        $host = $this->websiteHost((string) $stored->website);
        if (! $host || $host !== $stored->search_console_verified_host
            || ! $this->containsUrl($stored->search_console_property, 'https://'.$host.'/')) {
            return null;
        }

        return ['property' => $stored->search_console_property, 'host' => $host];
    }

    private function websiteHost(string $website): ?string
    {
        if ($website === '') {
            return null;
        }
        $url = str_contains($website, '://') ? $website : 'https://'.$website;
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' ? $host : null;
    }

    private function accessToken(): ?string
    {
        if ($this->token) {
            return $this->token;
        }

        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
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

    /** Distinguish missing token scopes from property permissions and quota. */
    private function explain(int $status, string $body, bool $propertySetup = false): string
    {
        $data = json_decode($body, true);
        $message = (string) data_get($data, 'error.message', '');
        $reasons = json_encode(data_get($data, 'error.details', [])).json_encode(data_get($data, 'error.errors', []));
        if (str_contains((string) $reasons, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')
            || str_contains((string) $reasons, 'insufficientPermissions')
            || str_contains(strtolower($message), 'insufficient authentication scopes')) {
            Log::warning('Search Console token lacks required operation scope', [
                'operation' => $propertySetup ? 'website_ownership_setup' : 'search_console_reports',
                'diagnostic_command' => 'php artisan google:platform-token --check',
            ]);

            return 'The platform Google connection needs permission for '.($propertySetup ? 'website ownership setup' : 'Search Console reports').'. Contact support to finish setup.';
        }
        if (str_contains((string) $reasons, 'SERVICE_DISABLED') || str_contains((string) $reasons, 'accessNotConfigured')) {
            return 'Enable the Search Console API in the platform Google Cloud project.';
        }
        if ($status === 429 || str_contains((string) $reasons, 'quotaExceeded') || str_contains((string) $reasons, 'rateLimitExceeded')) {
            return 'Search Console rate limit reached; try again later.';
        }

        return match ($status) {
            401 => 'The platform Google authentication expired or was revoked. Check the platform token.',
            403 => 'The platform Google account cannot read this property. Check Search Console property permissions and API access. '.mb_substr($message, 0, 200),
            404 => 'This property or URL was not found in Search Console. Check the exact property URL.',
            default => 'Search Console returned HTTP '.$status.($message !== '' ? ': '.mb_substr($message, 0, 200) : '.'),
        };
    }
}
