<?php

namespace App\Http\Controllers;

use App\Support\PublicSeo;
use App\Support\PublicSitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves a tenant's canonical pages and crawler rules. Shared guides are
 * canonical on SiteToSpend, rather than cloned into every vertical sitemap.
 */
class TenantStaticController extends Controller
{
    public function sitemap(Request $request, PublicSitemap $sitemap): Response
    {
        return response($sitemap->xml($request), 200)
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        if (! PublicSeo::isPublicHost($request)) {
            return response("User-agent: *\nDisallow: /\n", 200)->header('Content-Type', 'text/plain');
        }

        return response($this->forHost(resource_path('robots.txt'), $request), 200)
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function forHost(string $templatePath, Request $request): string
    {
        return str_replace(
            'https://sitetospend.com',
            'https://'.$request->getHost(),
            (string) file_get_contents($templatePath),
        );
    }
}
