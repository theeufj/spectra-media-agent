<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Captures ad platform click IDs from the URL on first visit and stores them
 * in the session so they can be saved to the user record on registration.
 *
 * Supported click IDs:
 *   gclid  — Google Ads
 *   fbclid — Meta / Facebook Ads
 *   msclid — Microsoft Ads
 *   ttclid — TikTok Ads
 */
class CaptureClickIds
{
    private const PARAMS      = ['gclid', 'fbclid', 'msclid', 'ttclid'];
    private const SESSION_KEY = 'click_ids';

    public function handle(Request $request, Closure $next): mixed
    {
        $stored  = session(self::SESSION_KEY, []);
        $changed = false;

        foreach (self::PARAMS as $param) {
            if ($request->filled($param) && empty($stored[$param])) {
                $stored[$param] = $request->query($param);
                $changed = true;
            }
        }

        if ($changed) {
            session([self::SESSION_KEY => $stored]);
        }

        return $next($request);
    }

    /**
     * Retrieve a stored click ID from the current session.
     */
    public static function get(string $param): ?string
    {
        return session(self::SESSION_KEY . '.' . $param);
    }

    /**
     * Return all stored click IDs (non-null values only).
     */
    public static function all(): array
    {
        return array_filter(session(self::SESSION_KEY, []));
    }
}
