<?php

namespace App\Support;

class Url
{
    /**
     * Normalise a stored site URL to https. An http:// scheme poisons every
     * downstream fetch (crawl, GTM verification, Browsershot) because
     * CrawlSitemap preserves an explicit scheme — and most sites now 301 or
     * bot-block plain http. Schemeless input gets https:// prepended.
     */
    public static function forceHttps(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (preg_match('#^http://#i', $url)) {
            return 'https://'.substr($url, 7);
        }

        if (! preg_match('#^https://#i', $url)) {
            return 'https://'.$url;
        }

        return $url;
    }

    /**
     * Is this a website the crawler may safely fetch from our servers?
     *
     * The crawl chain (Browsershot included) requests whatever URL a user
     * typed into onboarding — from the production box. Without this check,
     * "your website" could be the cloud metadata endpoint
     * (169.254.169.254), localhost, or anything on the private network.
     * Hostnames are resolved and the resolved address must be publicly
     * routable; IP literals are checked directly.
     */
    public static function isSafePublicHost(?string $url): bool
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        // An IP literal is judged as-is; a hostname by what it resolves to.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            // gethostbyname returns the input unchanged on failure — an
            // unresolvable host can't be crawled anyway.
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
