<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalRedirect
{
    /**
     * The crawlable marketing surface.
     *
     * Query-string stripping applies HERE AND NOWHERE ELSE. It is an SEO
     * measure — it exists so `/pricing?$` and `/pricing?random=1` do not become
     * duplicate indexed URLs — and it has no business running on the
     * authenticated app.
     *
     * It used to run on every request, keeping an allowlist of "known good"
     * parameters. That inverted the risk: any page using a parameter nobody had
     * thought to add was silently 301'd to a stripped URL, in production only,
     * where no test would ever see it. The admin dashboard's ?tab= and ?period=
     * were broken exactly this way — clicking a tab appeared to do nothing,
     * because the browser was being bounced straight back.
     *
     * The failure modes are not symmetrical. Missing an app parameter breaks a
     * feature invisibly; missing a marketing page leaves a junk parameter on a
     * page nobody crawls. So the list is of pages to canonicalise, not of
     * parameters to permit.
     *
     * @var list<string>
     */
    private const CRAWLABLE_PATHS = [
        '/',
        'features',
        'how-it-works',
        'pricing',
        'about',
        'terms-of-service',
        'privacy-policy',
        'blog',
        'blog/*',
    ];

    /**
     * Redirect non-canonical URLs to their canonical form:
     * - http → https
     * - www.sitetospend.com → sitetospend.com
     * - strip unexpected query strings, on crawlable marketing pages only
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

            // Strip junk query strings (e.g. ?$, ?random=) on crawlable pages.
            // Signed URLs and OAuth callbacks are excluded even there, since a
            // stripped signature or code is a broken flow rather than a tidier URL.
            if ($request->isMethod('GET')
                && $request->getQueryString() !== null
                && $request->is(...self::CRAWLABLE_PATHS)
                && ! $request->is('auth/*/callback')
                && ! $request->is('settings/*/callback')
                && ! isset($request->query()['signature'])) {
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
                if (! empty($qs)) {
                    $cleanPath .= '?'.http_build_query($qs);
                }

                $canonicalUrl = $scheme.'://'.$host.$cleanPath;

                return redirect()->away($canonicalUrl, 301);
            }
        }

        return $next($request);
    }
}
