<?php

namespace App\Services\Crawling;

use Illuminate\Support\Facades\Http;

/** Untrusted JavaScript runs only in the isolated renderer, never in a queue worker. */
class WebsiteRenderer
{
    public function __construct(private PublicWebsiteFetcher $fetcher) {}

    public function html(string $url): string
    {
        if (! config('browsershot.renderer_url')) {
            return $this->fetcher->get($url)->throw()->body();
        }

        return $this->render($url, 'html');
    }

    public function screenshot(string $url): string
    {
        return $this->render($url, 'screenshot');
    }

    private function render(string $url, string $action): string
    {
        $endpoint = config('browsershot.renderer_url');
        $token = config('browsershot.renderer_token');
        if (! $endpoint || ! $token) {
            throw new \RuntimeException('The isolated website renderer is not configured.');
        }
        if (! \App\Rules\SafePublicUrl::isSafe($url)) {
            throw new \InvalidArgumentException('The website must resolve to a public address.');
        }
        // The renderer validates/pins each navigation, redirect and subresource.
        $result = Http::withToken($token)->timeout(130)->withOptions(['allow_redirects' => false])
            ->post(rtrim($endpoint, '/').'/render', compact('url', 'action'))->throw()->json('result');
        if (! is_string($result)) {
            throw new \RuntimeException('Invalid website renderer response.');
        }

        return $result;
    }
}
