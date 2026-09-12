<?php

namespace App\Services\Onboarding;

use App\Rules\SafePublicUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What is this address, and what is the business called?
 *
 * Both answers used to be one line in QuickStartController:
 *
 *     $host = parse_url($url, PHP_URL_HOST);
 *     $businessName = ucfirst(str_replace('www.', '', $host));
 *
 * A real signup in July pasted a bit.ly link to her trucking brokerage. The
 * product crawled the shortener, named her business "Bit.ly", built a brand
 * profile from whatever the link resolved to, scored its own extraction 88 out
 * of 100, and she left four minutes later. She is one of three signups that
 * month who verified an email, saw what we made of their business, and never
 * came back.
 *
 * So: follow shorteners to the real site before anything is stored, and take
 * the name from the registrable domain rather than the whole host.
 */
class WebsiteIdentity
{
    /**
     * Hosts that are never the customer's website — they are a redirect to it.
     *
     * Kept as an explicit list rather than a heuristic: "short host, short TLD"
     * would also catch legitimate two-letter-domain businesses, and being wrong
     * in that direction renames a real company.
     */
    private const SHORTENERS = [
        'bit.ly', 'bitly.com', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly',
        'buff.ly', 'rebrand.ly', 'lnkd.in', 'is.gd', 'cutt.ly', 'shorturl.at',
        'rb.gy', 'tiny.cc', 'shorte.st', 'soo.gd', 's2r.co', 'clck.ru',
        'linktr.ee', 'trib.al', 'dlvr.it', 'ift.tt', 'amzn.to', 'fb.me',
    ];

    /**
     * Second-level domains that carry no name of their own — the business is
     * the label in front of them. "acme.co.uk" is Acme, not Co.
     */
    private const COMPOUND_TLDS = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au',
        'edu.au', 'gov.au', 'co.nz', 'com.br', 'co.za', 'co.jp', 'co.in',
        'com.sg', 'com.mx', 'com.tr', 'co.il', 'co.kr',
    ];

    /**
     * Resolve a submitted address to the page the customer actually means.
     *
     * Only shorteners are followed. Every other URL is returned untouched: an
     * ordinary site redirecting http → https → www is not something we need to
     * rewrite, and chasing those would turn one signup into several requests
     * for no gain.
     */
    public function resolve(string $url): string
    {
        $host = $this->hostOf($url);

        if ($host === null || ! $this->isShortener($host)) {
            return $url;
        }

        try {
            $response = Http::timeout(8)
                ->withUserAgent('sitetospend/1.0 (+https://sitetospend.com)')
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->get($url);

            $hops = $response->handlerStats()['redirect_url'] ?? null;
            $final = $response->effectiveUri()?->__toString() ?? $hops;

            // The destination is a URL a stranger chose, so it gets the same
            // SSRF check the submitted one did — a shortener pointing at
            // 169.254.169.254 is precisely the attack this guards.
            if (is_string($final) && $final !== '' && SafePublicUrl::isSafe($final)) {
                return $final;
            }

            Log::info('WebsiteIdentity: shortener resolved to an address we will not fetch', [
                'submitted' => $url,
                'resolved' => $final,
            ]);
        } catch (\Throwable $e) {
            // A dead shortlink is the customer's problem to see, not a reason
            // to fail signup — they keep the URL they typed and the crawl
            // reports what it finds.
            Log::info('WebsiteIdentity: could not resolve shortener', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $url;
    }

    /**
     * A business name worth showing someone.
     *
     * "bit.ly" became "Bit.ly" and "cherished-domains.com" would have become
     * "Cherished-domains.com". The TLD is not part of anyone's name, and a
     * hyphen is a space that a domain could not hold.
     */
    public function businessName(string $url): string
    {
        $host = $this->hostOf($url);

        if ($host === null) {
            return 'My business';
        }

        $host = Str::of($host)->lower()->after('www.')->toString();
        $label = $this->registrableLabel($host);

        $name = Str::of($label)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();

        return $name !== '' ? $name : 'My business';
    }

    public function isShortener(string $host): bool
    {
        return in_array(Str::of($host)->lower()->after('www.')->toString(), self::SHORTENERS, true);
    }

    /**
     * The label that names the business: "shop.acme.co.uk" -> "acme".
     */
    private function registrableLabel(string $host): string
    {
        foreach (self::COMPOUND_TLDS as $tld) {
            if (str_ends_with($host, '.'.$tld)) {
                $withoutTld = substr($host, 0, -(strlen($tld) + 1));

                return (string) Str::of($withoutTld)->afterLast('.');
            }
        }

        $parts = explode('.', $host);

        // host with no dot at all (intranet name, or a typo) — use it whole
        if (count($parts) < 2) {
            return $host;
        }

        // Drop the TLD, take the label before it.
        array_pop($parts);

        return (string) end($parts);
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        // Bare "example.com" with no scheme still names a business.
        $host = parse_url('https://'.ltrim($url, '/'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
