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

            // Strip junk query strings on GET requests (e.g. ?$, ?random=)
            // Skip signed URL routes (email verification) and OAuth callbacks
            if ($request->isMethod('GET') && $request->getQueryString() !== null && !$request->is('auth/*/callback') && !$request->is('settings/*/callback') && !isset($request->query()['signature'])) {
                // Ad-click and campaign attribution params MUST survive: Google Ads
                // auto-tagging (gclid/gbraid/wbraid), Meta (fbclid), Microsoft (msclid),
                // TikTok (ttclid) and UTMs. Stripping them here breaks conversion
                // tracking — gtag never sets its _gcl_aw cookie and CaptureClickIds
                // never stores the gclid for server-side upload.
                $trackingParams = [
                    'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclid', 'ttclid',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                ];
                $allowed = array_merge(
                    ['page', 'token', 'search', 'sort', 'filter', 'plan', 'status', 'priority', 'category'],
                    $trackingParams,
                );
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
