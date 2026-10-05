<?php

namespace App\Services\Deployment;

use App\Services\Crawling\PublicWebsiteFetcher;

/** A successful request is preliminary evidence, never a claim of Google approval. */
class AdDestinationCheck
{
    public function __construct(private PublicWebsiteFetcher $fetcher) {}

    public function check(?string $url): array
    {
        $result = ['url' => $url, 'checked_at' => now()->toIso8601String(), 'profile' => 'desktop',
            'status' => 'unknown', 'http_status' => null, 'message' => ''];
        if (! $url) {
            $result['message'] = 'Choose a landing-page URL before deploying this ad.';

            return $result;
        }
        try {
            $response = $this->fetcher->getForAd($url);
            $result['http_status'] = $response->status();
            $result['status'] = $response->successful() ? 'reachable' : 'unreachable';
            $result['message'] = $response->successful()
                ? 'The landing page responded to our preliminary desktop check. Google policy review is still required.'
                : "The ad destination returned HTTP {$response->status()} during our desktop check. Fix the website or choose a working landing page before retrying deployment.";
        } catch (\InvalidArgumentException) {
            $result['message'] = 'The ad destination must resolve to a public HTTP or HTTPS website. Review the URL and its redirects before retrying deployment.';
        } catch (\Throwable $e) {
            report($e);
            $result['message'] = 'We could not verify the ad destination because its request failed or timed out. Check the website and retry deployment.';
        }

        return $result;
    }
}
