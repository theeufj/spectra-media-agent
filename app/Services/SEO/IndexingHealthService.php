<?php

namespace App\Services\SEO;

use App\Models\Customer;
use App\Models\SeoAudit;
use App\Services\Crawling\PublicWebsiteFetcher;

/** Bounded, read-only checks of Google's indexed version of sitemap pages. */
class IndexingHealthService
{
    public function __construct(private SearchConsoleService $searchConsole, private PublicWebsiteFetcher $fetcher) {}

    public function inspect(Customer $customer, array $urls): array
    {
        $pages = [];
        $issues = [];
        foreach (array_slice(array_values(array_unique($urls)), 0, 50) as $url) {
            $result = $this->searchConsole->inspectUrl($customer, $url);
            $result['url'] = $url;
            $pages[] = $result;
            if (! $result['success']) {
                $issues[] = $this->issue('warning', 'inspection_unavailable', $url, $result['error'], 'Check platform API access and property permissions, then rerun the audit.');
                // Permission or quota errors affect the entire property. Do not
                // spend 49 more requests repeating the same failure.
                break;
            }
            $canonical = $result['google_canonical'] ?? null;
            $declared = $result['user_canonical'] ?? $url;
            if ($canonical && $this->host($canonical) !== $this->host($url)) {
                $issues[] = $this->issue('critical', 'canonical_other_domain', $url,
                    "Google assigns this page to another domain: {$canonical}",
                    'Consolidate duplicate content, align canonical tags and sitemap URLs, and give each domain distinct content. Recheck after Google recrawls.');
            } elseif ($canonical && $this->normalize($canonical) !== $this->normalize($declared)) {
                $issues[] = $this->issue('warning', 'canonical_mismatch', $url,
                    "Google selected {$canonical} instead of the declared canonical {$declared}.",
                    'Align internal links, redirects and sitemap entries with the intended canonical; review duplicate content.');
            }
            if (($result['verdict'] ?? '') !== 'PASS') {
                $commercial = $this->isCommercial($url);
                $issues[] = $this->issue($commercial ? 'critical' : 'warning', 'page_not_indexed', $url,
                    ($commercial ? 'Commercial page' : 'Page').' is not indexed: '.($result['coverage_state'] ?? $result['verdict']),
                    'Check crawl access, noindex directives and canonical tags. Improve original content and internal links where Google excludes crawled pages.');
            }
        }

        return [
            'source' => 'google_search_console_url_inspection',
            'checked_at' => now()->toIso8601String(),
            'requested_pages' => min(count(array_unique($urls)), 50),
            'checked_pages' => count(array_filter($pages, fn ($p) => $p['success'])),
            'pages' => $pages, 'issues' => $issues,
            'note' => 'This is Google’s last indexed version, not a live crawl. Changes are confirmed only after Google recrawls.',
        ];
    }

    public function auditSite(Customer $customer): SeoAudit
    {
        $root = $this->root($customer);
        if (! $root) {
            throw new \InvalidArgumentException('Set a website URL before checking indexing.');
        }
        $urls = [$root];
        $discoveryIssue = null;
        try {
            $urls = array_merge($urls, $this->sitemapUrls($root.'sitemap.xml', $root));
        } catch (\Throwable $e) {
            report($e);
            $discoveryIssue = $this->issue('warning', 'sitemap_unavailable', $root.'sitemap.xml',
                'The sitemap could not be read; only the homepage was inspected.',
                'Publish a valid XML sitemap at /sitemap.xml and rerun the audit.');
        }
        // Money pages are checked first when a larger sitemap exceeds our bound.
        usort($urls, fn ($a, $b) => (int) $this->isCommercial($b) <=> (int) $this->isCommercial($a));
        $health = $this->inspect($customer, $urls);
        $health['scope'] = 'sitemap';
        if ($discoveryIssue) {
            $health['issues'][] = $discoveryIssue;
        }

        return SeoAudit::create([
            'customer_id' => $customer->id, 'url' => $root, 'score' => null,
            'indexing_analysis' => $health, 'issues' => $health['issues'],
            'recommendations' => array_map(fn ($issue) => [
                'category' => 'indexing', 'priority' => $issue['severity'] === 'critical' ? 'high' : 'medium',
                'message' => $issue['action'], 'url' => $issue['url'],
            ], $health['issues']),
        ]);
    }

    /** Fetch only same-origin sitemap files; PublicWebsiteFetcher validates every DNS/redirect hop. */
    private function sitemapUrls(string $url, string $root, int $depth = 0): array
    {
        $response = $this->fetcher->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Sitemap returned HTTP '.$response->status());
        }
        $xml = @simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET);
        if (! $xml) {
            throw new \RuntimeException('Sitemap is not valid XML.');
        }
        $urls = [];
        if ($xml->getName() === 'sitemapindex' && $depth < 1) {
            foreach (array_slice($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [], 0, 3) as $loc) {
                $child = trim((string) $loc);
                if (str_starts_with($child, $root)) {
                    $urls = array_merge($urls, $this->sitemapUrls($child, $root, $depth + 1));
                }
            }
        } elseif ($xml->getName() === 'urlset') {
            foreach ($xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]') ?: [] as $loc) {
                $page = trim((string) $loc);
                if (str_starts_with($page, $root)) {
                    $urls[] = $page;
                }
                if (count($urls) >= 250) {
                    break;
                }
            }
        } else {
            throw new \RuntimeException('Sitemap must contain a urlset or sitemapindex.');
        }

        return array_slice(array_values(array_unique($urls)), 0, 250);
    }

    private function root(Customer $customer): ?string
    {
        $website = trim((string) $customer->website);
        if (! str_contains($website, '://')) {
            $website = 'https://'.$website;
        }
        $host = parse_url($website, PHP_URL_HOST);
        $scheme = parse_url($website, PHP_URL_SCHEME);

        return $host && in_array($scheme, ['http', 'https'], true) ? $scheme.'://'.strtolower($host).'/' : null;
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    private function normalize(string $url): string
    {
        return rtrim(explode('#', $url, 2)[0], '/');
    }

    private function isCommercial(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array(trim($path, '/'), ['', 'pricing', 'features', 'how-it-works'], true)
            || (bool) preg_match('#/(google-ads|ai-ads|one-time|setup|services)(?:[-/]|$)#', $path);
    }

    private function issue(string $severity, string $code, string $url, string $message, string $action): array
    {
        return compact('severity', 'code', 'url', 'message', 'action') + ['category' => 'indexing'];
    }
}
