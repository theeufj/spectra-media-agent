<?php

namespace App\Support;

use Illuminate\Http\Request;

/** The canonical identity and crawl policy of the public marketing surface. */
class PublicSeo
{
    public static function sharedUrl(string $path): string
    {
        return 'https://'.config('public_seo.shared_host').'/'.ltrim($path, '/');
    }

    public static function normalizedHost(Request $request): string
    {
        return strtolower((string) preg_replace('/^www\./i', '', $request->getHost()));
    }

    public static function isPublicHost(Request $request): bool
    {
        $tenant = config('tenants')[self::normalizedHost($request)] ?? null;

        return is_array($tenant) && isset($tenant['key']);
    }

    public static function canonicalUrl(Request $request): string
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');
        $host = in_array($path, config('public_seo.tenant_specific_paths'), true) && self::isPublicHost($request)
            ? self::normalizedHost($request)
            : config('public_seo.shared_host');

        // Pagination has distinct links/content. Filter combinations remain
        // self-canonical but are noindexed, instead of creating search pages.
        $query = [];
        if ($path === '/blog') {
            $page = $request->query('page');
            if (is_scalar($page) && filter_var($page, FILTER_VALIDATE_INT) && (int) $page > 1) {
                $query['page'] = (int) $page;
            }
            $category = $request->query('category');
            if (is_string($category) && $category !== '' && $category !== 'All') {
                $query['category'] = $category;
            }
        }

        return 'https://'.$host.$path.($query ? '?'.http_build_query($query) : '');
    }

    public static function isPublicPage(Request $request): bool
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');

        return array_key_exists($path, config('public_seo.pages')) || $request->is('blog/*');
    }

    public static function robots(Request $request): ?string
    {
        if (! self::isPublicHost($request) || ! self::isPublicPage($request)) {
            return 'noindex, nofollow';
        }

        if ($request->is('blog') && $request->filled('category') && $request->query('category') !== 'All') {
            return 'noindex, follow';
        }

        return null;
    }
}
