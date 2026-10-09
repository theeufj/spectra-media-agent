<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Http\Request;

class PublicSitemap
{
    public function __construct(private PublicContentModifiedAt $modifiedAt) {}

    /** @return list<array{loc: string, lastmod: string|null}> */
    public function pages(Request $request): array
    {
        if (! PublicSeo::isPublicHost($request)) {
            return [];
        }

        $host = PublicSeo::normalizedHost($request);
        $shared = $host === config('public_seo.shared_host');
        $pages = [];
        foreach (config('public_seo.pages') as $path => $sources) {
            if (! $shared && ! in_array($path, config('public_seo.tenant_specific_paths'), true)) {
                continue;
            }

            $lastmod = $this->modifiedAt->forSources($sources);
            if (in_array($path, ['/', '/pricing'], true)) {
                $planModified = Plan::active()->max('updated_at');
                if ($planModified && ($lastmod === null || strtotime($planModified) > strtotime($lastmod))) {
                    $lastmod = date(DATE_ATOM, strtotime($planModified));
                }
            }

            $pages[] = ['loc' => 'https://'.$host.$path, 'lastmod' => $lastmod];
        }

        if ($shared) {
            foreach (HelpArticles::index() as $article) {
                $pages[] = [
                    'loc' => PublicSeo::sharedUrl('/blog/'.$article['slug']),
                    'lastmod' => $article['modified'] ?? $article['updated'] ?? $article['published'] ?? null,
                ];
            }
        }

        return $pages;
    }

    public function xml(Request $request): string
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($this->pages($request) as $page) {
            $xml->startElement('url');
            $xml->writeElement('loc', $page['loc']);
            if ($page['lastmod'] !== null) {
                $xml->writeElement('lastmod', $page['lastmod']);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
