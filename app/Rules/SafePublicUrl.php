<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The URL must point at a publicly routable host. Everything a user submits
 * here gets fetched BY OUR SERVERS (crawler, Browsershot, the public demo,
 * GTM verification) — without this, "your website" could be the cloud
 * metadata endpoint or an internal service.
 *
 * Laravel's `url` rule is not that check: its protocol list runs from `aaa`
 * to `z39.50s` (so `file:///etc/passwd` passes) and its pattern explicitly
 * allows `user:pass@host`. Both are checked here against the *raw* value,
 * because the caller fetches the raw value — normalising before the check
 * and fetching without it is how a `file://` URL gets waved through.
 *
 * The host is judged by every address it resolves to, not the first one:
 * `gethostbyname()` returns a single A record, so a name publishing one
 * public and one 127.0.0.1 address would pass or fail on a coin flip.
 *
 * This is validate-time, and DNS can change between here and the fetch, so
 * it is a filter and not a guarantee — whatever finally makes the request
 * should call {@see SafePublicUrl::isSafe()} again immediately beforehand
 * and re-check every redirect hop.
 */
class SafePublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isSafe(is_string($value) ? $value : null)) {
            $fail('That address doesn\'t look like a reachable public website.');
        }
    }

    /**
     * May our servers fetch this exact URL?
     */
    public static function isSafe(?string $url): bool
    {
        return self::publicAddresses($url) !== [];
    }

    /** @return list<string> */
    public static function publicAddresses(?string $url): array
    {
        $url = trim((string) $url);

        if ($url === '') {
            return [];
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return [];
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return [];
        }

        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return [];
        }

        // Credentials in the authority. `https://www.example.com@169.254.169.254/`
        // reads as a trusted host to a human and to a log line, and clients
        // disagree with parse_url about where the host starts. Nothing we fetch
        // legitimately carries them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return [];
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '') {
            return [];
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return [];
        }

        // An IP literal is judged as-is; a hostname by everything it resolves to.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($addresses === []) {
            // Unresolvable: nothing to fetch, and nothing we can vouch for.
            return [];
        }

        foreach ($addresses as $address) {
            if (! self::isPubliclyRoutable($address)) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * Every address a hostname publishes, IPv4 and IPv6.
     *
     * gethostbynamel() follows CNAMEs and returns the full A set (which
     * dns_get_record does not reliably do for a CNAME'd name); AAAA is asked
     * for separately because an IPv6-only internal host is just as reachable
     * from the app box as an IPv4 one.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * PHP's own filter covers private (10/8, 172.16/12, 192.168/16, fc00::/7),
     * loopback, link-local (169.254/16, fe80::/10), IPv4-mapped IPv6 and the
     * 0/8 and 240/4 reserved blocks. It does not cover carrier-grade NAT or
     * multicast, both of which still reach something on our side of the edge.
     */
    private static function isPubliclyRoutable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = (int) ip2long($ip);

            // 100.64.0.0/10 (CGNAT) and 224.0.0.0/4 (multicast).
            return ($long & 0xFFC00000) !== 0x64400000
                && ($long & 0xF0000000) !== 0xE0000000;
        }

        $packed = @inet_pton($ip);

        // ff00::/8 — IPv6 multicast.
        return $packed !== false && ord($packed[0]) !== 0xFF;
    }
}
