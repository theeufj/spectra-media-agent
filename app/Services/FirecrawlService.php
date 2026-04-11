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

            $result['markdown'] = is_string($data['markdown'] ?? null) ? $data['markdown'] : null;
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $result['title'] = is_string($metadata['title'] ?? null) ? $metadata['title'] : null;
            $result['meta_description'] = is_string($metadata['description'] ?? null) ? $metadata['description'] : null;
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

    /**
     * Search the web via Firecrawl's search endpoint.
     *
     * Supports query operators: "", -, site:, -site:, inurl:, intitle:, filetype:
     *
     * @param string $query The search query (max 500 chars)
     * @param int $limit Max results (1-100, default 10)
     * @return array{results: array, success: bool}
     */
    public function search(string $query, int $limit = 10): array
    {
        if (!$this->isConfigured()) {
            Log::debug('FirecrawlService: API key not configured, skipping search');
            return ['results' => [], 'success' => false];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(60)->post('https://api.firecrawl.dev/v2/search', [
                'query' => substr($query, 0, 500),
                'limit' => min(max($limit, 1), 100),
            ]);

            if (!$response->successful()) {
                Log::warning('FirecrawlService: Search API error', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                return ['results' => [], 'success' => false];
            }

            $data = $response->json('data', []);
            $webResults = $data['web'] ?? $data ?? [];

            Log::info('FirecrawlService: Search completed', [
                'query' => $query,
                'results_count' => count($webResults),
            ]);

            return ['results' => $webResults, 'success' => true];
        } catch (\Exception $e) {
            Log::warning('FirecrawlService: Search exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return ['results' => [], 'success' => false];
        }
    }
}
