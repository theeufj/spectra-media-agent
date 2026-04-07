<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirecrawlService
{
    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.firecrawl.api_key');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Scrape a URL and return markdown content + metadata.
     *
     * @param string $url The URL to scrape
     * @return array{markdown: ?string, title: ?string, meta_description: ?string, success: bool}
     */
    public function scrape(string $url): array
    {
        $result = [
            'markdown' => null,
            'title' => null,
            'meta_description' => null,
            'success' => false,
        ];

        if (!$this->isConfigured()) {
            Log::debug('FirecrawlService: API key not configured, skipping');
            return $result;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->post('https://api.firecrawl.dev/v1/scrape', [
                'url' => $url,
                'formats' => ['markdown'],
            ]);

            if (!$response->successful()) {
                Log::warning('FirecrawlService: API error', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return $result;
            }

            $data = $response->json('data', []);

            $result['markdown'] = $data['markdown'] ?? null;
            $result['title'] = $data['metadata']['title'] ?? null;
            $result['meta_description'] = $data['metadata']['description'] ?? null;
            $result['success'] = !empty($result['markdown']);

            Log::info('FirecrawlService: Scraped successfully', [
                'url' => $url,
                'content_length' => strlen($result['markdown'] ?? ''),
            ]);

        } catch (\Exception $e) {
            Log::warning('FirecrawlService: Exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
