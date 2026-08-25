<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves sitemap.xml and robots.txt with the requesting tenant's domain.
 *
 * These lived in public/ with sitetospend.com hardcoded, so every vertical
 * skin served sitetospend URLs — realpropertyads.com's sitemap pointed
 * crawlers (including our own onboarding crawl) at 28 sitetospend pages,
 * which is how a real-estate customer ended up with a brand guideline
 * flavoured by the wrong site.
 */
class TenantStaticController extends Controller
{
    public function sitemap(Request $request): Response
    {
        return response($this->forHost(resource_path('sitemap.xml'), $request), 200)
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
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
