<?php

namespace App\Services\KnowledgeBase;

use App\Services\Crawling\PublicWebsiteFetcher;
use Symfony\Component\DomCrawler\Crawler;

class SourceDiscovery
{
    public function __construct(private PublicWebsiteFetcher $fetcher) {}

    public function discover(string $website): array
    {
        $parts = parse_url($website);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $sitemap = preg_match('/\.xml(?:\.gz)?$/i', $website) ? $website : $origin.'/sitemap.xml';
        $urls = [];
        try {
            $urls = $this->sitemap($sitemap, 0);
        } catch (\Throwable $e) {
            report($e);
        }
        if ($urls === []) {
            $response = $this->fetcher->get($website);
            if ($response->failed()) {
                throw new \RuntimeException('The website did not respond. Add an individual page or paste your business information.');
            }
            $crawler = new Crawler($response->body(), $website);
            $urls = [$website, ...$crawler->filter('a[href]')->each(fn (Crawler $link) => $link->link()->getUri())];
        }
        $host = preg_replace('/^www\./', '', strtolower($parts['host'] ?? ''));
        $candidates = [];
        foreach (array_unique($urls) as $url) {
            $url = strtok($url, '#');
            $candidateHost = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            if ($candidateHost !== $host || preg_match('#/(?:login|register|admin|auth|cart|checkout|password|logout)(?:/|$)#', $path)
                || preg_match('/\.(?:jpg|jpeg|png|gif|svg|zip|pdf)$/', $path)) {
                continue;
            }
            $priority = preg_match('#(?:about|pricing|services?|contact|products?|collections|solutions)#', $path) || in_array($path, ['', '/'], true);
            $candidates[$url] = ['url' => $url, 'title' => $path === '' || $path === '/' ? 'Homepage' : ucwords(str_replace(['/', '-', '_'], ' ', trim($path, '/'))), 'recommended' => (bool) $priority];
            if (count($candidates) >= 250) {
                break;
            }
        }
        uasort($candidates, fn ($a, $b) => (int) $b['recommended'] <=> (int) $a['recommended']);

        return array_values($candidates);
    }

    private function sitemap(string $url, int $depth): array
    {
        if ($depth > 2) {
            return [];
        }
        $response = $this->fetcher->get($url);
        if ($response->failed()) {
            return [];
        }
        $body = $response->body();
        if (str_ends_with($url, '.gz')) {
            $body = gzdecode($body) ?: '';
        }
        if (strlen($body) > 5_000_000) {
            throw new \RuntimeException('This sitemap is too large. Add a smaller sitemap or individual pages.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
            if (! $xml) {
                return [];
            }
            $urls = [];
            if ($xml->getName() === 'sitemapindex') {
                foreach (array_slice($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [], 0, 8) as $loc) {
                    $urls = [...$urls, ...$this->sitemap((string) $loc, $depth + 1)];
                    if (count($urls) >= 250) {
                        break;
                    }
                }
            } else {
                foreach ($xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]') ?: [] as $loc) {
                    $urls[] = (string) $loc;
                }
            }

            return array_slice($urls, 0, 250);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
