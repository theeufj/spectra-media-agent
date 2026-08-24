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
}
