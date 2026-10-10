<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

/**
 * Captures ad platform click IDs from the URL on first visit and stores them
 * in the session and an encrypted, host-only cookie so they survive a returning
 * visitor's expired session. Identifiers expire 30 days after first capture.
 *
 * Supported click IDs:
 *   gclid  — Google Ads
 *   gbraid — Google Ads, app-to-web on iOS (no gclid available)
 *   wbraid — Google Ads, web-to-web on iOS (no gclid available)
 *   fbclid — Meta / Facebook Ads
 *   msclid — Microsoft Ads
 *   ttclid — TikTok Ads
 *
 * gbraid/wbraid are not optional extras: where ATT applies, Google sends one of
 * them *instead of* gclid. Capturing only gclid silently discards that traffic,
 * and on this account 99% of clicks are mobile or tablet.
 */
class CaptureClickIds
{
    private const PARAMS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclid', 'ttclid'];

    private const SESSION_KEY = 'click_ids';

    private const CONTEXT_KEY = 'click_ids_context';

    public const COOKIE_NAME = 'spectra_click_ids';

    private const RETENTION_SECONDS = 30 * 86400;

    public function handle(Request $request, Closure $next): mixed
    {
        $context = ['host' => strtolower($request->getHost()), 'tenant' => data_get($request->attributes->get('tenant'), 'key')];
        $stored = self::clean(session(self::SESSION_KEY, []));
        $savedContext = session(self::CONTEXT_KEY);
        $capturedAt = now()->timestamp;

        if (is_array($savedContext)) {
            if ($this->matches($savedContext, $context)) {
                $capturedAt = (int) $savedContext['captured_at'];
            } else {
                $stored = [];
            }
        }

        // EncryptCookies authenticates/decrypts this cookie before this middleware.
        // Host and tenant guards also prevent a shared session from mixing skins.
        $cookie = $request->cookie(self::COOKIE_NAME);
        $saved = is_string($cookie) ? json_decode($cookie, true) : null;
        if ($stored === [] && is_array($saved) && $this->matches($saved, $context)) {
            $stored = self::clean($saved['ids'] ?? []);
            $capturedAt = (int) $saved['captured_at'];
        }

        foreach (self::PARAMS as $param) {
            $value = $request->query($param);
            if (self::valid($value) && empty($stored[$param])) {
                $stored[$param] = $value;
            }
        }

        if ($stored !== []) {
            $saved = array_merge($context, ['captured_at' => $capturedAt, 'ids' => $stored]);
            session([self::SESSION_KEY => $stored, self::CONTEXT_KEY => $saved]);
            // The capture time is fixed: browsing does not renew retention.
            Cookie::queue(new ResponseCookie(self::COOKIE_NAME, json_encode($saved, JSON_THROW_ON_ERROR),
                $capturedAt + self::RETENTION_SECONDS, '/', null, $request->isSecure(), true, false, 'lax'));
        } else {
            session()->forget([self::SESSION_KEY, self::CONTEXT_KEY]);
            if ($cookie !== null) {
                Cookie::queue(new ResponseCookie(self::COOKIE_NAME, '', 1, '/', null, $request->isSecure(), true, false, 'lax'));
            }
        }

        return $next($request);
    }

    /**
     * Retrieve a stored click ID from the current session.
     */
    public static function get(string $param): ?string
    {
        return self::all()[$param] ?? null;
    }

    /**
     * Return all stored click IDs (non-null values only).
     */
    public static function all(): array
    {
        return self::clean(session(self::SESSION_KEY, []));
    }

    /** @return array<string, string> */
    private static function clean(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_filter(array_intersect_key($values, array_flip(self::PARAMS)), self::valid(...));
    }

    private static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._~+\/=\\-]{1,255}\z/D', $value) === 1;
    }

    private function matches(array $saved, array $context): bool
    {
        $timestamp = $saved['captured_at'] ?? null;

        return ($saved['host'] ?? null) === $context['host'] && ($saved['tenant'] ?? null) === $context['tenant']
            && is_int($timestamp) && $timestamp <= now()->timestamp && $timestamp > now()->timestamp - self::RETENTION_SECONDS;
    }
}
