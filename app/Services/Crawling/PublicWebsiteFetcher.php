<?php

namespace App\Services\Crawling;

use App\Rules\SafePublicUrl;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Validate every hop, then pin the connection to that validated DNS answer. */
class PublicWebsiteFetcher
{
    public function get(string $url): Response
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $addresses = $this->addresses($url);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https:') ? 443 : 80);
            $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SiteToSpend/1.0)', 'Accept-Encoding' => 'identity'])
                ->timeout(30)->connectTimeout(10)->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'decode_content' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]],
                    'on_headers' => function ($response) {
                        if ((int) $response->getHeaderLine('Content-Length') > 10_000_000) {
                            throw new \RuntimeException('Website response exceeds the crawl limit.');
                        }
                    },
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > 10_000_000) {
                            throw new \RuntimeException('Website response exceeds the crawl limit.');
                        }
                    },
                ])->get($url);
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            $location = $response->header('Location');
            if (! $location) {
                throw new \RuntimeException('Website returned a redirect without a destination.');
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }
        throw new \RuntimeException('Website exceeded the redirect limit.');
    }

    /** @return non-empty-list<string> */
    protected function addresses(string $url): array
    {
        $addresses = SafePublicUrl::publicAddresses($url);
        if ($addresses === []) {
            throw new \InvalidArgumentException('Crawler may only fetch public HTTP(S) websites on ports 80 and 443.');
        }

        return $addresses;
    }
}
