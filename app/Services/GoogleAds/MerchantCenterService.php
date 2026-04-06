<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFeed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with Google Merchant Center Content API.
 */
class MerchantCenterService
{
    protected Customer $customer;
    protected string $baseUrl = 'https://shoppingcontent.googleapis.com/content/v2.1';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    /**
     * Get Merchant Center account info.
     */
    public function getAccountInfo(string $merchantId): ?array
    {
        $response = $this->apiCall("accounts/{$merchantId}");
        return $response;
    }

    /**
     * List products in the Merchant Center account.
     */
    public function listProducts(string $merchantId, int $maxResults = 250): array
    {
        $products = [];
        $pageToken = null;

        do {
            $params = ['maxResults' => $maxResults];
            if ($pageToken) $params['pageToken'] = $pageToken;

            $response = $this->apiCall("{$merchantId}/products", $params);
            if (!$response) break;

            foreach ($response['resources'] ?? [] as $product) {
                $products[] = $this->normalizeProduct($product);
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken && count($products) < 5000);

        return $products;
    }

    /**
     * Get product status (approval, disapproval reasons).
     */
    public function getProductStatuses(string $merchantId): array
    {
        $statuses = [];
        $pageToken = null;

        do {
            $params = ['maxResults' => 250];
            if ($pageToken) $params['pageToken'] = $pageToken;

            $response = $this->apiCall("{$merchantId}/productstatuses", $params);
            if (!$response) break;

            foreach ($response['resources'] ?? [] as $status) {
                $statuses[$status['productId'] ?? ''] = [
                    'status' => $this->determineStatus($status),
                    'disapproval_reasons' => $this->extractDisapprovalReasons($status),
                ];
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken);

        return $statuses;
    }

    /**
     * Insert or update a product in Merchant Center.
     */
    public function upsertProduct(string $merchantId, array $productData): ?array
    {
        return $this->apiCall("{$merchantId}/products", body: $productData, method: 'POST');
    }

    /**
     * Delete a product from Merchant Center.
     */
    public function deleteProduct(string $merchantId, string $productId): bool
    {
        $response = $this->apiCall("{$merchantId}/products/{$productId}", method: 'DELETE');
        return $response !== null;
    }

    /**
     * Get feed diagnostics.
     */
    public function getFeedDiagnostics(string $merchantId): array
    {
        $response = $this->apiCall("{$merchantId}/accountstatuses/{$merchantId}");
        if (!$response) return [];

        return [
            'account_level_issues' => $response['accountLevelIssues'] ?? [],
            'products' => $response['products'] ?? [],
        ];
    }

    /**
     * Sync products from Merchant Center to our database.
     */
    public function syncToDatabase(ProductFeed $feed): int
    {
        $merchantId = $feed->merchant_id;
        if (!$merchantId) return 0;

        $products = $this->listProducts($merchantId);
        $statuses = $this->getProductStatuses($merchantId);

        $synced = 0;
        foreach ($products as $product) {
            $statusInfo = $statuses[$product['offer_id']] ?? ['status' => 'pending', 'disapproval_reasons' => null];

            Product::updateOrCreate(
                ['product_feed_id' => $feed->id, 'offer_id' => $product['offer_id']],
                array_merge($product, [
                    'customer_id' => $feed->customer_id,
                    'status' => $statusInfo['status'],
                    'disapproval_reasons' => $statusInfo['disapproval_reasons'],
                ])
            );
            $synced++;
        }

        // Update feed stats
        $feed->update([
            'total_products' => Product::where('product_feed_id', $feed->id)->count(),
            'approved_products' => Product::where('product_feed_id', $feed->id)->where('status', 'approved')->count(),
            'disapproved_products' => Product::where('product_feed_id', $feed->id)->where('status', 'disapproved')->count(),
            'last_synced_at' => now(),
            'feed_diagnostics' => $this->getFeedDiagnostics($merchantId),
        ]);

        return $synced;
    }

    protected function apiCall(string $path, ?array $params = null, ?array $body = null, string $method = 'GET'): ?array
    {
        try {
            // Get OAuth token from BaseGoogleAdsService pattern
            $accessToken = $this->getAccessToken();
            if (!$accessToken) return null;

            $url = "{$this->baseUrl}/{$path}";

            $request = Http::withToken($accessToken)->timeout(30);

            $response = match ($method) {
                'POST' => $request->post($url, $body),
                'DELETE' => $request->delete($url),
                default => $request->get($url, $params ?? []),
            };

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Merchant Center API error', ['path' => $path, 'status' => $response->status()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Merchant Center API exception', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function getAccessToken(): ?string
    {
        // Reuse Google Ads OAuth token since Merchant Center uses the same credentials
        try {
            $base = new class($this->customer) extends BaseGoogleAdsService {
                public function getToken(): ?string
                {
                    return $this->client?->getOAuth2Credential()?->fetchAuthToken()['access_token'] ?? null;
                }
            };
            return $base->getToken();
        } catch (\Exception $e) {
            Log::debug('Could not get Merchant Center access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function normalizeProduct(array $raw): array
    {
        return [
            'offer_id' => $raw['offerId'] ?? $raw['id'] ?? '',
            'title' => $raw['title'] ?? '',
            'description' => $raw['description'] ?? null,
            'link' => $raw['link'] ?? null,
            'image_link' => $raw['imageLink'] ?? null,
            'price' => isset($raw['price']['value']) ? (float) $raw['price']['value'] : null,
            'sale_price' => isset($raw['salePrice']['value']) ? (float) $raw['salePrice']['value'] : null,
            'currency_code' => $raw['price']['currency'] ?? 'USD',
            'availability' => str_replace(' ', '_', strtolower($raw['availability'] ?? 'in stock')),
            'condition' => strtolower($raw['condition'] ?? 'new'),
            'brand' => $raw['brand'] ?? null,
            'gtin' => $raw['gtin'] ?? null,
            'mpn' => $raw['mpn'] ?? null,
            'google_product_category' => $raw['googleProductCategory'] ?? null,
            'product_type' => $raw['productTypes'][0] ?? null,
        ];
    }

    protected function determineStatus(array $status): string
    {
        $destinations = $status['destinationStatuses'] ?? [];
        foreach ($destinations as $dest) {
            if (($dest['destination'] ?? '') === 'SurfacesAcrossGoogle' || ($dest['destination'] ?? '') === 'Shopping') {
                if (($dest['status'] ?? '') === 'approved') return 'approved';
                if (($dest['status'] ?? '') === 'disapproved') return 'disapproved';
            }
        }
        $issues = $status['itemLevelIssues'] ?? [];
        if (!empty($issues)) return 'disapproved';
        return 'pending';
    }

    protected function extractDisapprovalReasons(array $status): ?array
    {
        $issues = $status['itemLevelIssues'] ?? [];
        if (empty($issues)) return null;

        return array_map(fn ($i) => [
            'code' => $i['code'] ?? '',
            'description' => $i['description'] ?? '',
            'detail' => $i['detail'] ?? '',
            'servability' => $i['servability'] ?? '',
        ], $issues);
    }
}
