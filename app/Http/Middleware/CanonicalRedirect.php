<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalRedirect
{
    /**
     * Redirect non-canonical URLs to their canonical form:
     * - http → https
     * - www.sitetospend.com → sitetospend.com
     * - Strip unexpected query strings on public pages
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            $host = $request->getHost();
            $scheme = $request->getScheme();
            $needsRedirect = false;

            // Redirect www to non-www
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
                $needsRedirect = true;
            }

            // Redirect http to https
            if ($scheme !== 'https') {
                $scheme = 'https';
                $needsRedirect = true;
            }

            // Strip junk query strings on GET requests (e.g. ?$, ?fbclid=, etc.)
            // Skip signed URL routes (email verification) and OAuth callbacks
            if ($request->isMethod('GET') && $request->getQueryString() !== null && !$request->is('auth/*/callback') && !isset($request->query()['signature'])) {
                $allowed = ['page', 'token', 'search', 'sort', 'filter', 'plan', 'status', 'priority', 'category'];
                $query = $request->query();
                $filtered = array_intersect_key($query, array_flip($allowed));

                if (count($filtered) !== count($query)) {
                    $needsRedirect = true;
                    $request->query->replace($filtered);
                }
            }

            if ($needsRedirect) {
                $path = $request->getRequestUri();

                // Rebuild clean query string
                $qs = $request->query->all();
                $cleanPath = $request->getPathInfo();
                if (!empty($qs)) {
                    $cleanPath .= '?' . http_build_query($qs);
                }

                $canonicalUrl = $scheme . '://' . $host . $cleanPath;

                return redirect()->away($canonicalUrl, 301);
            }
        }

        return $next($request);
    }
}
